<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingSubmissionService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

/**
 * What a reservation Novoton did not make looks like in the store: a
 * refusal, an answer without a reservation number and an answer that cannot
 * be read each mark that room group's own row failed, with what to check,
 * and never touch another group's row or stop the groups after it.
 */
#[CoversClass(BookingSubmissionService::class)]
final class BookingSubmissionServiceFailureTest extends TestCase
{
    /** @var array<int, array<string, mixed>> booking id => what was last written */
    private array $rows = [];

    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    /**
     * Three rooms of three packages: three hotel_res_RQ, rows 12 (the cart
     * booking), 13 and 14. Each answer is the reply's XML, or what the call
     * throws.
     *
     * @param list<string|\Throwable> $answers
     * @return list<string> the OrderNum of each request sent
     */
    private function submit(array $answers): array
    {
        $packages = ['ADMIRAL A 2027', 'ADMIRAL B 2027', 'ADMIRAL C 2027'];
        $rooms = [];
        $guests = [];
        foreach ($packages as $i => $package) {
            $n = $i + 1;
            $rooms[] = ['room_id' => 'DBL 2+0', 'board_id' => 'AI', 'package_name' => $package,
                'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 1000.0];
            $guests["room{$n}_adult_1"] = ['api_name' => "Guest {$n}A", 'age' => 40];
            $guests["room{$n}_adult_2"] = ['api_name' => "Guest {$n}B", 'age' => 40];
        }

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('claimForOrder')->willReturn(true);
        $repo->method('findById')->willReturn(['booking_id' => 12, 'order_id' => 0]);
        $repo->method('findByIdHydrated')->willReturn([
            'booking_id' => 12, 'hotel_id' => '476', 'hotel_name' => 'Admiral', 'package_name' => $packages[0],
            'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'nights' => 7, 'total_price' => 3000.0,
            'rooms_data' => $rooms, 'guests_data' => $guests,
        ]);
        $repo->method('update')->willReturnCallback(function (int $id, array $data): bool {
            $this->rows[$id] = $data + ($this->rows[$id] ?? []);

            return true;
        });
        $next = 13;
        $repo->method('create')->willReturnCallback(static function () use (&$next): int {
            return $next++;
        });

        $sent = [];
        $reservations = $this->createMock(ReservationApiClientInterface::class);
        $reservations->method('createReservation')->willReturnCallback(static function (array $data) use (&$sent, $answers): \SimpleXMLElement {
            $sent[] = (string) $data['order_num'];
            $answer = $answers[count($sent) - 1];
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new \SimpleXMLElement($answer);
        });

        (new BookingSubmissionService($repo, $reservations))->submitOrder(55, ['products' => [
            'c1' => ['product_id' => 7, 'price' => 3000.0, 'extra' => ['novoton_booking' => true, 'novoton_booking_id' => 12]],
        ]]);

        return $sent;
    }

    private const string GOOD = '<hotel_res_RS><IdNum>31191</IdNum><Status>Good</Status></hotel_res_RS>';

    public function testAnUnreadableAnswerFailsOnlyItsGroupAndTheNextGroupIsStillSent(): void
    {
        $sent = $this->submit([self::GOOD, new XmlParsingException('Start tag expected'), self::GOOD]);

        self::assertSame(['55-G1', '55-G2', '55-G3'], $sent, 'the third group is still sent');
        self::assertSame('confirmed', $this->rows[12]['status'], 'the first group stays confirmed');
        self::assertSame('31191', $this->rows[12]['novoton_invoice_id']);
        self::assertSame('failed', $this->rows[13]['status']);
        self::assertStringContainsString('may exist: check OrderNum 55-G2', (string) $this->rows[13]['notes']);
        self::assertSame('confirmed', $this->rows[14]['status']);
    }

    public function testATimedOutReservationIsNotSentAgainAndSaysItMayExist(): void
    {
        $this->submit([ApiException::maybeProcessed('hotel_res_RQ', 'Operation timed out after 120000 milliseconds', 0, 1), self::GOOD, self::GOOD]);

        self::assertSame('failed', $this->rows[12]['status']);
        self::assertStringContainsString('may exist: check OrderNum 55-G1 with Novoton before booking again', (string) $this->rows[12]['notes']);
    }

    public function testARefusalFailsItsGroupWithNovotonsReason(): void
    {
        $this->submit([ApiException::refused('hotel_res_RQ', 'Invalid username or password!'), self::GOOD, self::GOOD]);

        self::assertSame('failed', $this->rows[12]['status']);
        self::assertStringContainsString('Invalid username or password!', (string) $this->rows[12]['notes']);
        self::assertStringNotContainsString('may exist', (string) $this->rows[12]['notes'], 'a refusal booked nothing');
        self::assertSame(['confirmed', 'confirmed'], [$this->rows[13]['status'], $this->rows[14]['status']]);
    }

    public function testAnAnswerWithoutAReservationNumberIsNeverASilentPending(): void
    {
        $this->submit(['<hotel_res_RS><IdNum></IdNum><Status></Status></hotel_res_RS>', self::GOOD, self::GOOD]);

        self::assertSame('failed', $this->rows[12]['status']);
        self::assertStringContainsString('without a reservation number (Status: none). Check OrderNum 55-G1', (string) $this->rows[12]['notes']);
    }
}
