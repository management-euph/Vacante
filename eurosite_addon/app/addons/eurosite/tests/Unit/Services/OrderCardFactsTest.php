<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\Eurosite\Services\OrderCardFacts;

/**
 * What the order booking card learns from the eurosite booking row: the
 * supplier reference apart from our own, a booking that never reached
 * Eurosite, why one failed, and the confirmed fee schedule and agreed
 * payment terms as the shared timeline's input.
 */
#[CoversClass(OrderCardFacts::class)]
final class OrderCardFactsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 41,
            'order_id' => 12,
            'client_ref' => 'ES1A2B3C4D5E',
            'api_ref' => '778899',
            'status' => 'confirmed',
            'api_response' => '<AddBookingResponse/>',
            'cancellation_fees_json' => '',
        ];
    }

    public function testTheSupplierReferenceIsKeptApartFromOurs(): void
    {
        $facts = OrderCardFacts::fromRow(41, self::row());

        self::assertSame('41', $facts['provider_booking_id']);
        self::assertSame('778899', $facts['reference']);
        self::assertSame('ES1A2B3C4D5E', $facts['our_reference']);
        self::assertSame('confirmed', $facts['status']);
        self::assertFalse($facts['not_sent']);
        self::assertArrayNotHasKey('error', $facts);
    }

    public function testAPlacedBookingWithoutSupplierReferenceWasNeverSent(): void
    {
        // REGRESSION (review): our own client_ref was shown as "Eurosite
        // booking: ES… (Pending)", which reads as a booking Eurosite holds.
        $notSent = OrderCardFacts::fromRow(41, self::row(['api_ref' => null, 'status' => 'pending']));
        $inCart = OrderCardFacts::fromRow(41, self::row(['api_ref' => '', 'status' => 'pending', 'order_id' => 0]));
        $onRequest = OrderCardFacts::fromRow(41, self::row(['status' => 'pending']));

        self::assertSame('', $notSent['reference']);
        self::assertTrue($notSent['not_sent']);
        self::assertFalse($inCart['not_sent'], 'not an order yet');
        self::assertFalse($onRequest['not_sent'], 'Eurosite holds it, on request');
    }

    public function testAFailureReadsAsTheSuppliersAnswer(): void
    {
        $exception = OrderCardFacts::fromRow(41, self::row(['status' => 'failed', 'api_ref' => '', 'api_response' => 'EXCEPTION: cURL error 28: timed out']));
        $dropped = OrderCardFacts::fromRow(41, self::row(['status' => 'failed', 'api_response' => 'CART_DROPPED: product 77']));
        $xml = OrderCardFacts::fromRow(41, self::row([
            'status' => 'failed',
            'api_response' => '<AddBookingResponse><Error><ErrorId>-12</ErrorId><ErrorText>Room no longer available</ErrorText></Error></AddBookingResponse>',
        ]));

        self::assertSame('cURL error 28: timed out', $exception['error']);
        self::assertSame('product 77', $dropped['error']);
        self::assertSame('-12: Room no longer available', $xml['error']);
    }

    public function testALongAnswerIsCut(): void
    {
        $error = OrderCardFacts::error('EXCEPTION: ' . str_repeat('x', 400));

        self::assertSame(300, mb_strlen($error));
        self::assertStringEndsWith('…', $error);
    }

    public function testNoBookingRowStillNamesTheBooking(): void
    {
        self::assertSame(['provider_booking_id' => '41'], OrderCardFacts::fromRow(41, null));
    }

    public function testTheFeeScheduleIsOneWindowPerPeriodInThePrimaryCurrency(): void
    {
        $fees = (string) json_encode(['items' => [
            ['fees' => [
                ['from_date' => '2026-10-27', 'to_date' => '2026-11-06', 'price' => 100.0, 'currency' => 'EUR'],
                ['from_date' => '2026-11-07', 'to_date' => '2026-11-12', 'price' => 400.5, 'currency' => 'EUR'],
            ]],
            ['fees' => [
                ['from_date' => '2026-10-27', 'to_date' => '2026-11-06', 'price' => 50.0, 'currency' => 'EUR'],
            ]],
        ]]);

        $windows = OrderCardFacts::windows($fees, static fn (string $currency): float => $currency === 'EUR' ? 5.0 : 0.0);

        self::assertSame([
            ['from' => '2026-10-27', 'to' => '2026-11-06', 'amount' => 750.0],
            ['from' => '2026-11-07', 'to' => '2026-11-12', 'amount' => 2002.5],
        ], $windows);
        self::assertSame([], OrderCardFacts::windows('', static fn (string $c): float => 1.0));
        self::assertSame([], OrderCardFacts::windows('{oops', static fn (string $c): float => 1.0));
    }

    public function testTermsAreTheStoredPaymentLinesAndTheConfirmedFees(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            public function findById(int $bookingId): ?array
            {
                return $bookingId === 41 ? ['booking_id' => 41, 'cancellation_fees_json' => ''] : null;
            }
        };
        $facts = new OrderCardFacts($repo);

        $withLines = $facts->terms(['eurosite_booking_id' => 41, 'payment_terms' => ['30% at booking', ' ', '70% before check-in']]);
        $noTerms = $facts->terms(['eurosite_booking_id' => 41]);
        $notOurs = $facts->terms(['novoton_booking' => true]);

        self::assertSame(['payment_lines' => ['30% at booking', '70% before check-in']], $withLines);
        self::assertSame([], $noTerms);
        self::assertSame([], $notOurs);
    }

    public function testOnlyEurositeLinesAreClaimed(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            public function findById(int $bookingId): ?array
            {
                return null;
            }
        };

        self::assertSame([], (new OrderCardFacts($repo))->facts(['sphinx_booking' => true]));
        self::assertSame(['provider_booking_id' => '41'], (new OrderCardFacts($repo))->facts(['eurosite_booking_id' => 41]));
    }
}
