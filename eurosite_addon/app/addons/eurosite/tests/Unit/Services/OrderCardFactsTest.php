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

    public function testTheFeeScheduleIsOneWindowPerPeriodAsAShareOfTheBooking(): void
    {
        // REGRESSION: the fees were converted at today's rate while the line
        // price was converted the day it was booked, so a 100% fee read as
        // "cancelling now costs 99.9%" and changed every day. In the
        // booking's own currency they are a share of its total instead.
        $fees = (string) json_encode(['items' => [
            ['fees' => [
                ['from_date' => '2026-10-27', 'to_date' => '2026-11-06', 'price' => 100.0, 'currency' => 'EUR'],
                ['from_date' => '2026-11-07', 'to_date' => '2026-11-12', 'price' => 1246.5, 'currency' => 'EUR'],
            ]],
            ['fees' => [
                ['from_date' => '2026-10-27', 'to_date' => '2026-11-06', 'price' => 50.0, 'currency' => 'EUR'],
                ['from_date' => '2026-11-07', 'to_date' => '2026-11-12', 'price' => 150.0, 'currency' => 'EUR'],
            ]],
        ]]);
        $rates = static fn (string $currency): float => throw new \LogicException('no conversion for a share');

        self::assertSame([
            ['from' => '2026-10-27', 'to' => '2026-11-06', 'percent' => 10.0],
            ['from' => '2026-11-07', 'to' => '2026-11-12', 'percent' => 93.1],
        ], OrderCardFacts::windows($fees, 1500.0, 'EUR', $rates));
        self::assertSame([], OrderCardFacts::windows('', 1500.0, 'EUR', $rates));
        self::assertSame([], OrderCardFacts::windows('{oops', 1500.0, 'EUR', $rates));
    }

    public function testFeesInAnotherCurrencyOrWithoutATotalStayAmountsInThePrimaryCurrency(): void
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
        $asked = [];
        $rates = static function (string $currency) use (&$asked): float {
            $asked[] = $currency;

            return $currency === 'EUR' ? 5.0 : 0.0;
        };
        $amounts = [
            ['from' => '2026-10-27', 'to' => '2026-11-06', 'amount' => 750.0],
            ['from' => '2026-11-07', 'to' => '2026-11-12', 'amount' => 2002.5],
        ];

        self::assertSame($amounts, OrderCardFacts::windows($fees, 0.0, 'EUR', $rates));
        self::assertSame($amounts, OrderCardFacts::windows($fees, 1500.0, 'USD', $rates));
        self::assertSame(['EUR', 'EUR', 'EUR', 'EUR', 'EUR', 'EUR'], $asked);
    }

    public function testTermsAreTheStoredPaymentLinesAndTheConfirmedFees(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            public function findById(int $bookingId): ?array
            {
                return $bookingId === 41 ? ['booking_id' => 41, 'cancellation_fees_json' => ''] : null;
            }
        };
        $facts = new OrderCardFacts($repo, static fn (): array => ['Setting: 100% at booking']);

        $withLines = $facts->terms(['eurosite_booking_id' => 41, 'payment_terms' => ['30% at booking', ' ', '70% before check-in']]);
        $emptyAtBooking = $facts->terms(['eurosite_booking_id' => 41, 'payment_terms' => []]);
        $notOurs = $facts->terms(['novoton_booking' => true]);

        self::assertSame(['payment_lines' => ['30% at booking', '70% before check-in']], $withLines);
        // The setting was empty when the line was booked: it stays so.
        self::assertSame([], $emptyAtBooking);
        self::assertSame([], $notOurs);
    }

    public function testALineFromBeforeTheSnapshotShowsTheSettingAsItsPagesDid(): void
    {
        // REGRESSION: lines booked before add_to_cart stored the terms lost
        // them on the order pages (the old decorator filled in the setting).
        $repo = new class () extends EurositeBookingRepository {
            public function findById(int $bookingId): ?array
            {
                return ['booking_id' => $bookingId, 'cancellation_fees_json' => ''];
            }
        };
        $facts = new OrderCardFacts($repo, static fn (): array => ['30% la rezervare', ' ', '70% cu 30 de zile înainte']);

        self::assertSame(
            ['payment_lines' => ['30% la rezervare', '70% cu 30 de zile înainte']],
            $facts->terms(['eurosite_booking_id' => 41]),
        );
    }

    public function testTheBookingsFeesAreReadAsAShareOfItsTotal(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            public function findById(int $bookingId): ?array
            {
                return [
                    'booking_id' => $bookingId,
                    'total_price' => '1396.50',
                    'currency' => 'eur',
                    'cancellation_fees_json' => (string) json_encode(['items' => [['fees' => [
                        ['from_date' => '2026-10-01', 'to_date' => '2026-11-06', 'price' => 1396.5, 'currency' => 'EUR'],
                    ]]]]),
                ];
            }
        };

        self::assertSame(
            [['from' => '2026-10-01', 'to' => '2026-11-06', 'percent' => 100.0]],
            (new OrderCardFacts($repo, static fn (): array => []))->terms(['eurosite_booking_id' => 41])['cancel_windows'] ?? null,
        );
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
