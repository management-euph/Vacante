<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CheckoutBookingBinder;
use Tygh\Addons\TravelCore\Services\DepositCartLine;

/**
 * Audit H2: a Sphinx cart line may carry a booking only when the row is this
 * visitor's pending booking for the line's offer and product, and the line
 * charges the row's price. Ownership follows the C1 rule (user_id only for a
 * real account, session_id only when non-empty) plus the ids this session's
 * cart bound before a login regenerated the session id.
 */
#[CoversClass(CheckoutBookingBinder::class)]
final class CheckoutBookingBinderTest extends TestCase
{
    /** @var list<string> currencies the conversion was asked for */
    private array $converted = [];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row = []): array
    {
        return $row + [
            'booking_id' => 9,
            'order_id' => 0,
            'user_id' => 0,
            'session_id' => 'sess-owner',
            'product_id' => 100,
            'offer_id' => 'offer-A',
            'status' => 'pending',
            'total_price' => 1500.0,
            'currency' => 'EUR',
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function line(array $extra = [], array $line = []): array
    {
        return $line + [
            'product_id' => 100,
            'amount' => 1,
            'price' => 1500.0,
            'stored_price' => 'Y',
            'extra' => $extra + ['sphinx_booking' => true, 'travel_booking_id' => 9, 'offer_id' => 'offer-A'],
        ];
    }

    /**
     * A hotel row's payment terms as the verify response sends them
     * (cumulative rules): 30% on booking (2026-10-08), the rest by 2027-01-10.
     * add_to_cart made a 0.3 / 2027-01-10 plan of them on 2026-10-08.
     */
    private const string TERMS_30_70 = '{"is_loaded":true,"rules":[{"until":"2026-10-08","value":450},{"until":"2027-01-10","value":1500}]}';

    /**
     * Three installments: 20% on 2026-10-08, 30% on 2026-11-15, 50% on
     * 2027-01-10 — the deposit is 0.2 until 2026-11-15 falls due, 0.5 after.
     */
    private const string TERMS_20_30_50 = '{"is_loaded":true,"rules":[{"until":"2026-10-08","value":300},{"until":"2026-11-15","value":750},{"until":"2027-01-10","value":1500}]}';

    /**
     * @param array<string, mixed>|null $row
     * @param list<int> $verifiedIds
     * @param (\Closure(int, int): (array<string, mixed>|null))|null $placed
     */
    private function binder(?array $row, int $userId = 0, string $sessionId = 'sess-owner', array $verifiedIds = [], bool $admin = false, bool $deposits = true, string $today = '2026-10-08', ?\Closure $placed = null): CheckoutBookingBinder
    {
        return new CheckoutBookingBinder(
            static fn (int $id): ?array => $row !== null && $id === $row['booking_id'] ? $row : null,
            function (float $amount, string $currency): float {
                $this->converted[] = $currency;

                return $currency === 'USD' ? round($amount * 0.9, 2) : $amount;
            },
            $userId,
            $sessionId,
            $verifiedIds,
            $admin,
            $deposits,
            $today,
            $placed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function depositLine(float $ratio, string $balanceDue = '2027-01-10'): array
    {
        return self::line([DepositCartLine::EXTRA_KEY => ['ratio' => $ratio, 'balance_due' => $balanceDue]]);
    }

    public function testTheVisitorsOwnPendingBookingBinds(): void
    {
        $row = self::row();
        $binder = $this->binder($row);

        self::assertSame($row, $binder->rowOf(self::line()));
        self::assertNull($binder->rejection(self::line(), $row));
    }

    /** @return iterable<string, array{array<string, mixed>, int, string, list<int>}> */
    public static function owners(): iterable
    {
        yield 'the account' => [['user_id' => 42, 'session_id' => 'old'], 42, 'other-sess', []];
        yield 'the session' => [['user_id' => 0, 'session_id' => 'sess-owner'], 0, 'sess-owner', []];
        yield 'bound before the login regenerated the session id' => [['user_id' => 0, 'session_id' => 'pre-login'], 42, 'post-login', [9]];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $verified
     */
    #[DataProvider('owners')]
    public function testOwnershipByAccountSessionOrBoundId(array $row, int $userId, string $sessionId, array $verified): void
    {
        $row = self::row($row);

        self::assertNull($this->binder($row, $userId, $sessionId, $verified)->rejection(self::line(), $row));
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function strangers(): iterable
    {
        yield 'another session' => [['user_id' => 0, 'session_id' => 'victim'], 0, 'attacker'];
        yield 'anonymous user_id 0 never matches a guest row' => [['user_id' => 0, 'session_id' => ''], 0, ''];
        yield 'another account' => [['user_id' => 7, 'session_id' => 'victim'], 42, 'attacker'];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('strangers')]
    public function testAnotherVisitorsBookingDoesNotBind(array $row, int $userId, string $sessionId): void
    {
        $row = self::row($row);

        self::assertSame(
            'booking is not owned by this visitor',
            $this->binder($row, $userId, $sessionId)->rejection(self::line(), $row),
        );
    }

    public function testAForgedBookingIdBindsNothing(): void
    {
        $binder = $this->binder(self::row());
        $forged = self::line(['travel_booking_id' => 999999]);

        self::assertNull($binder->rowOf($forged));
        self::assertSame('no such booking', $binder->rejection($forged, $binder->rowOf($forged)));
        self::assertSame('no booking id', $binder->rejection(self::line(['travel_booking_id' => 0]), null));
        self::assertSame('no such booking', $binder->rejection(self::line(), self::row(['booking_id' => 8])), 'a row of another id');
    }

    public function testTheLineMustBeForTheRowsOfferAndProduct(): void
    {
        $row = self::row();
        $binder = $this->binder($row);

        self::assertSame('booking is for another offer', $binder->rejection(self::line(['offer_id' => 'offer-B']), $row));
        self::assertSame('booking is for another offer', $binder->rejection(self::line(), self::row(['offer_id' => ''])));
        self::assertSame('booking belongs to another product', $binder->rejection(self::line([], ['product_id' => 5]), $row));
    }

    public function testOnlyAPendingRowWithoutAnOrderOrOnTheCartsOwnOrderBinds(): void
    {
        $binder = $this->binder(null);

        self::assertSame('booking is not pending', $binder->rejection(self::line(), self::row(['status' => 'confirmed'])));
        self::assertSame('booking is attached to another order', $binder->rejection(self::line(), self::row(['order_id' => 77])));

        // A failed payment sends the customer back with the same cart: the
        // row is on that cart's own order, booked live there.
        self::assertNull($binder->rejection(self::line(), self::row(['order_id' => 77, 'status' => 'confirmed', 'session_id' => 'x']), [77]));
        self::assertSame(
            'booking is attached to another order',
            $binder->rejection(self::line(), self::row(['order_id' => 77, 'status' => 'failed']), [77]),
            'nothing live to pay for',
        );
    }

    public function testTheExpectedChargeIsTheRowPriceInThePrimaryCurrency(): void
    {
        $binder = $this->binder(null);

        self::assertSame(1350.0, $binder->expectedCharge(self::line(), self::row(['currency' => 'USD'])));
        self::assertSame(['USD'], $this->converted, "the row's currency, not the line's");
        self::assertNull($binder->expectedCharge(self::line(), self::row(['total_price' => 0])), 'no price is never a free pass');
        self::assertNull($binder->expectedCharge(self::line(), self::row(['total_price' => -5])));
    }

    public function testADepositLineMustChargeTheDepositOfTheRowPrice(): void
    {
        $line = self::depositLine(0.3);

        self::assertSame(450.0, $this->binder(null)->expectedCharge($line, self::row()));
    }

    public function testTheDepositPlanTheRowsTermsGiveBinds(): void
    {
        $row = self::row(['payment_terms_json' => self::TERMS_30_70, 'room_type' => 'Double Room']);

        self::assertNull($this->binder($row)->rejection(self::depositLine(0.3), $row));
        self::assertNull($this->binder($row)->rejection(self::line(), $row), 'paying in full needs no plan');
    }

    /**
     * Audit H2 (review): the deposit ratio is read from extra.travel_deposit,
     * which the request can write. A bound EUR 1,500 booking with a forged
     * 1% plan used to pass charging EUR 15 — and the full offer was booked.
     */
    public function testAForgedDepositPlanDoesNotBind(): void
    {
        $row = self::row(['payment_terms_json' => self::TERMS_30_70, 'room_type' => 'Double Room']);
        $binder = $this->binder($row);
        $mismatch = "deposit does not match the booking's payment terms";

        self::assertSame($mismatch, $binder->rejection(self::depositLine(0.01, '2099-01-01'), $row));
        self::assertSame($mismatch, $binder->rejection(self::depositLine(0.01), $row), 'the ratio');
        self::assertSame($mismatch, $binder->rejection(self::depositLine(0.3, '2026-10-20'), $row), 'the balance date');
        self::assertSame($mismatch, $binder->rejection(self::depositLine(0.3), self::row()), 'a row without a payment schedule');
        self::assertSame(
            $mismatch,
            $binder->rejection(self::depositLine(0.01), self::row(['payment_terms_json' => self::TERMS_30_70, 'order_id' => 77, 'status' => 'confirmed']), [77]),
            'nor on a row of the cart\'s own order',
        );
    }

    public function testADepositOnlyWhereAddToCartOffersOne(): void
    {
        $hotel = self::row(['payment_terms_json' => self::TERMS_30_70, 'room_type' => 'Double Room']);

        // Deposits switched off after add-to-cart: the line is not refused,
        // it is to charge the full price (review of H2, round 2).
        $off = $this->binder($hotel, deposits: false);
        self::assertNull($off->rejection(self::depositLine(0.3), $hotel));
        $canonical = $off->canonicalLine(self::depositLine(0.3), $hotel);
        self::assertNotNull($canonical);
        self::assertSame(1500.0, $canonical['price'] ?? null);
        self::assertNull(DepositCartLine::plan($canonical), 'the plan is dropped');
        self::assertSame(1500.0, $off->expectedCharge(self::depositLine(0.3), $hotel));
        // A placed order's row with no placed item to vouch for the plan.
        self::assertSame(
            'deposits are not offered',
            $off->rejection(self::depositLine(0.3), ['order_id' => 77, 'status' => 'confirmed'] + $hotel, [77]),
        );
        foreach (['circuit', 'package'] as $type) {
            $row = ['room_type' => $type] + $hotel;
            self::assertSame(
                'deposit on a booking without a payment schedule',
                $this->binder($row)->rejection(self::depositLine(0.3), $row),
                $type,
            );
        }
    }

    /**
     * An installment falling due between add-to-cart and checkout changes
     * the ratio the terms give: the plan of the day the row was written
     * still binds, any other ratio does not.
     */
    public function testTheDepositPlanOfTheDayTheRowWasWrittenStillBinds(): void
    {
        $row = self::row([
            'payment_terms_json' => self::TERMS_20_30_50,
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-08 10:15:00',
        ]);

        // Added on 2026-10-08 (0.2), checked out on 2026-11-20 (0.5 today).
        self::assertNull($this->binder($row, today: '2026-11-20')->rejection(self::depositLine(0.2), $row));
        self::assertNull($this->binder($row, today: '2026-11-20')->rejection(self::depositLine(0.5), $row));
        self::assertSame(
            "deposit does not match the booking's payment terms",
            $this->binder($row, today: '2026-11-20')->rejection(self::depositLine(0.2), ['updated_at' => '2026-11-20 08:00:00', 'created_at' => '2026-11-20 08:00:00'] + $row),
        );
    }

    /**
     * Review of H2, round 2: the plan's saved amounts decide the balance
     * travel_core records as still owed (DepositCartLine::amounts). A line
     * with the genuine ratio and date, charging the genuine deposit, but
     * saving balance 0 (or another full price) bound and charged — and the
     * remaining 70% was never asked for.
     */
    public function testTheSavedDepositAmountsMustBeTheRowPrices(): void
    {
        $row = self::row(['payment_terms_json' => self::TERMS_30_70, 'room_type' => 'Double Room']);
        $binder = $this->binder($row);
        $saved = static fn (array $amounts): array => self::line(
            [DepositCartLine::EXTRA_KEY => $amounts + ['ratio' => 0.3, 'balance_due' => '2027-01-10', 'full' => 1500.0, 'deposit' => 450.0, 'balance' => 1050.0]],
            ['price' => 450.0],
        );

        $noBalance = $saved(['balance' => 0.0]);
        self::assertNull($binder->rejection($noBalance, $row));
        self::assertTrue(CheckoutBookingBinder::charges($noBalance, (float) $binder->expectedCharge($noBalance, $row)));
        $canonical = $binder->canonicalLine($noBalance, $row);
        self::assertNotNull($canonical);
        self::assertFalse(CheckoutBookingBinder::savesDepositOf($noBalance, $canonical), 'balance 0');
        self::assertSame(1050.0, DepositCartLine::amounts($canonical)['balance'] ?? null, 'the canonical line owes the rest');

        foreach ([['full' => 450.0], ['deposit' => 45.0], ['full' => 1500.02]] as $forged) {
            $line = $saved($forged);
            $canonical = $binder->canonicalLine($line, $row);
            self::assertNotNull($canonical);
            self::assertFalse(CheckoutBookingBinder::savesDepositOf($line, $canonical), (string) json_encode($forged));
        }

        // What add_to_cart writes (DepositCartLine::apply), and a full-price line.
        $genuine = $saved([]);
        $canonical = $binder->canonicalLine($genuine, $row);
        self::assertNotNull($canonical);
        self::assertTrue(CheckoutBookingBinder::savesDepositOf($genuine, $canonical));
        self::assertTrue(CheckoutBookingBinder::savesDepositOf($saved(['full' => 1500.004]), $canonical), 'within a cent');
        $canonical = $binder->canonicalLine(self::line(), $row);
        self::assertNotNull($canonical);
        self::assertTrue(CheckoutBookingBinder::savesDepositOf(self::line(), $canonical));
    }

    /**
     * Review of H2, round 2: back after a failed payment, the row is on the
     * cart's own order and the orders sync may have rewritten its terms
     * since. The plan the order's item was placed with is the one to keep.
     */
    public function testARetriedPaymentKeepsThePlanItsOrderWasPlacedWith(): void
    {
        // Terms rewritten to a schedule that gives no 0.3 plan any more.
        $row = self::row(['order_id' => 77, 'status' => 'confirmed', 'payment_terms_json' => self::TERMS_20_30_50, 'room_type' => 'Double Room']);
        $asked = [];
        $placed = static function (int $orderId, int $bookingId) use (&$asked): ?array {
            $asked[] = [$orderId, $bookingId];

            return $orderId === 77 && $bookingId === 9 ? self::depositLine(0.3) : null;
        };

        self::assertNull($this->binder($row, placed: $placed)->rejection(self::depositLine(0.3), $row, [77]));
        self::assertSame([[77, 9]], $asked);
        self::assertSame(
            'deposit does not match the placed order',
            $this->binder($row, placed: $placed)->rejection(self::depositLine(0.01), $row, [77]),
        );
        self::assertSame(
            'deposit does not match the placed order',
            $this->binder($row, placed: static fn (): array => self::line())->rejection(self::depositLine(0.3), $row, [77]),
            'placed paying in full',
        );

        // Deposits switched off since: the placed order keeps its plan.
        $off = $this->binder($row, deposits: false, placed: $placed);
        self::assertNull($off->rejection(self::depositLine(0.3), $row, [77]));
        self::assertSame(450.0, $off->expectedCharge(self::depositLine(0.3), $row));
    }

    public function testTheBookingTypeIsTheRowsRoomType(): void
    {
        self::assertSame('circuit', CheckoutBookingBinder::bookingTypeOf(['room_type' => 'circuit']));
        self::assertSame('package', CheckoutBookingBinder::bookingTypeOf(['room_type' => 'package']));
        self::assertSame('experience', CheckoutBookingBinder::bookingTypeOf(['room_type' => 'experience']));
        self::assertSame('hotel', CheckoutBookingBinder::bookingTypeOf(['room_type' => 'Double Room']));
        self::assertSame('hotel', CheckoutBookingBinder::bookingTypeOf([]));
    }

    public function testTheLineChargesPriceTimesAmountWithinACent(): void
    {
        self::assertTrue(CheckoutBookingBinder::charges(self::line([], ['price' => 1499.995]), 1500.0));
        self::assertFalse(CheckoutBookingBinder::charges(self::line([], ['price' => 1499.98]), 1500.0));
        self::assertFalse(CheckoutBookingBinder::charges(self::line([], ['price' => 45.0]), 1500.0));
        self::assertFalse(CheckoutBookingBinder::charges(self::line([], ['amount' => 2]), 1500.0));
        self::assertTrue(CheckoutBookingBinder::charges(self::line([], ['amount' => 2, 'price' => 750.0]), 1500.0));
    }

    public function testOnlyTheAdminEditsAPlacedOrder(): void
    {
        $cart = ['order_id' => 55, 'products' => []];

        self::assertTrue($this->binder(null, admin: true)->editsPlacedOrder($cart));
        self::assertFalse($this->binder(null)->editsPlacedOrder($cart), 'a storefront cart is never exempt');
        self::assertFalse($this->binder(null, admin: true)->editsPlacedOrder(['products' => []]));
    }

    public function testOwnOrderIdsReadProcessedAndFailedOrders(): void
    {
        self::assertSame([12, 13, 14], CheckoutBookingBinder::ownOrderIds([
            'processed_order_id' => [12, 13],
            'failed_order_id' => 14,
        ]));
        self::assertSame([], CheckoutBookingBinder::ownOrderIds([]));
    }

    public function testBoundBookingIdsListTheLinesThatBind(): void
    {
        $row = self::row();
        $cart = ['products' => [
            11 => self::line(),
            12 => self::line(['travel_booking_id' => 404]),
            13 => ['product_id' => 3, 'extra' => []],
        ]];

        self::assertSame([9], $this->binder($row)->boundBookingIds($cart));
        self::assertSame([], $this->binder($row, 0, 'stranger')->boundBookingIds($cart));
    }
}
