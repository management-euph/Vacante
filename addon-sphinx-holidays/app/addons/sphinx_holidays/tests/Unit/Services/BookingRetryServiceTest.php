<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Services\BookingRetryService;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Regression guard for the retry submission path: it must submit the SAME
 * factory-built payload as order placement. The previous implementation
 * called occupancy-builder functions that were never defined anywhere, so
 * every retry threw "Call to undefined function", landed in the catch block,
 * and re-marked the booking failed — retries could never succeed.
 */
#[CoversClass(BookingRetryService::class)]
final class BookingRetryServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function failedBookingRow(): array
    {
        return [
            'booking_id' => 7,
            'status' => TravelConstants::STATUS_FAILED,
            'offer_id' => 'OFF-77',
            'room_type' => 'DBL Superior',   // hotel booking (not circuit/package/experience)
            'order_id' => 85,
            'guest_email' => 'guest@example.ro',
            'guest_phone' => '+40737000000',
            'guests_data' => json_encode([
                'room1_adult_1' => ['name' => 'Pop, Ana', 'type' => 'adult', 'is_holder' => 1],
            ]),
            'total_price' => 725.0,
            'currency' => 'EUR',
        ];
    }

    public function testRetrySubmitsFactoryPayloadAndConfirms(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);

        $repo->method('findById')->with(7)->willReturn($this->failedBookingRow());
        // Sphinx holds no booking for order 85 yet.
        $api->method('getOrders')->with(1, 10, ['reference_code' => '85'])->willReturn(['results' => []]);
        $repo->method('findByOrderId')->with(85)->willReturn([$this->failedBookingRow()]);

        // Verify step: decoded payload with offer signals → available.
        $api->method('verifyHotelOffer')->with('OFF-77')->willReturn([
            'available' => true,
            'selling_price' => 725.0,
        ]);

        $api->expects(self::once())
            ->method('bookHotel')
            ->with([
                'offer_id' => 'OFF-77',
                'guests' => [
                    'room1_adult_1' => ['name' => 'Pop, Ana', 'type' => 'adult', 'is_holder' => 1],
                ],
                'contact' => ['email' => 'guest@example.ro', 'phone' => '+40737000000'],
                'reference_code' => '85',
            ])
            // Documented book response shape (tests/Fixtures/api/hotels_book.json):
            // the voucher code is booking_confirmation_number, NOT booking_reference.
            ->willReturn([
                'order_id' => 123456,
                'contract_id' => 112233,
                'booking_confirmation_number' => 'SPX-REF-1',
                'reference_code' => '85',
                'status' => 'confirmed',
            ]);

        $repo->expects(self::once())
            ->method('updateApiResponse')
            ->with(7, 'SPX-REF-1', self::isString());

        // pending (before submit) then confirmed (after success)
        $statusUpdates = [];
        $repo->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$statusUpdates): bool {
                if (isset($data['status'])) {
                    $statusUpdates[] = $data['status'];
                }
                return true;
            });

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertTrue($result['success']);
        self::assertSame('SPX-REF-1', $result['booking_ref']);
        self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], $statusUpdates);
    }

    public function testRetryRefusesNonFailedBookings(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);

        $row = $this->failedBookingRow();
        $row['status'] = TravelConstants::STATUS_CONFIRMED;
        $repo->method('findById')->willReturn($row);
        $api->expects(self::never())->method('bookHotel');

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertFalse($result['success']);
    }

    public function testUnavailableOfferAbortsBeforeSubmission(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);

        $repo->method('findById')->willReturn($this->failedBookingRow());
        // Explicitly unavailable offer payload
        $api->method('verifyHotelOffer')->willReturn(['available' => false]);
        $api->expects(self::never())->method('bookHotel');

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertFalse($result['success']);
        self::assertSame('Offer no longer available', $result['message']);
    }

    /**
     * @param array<string, mixed>|null $answer what bookHotel() answers
     * @param list<string> $statuses the statuses written, in order (filled)
     */
    private function retryWith(?array $answer, string $error, bool $maybeProcessed, array &$statuses, ?array $atSphinx = ['results' => []]): array
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);
        $repo->method('findById')->willReturn($this->failedBookingRow());
        $repo->method('findByOrderId')->willReturn([$this->failedBookingRow()]);
        $api->method('verifyHotelOffer')->willReturn(['available' => true, 'selling_price' => 725.0]);
        $api->method('getOrders')->willReturn($atSphinx);
        $api->method('bookHotel')->willReturn($answer);
        $api->method('lastError')->willReturn($error);
        $api->method('lastMaybeProcessed')->willReturn($maybeProcessed);
        $repo->method('update')->willReturnCallback(static function (int $id, array $data) use (&$statuses): bool {
            if (isset($data['status'])) {
                $statuses[] = $data['status'];
            }

            return true;
        });

        return (new BookingRetryService($api, $repo))->retry(7);
    }

    /** A refused retry used to report success and mark the booking confirmed. */
    public function testARefusedRetryStaysFailed(): void
    {
        $statuses = [];
        $result = $this->retryWith(null, 'Client error HTTP 422: {"message":"Offer expired"}', false, $statuses);

        self::assertFalse($result['success']);
        self::assertStringContainsString('Offer expired', $result['message']);
        self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_FAILED], $statuses);
    }

    public function testARetryWithoutAClearAnswerStaysPending(): void
    {
        $statuses = [];
        $result = $this->retryWith(null, 'Server error HTTP 504 (not resent: the request may have reached Sphinx)', true, $statuses);

        self::assertFalse($result['success']);
        self::assertStringContainsString('check it in Sphinx', $result['message']);
        self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_PENDING], $statuses);
    }

    /** A failed row may still have been booked (an answer lost to a timeout): it is not booked twice. */
    public function testABookingSphinxAlreadyHoldsIsNotResent(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);
        $repo->method('findById')->willReturn($this->failedBookingRow());
        $repo->method('findByOrderId')->willReturn([$this->failedBookingRow()]);
        $api->method('verifyHotelOffer')->willReturn(['available' => true, 'selling_price' => 725.0]);
        $api->method('getOrders')->willReturn(['results' => [['id' => 9, 'reference_code' => '85', 'bookings' => [['status' => 'confirmed']]]]]);
        $api->expects(self::never())->method('bookHotel');
        $repo->expects(self::once())->method('update')->with(7, ['status' => TravelConstants::STATUS_PENDING]);

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertFalse($result['success']);
        self::assertStringContainsString('already has a booking', $result['message']);
    }

    public function testNoRetryWhenSphinxCannotBeAsked(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);
        $repo->method('findById')->willReturn($this->failedBookingRow());
        $api->method('verifyHotelOffer')->willReturn(['available' => true, 'selling_price' => 725.0]);
        $api->method('getOrders')->willReturn(null);
        $api->expects(self::never())->method('bookHotel');
        $repo->expects(self::never())->method('update');

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertFalse($result['success']);
        self::assertStringContainsString('not resent', $result['message']);
    }

    /** The first attempt's errored booking at Sphinx is no booking: it does not block the retry. */
    public function testAnErroredBookingAtSphinxDoesNotBlockTheRetry(): void
    {
        $statuses = [];
        $result = $this->retryWith(
            ['booking_confirmation_number' => 'SPX-3', 'status' => 'confirmed'],
            '',
            false,
            $statuses,
            ['results' => [['bookings' => [['status' => 'errored']]]]],
        );

        self::assertTrue($result['success']);
        self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], $statuses);
    }

    /** An order's second booking is retried when Sphinx holds only the first. */
    public function testAnotherBookingOfTheOrderDoesNotBlockTheRetry(): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);
        $other = ['booking_id' => 6, 'status' => TravelConstants::STATUS_CONFIRMED, 'order_id' => 85];
        $repo->method('findById')->willReturn($this->failedBookingRow());
        $repo->method('findByOrderId')->willReturn([$other, $this->failedBookingRow()]);
        $api->method('verifyHotelOffer')->willReturn(['available' => true, 'selling_price' => 725.0]);
        $api->method('getOrders')->willReturn(['results' => [['bookings' => [['status' => 'confirmed']]]]]);
        $api->expects(self::once())->method('bookHotel')->willReturn(['booking_confirmation_number' => 'SPX-2', 'status' => 'confirmed']);
        $repo->method('update')->willReturn(true);

        $result = (new BookingRetryService($api, $repo))->retry(7);

        self::assertTrue($result['success']);
        self::assertSame('SPX-2', $result['booking_ref']);
    }
}
