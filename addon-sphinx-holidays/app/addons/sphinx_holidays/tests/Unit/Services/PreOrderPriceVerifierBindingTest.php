<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CheckoutBookingBinder;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\PreOrderPriceVerifier;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Registry;

/**
 * Audit H2: the checkout check trusted extra.sphinx_booking and compared
 * only extra.total_price with the API — never the price the line charges.
 * A cheap product carrying an expensive offer's extras (and any
 * travel_booking_id) was charged its catalogue price while the expensive
 * offer was booked; total_price <= 0, a circuit without a departure, a
 * throwing provider check or an API outage skipped the line entirely.
 *
 * Now every line is bound to its booking row first (no API needed), the
 * line must charge the row's price, and the provider re-check compares the
 * row's price.
 */
#[CoversClass(PreOrderPriceVerifier::class)]
final class PreOrderPriceVerifierBindingTest extends TestCase
{
    /** A 30/70 plan as add_to_cart saves it on a EUR 1,500 line (DepositCartLine::apply). */
    private const array PLAN_30 = ['ratio' => 0.3, 'balance_due' => '2027-01-10', 'full' => 1500.0, 'deposit' => 450.0, 'balance' => 1050.0];

    /** @var list<string> provider calls made */
    private static array $calls = [];

    /** @var array<string, mixed> */
    private static array $answers = [];

    private static bool $apiThrows = false;

    /** @var array<int, array<string, mixed>> booking_id => row */
    private array $rows = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        self::$calls = [];
        self::$answers = [];
        self::$apiThrows = false;
        Registry::set('addons.sphinx_holidays', null);
        ConfigProvider::resetSettingsCache();
        Container::reset();
        Container::override('api', static fn (): SphinxApi => new class extends SphinxApi {
            public function __construct()
            {
            }

            public function verifyHotelOffer(string $offerId): ?array
            {
                return PreOrderPriceVerifierBindingTest::answer('verifyHotelOffer:' . $offerId);
            }

            public function getCircuitQuote(array $params): array
            {
                return PreOrderPriceVerifierBindingTest::answer('getCircuitQuote') ?? [];
            }
        });
        $this->rows = [
            9 => self::row(9, 'offer-cheap', 45.0),
            10 => self::row(10, 'offer-luxury', 1500.0),
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Container::reset();
        Registry::set('addons.sphinx_holidays', null);
        ConfigProvider::resetSettingsCache();
    }

    /** @return array<array-key, mixed>|null */
    public static function answer(string $call): ?array
    {
        self::$calls[] = $call;
        if (self::$apiThrows) {
            throw new \RuntimeException('provider timeout');
        }
        $answer = self::$answers[$call] ?? null;

        return is_array($answer) ? $answer : null;
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $offerId, float $total, int $userId = 5): array
    {
        return [
            'booking_id' => $id, 'order_id' => 0, 'user_id' => $userId, 'session_id' => '',
            'product_id' => 100 + $id, 'offer_id' => $offerId, 'status' => 'pending',
            'total_price' => $total, 'base_price' => $total, 'currency' => 'EUR',
            'hotel_id' => 'H' . $id, 'check_in' => '2026-12-01', 'adults' => 2, 'children_ages' => '',
            'room_type' => 'Double Room',
            // 30% on booking (2026-10-08), the rest by 2027-01-10.
            'payment_terms_json' => '{"is_loaded":true,"rules":[{"until":"2026-10-08","value":' . $total * 0.3
                . '},{"until":"2027-01-10","value":' . $total . '}]}',
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function line(int $bookingId, string $offerId, float $price, array $extra = [], array $line = []): array
    {
        return $line + [
            'product_id' => 100 + $bookingId,
            'amount' => 1,
            'price' => $price,
            'stored_price' => 'Y',
            'extra' => $extra + [
                'sphinx_booking' => 1,
                'travel_booking_id' => $bookingId,
                'offer_id' => $offerId,
                'total_price' => $price,
                'hotel_name' => 'Hotel ' . $offerId,
            ],
        ];
    }

    private function verifier(int $userId = 5, bool $admin = false, bool $deposits = true): PreOrderPriceVerifier
    {
        $rows = $this->rows;

        return new PreOrderPriceVerifier(null, new CheckoutBookingBinder(
            static fn (int $id): ?array => $rows[$id] ?? null,
            static fn (float $amount, string $currency): float => $amount,
            $userId,
            '',
            [],
            $admin,
            $deposits,
            '2026-10-08',
        ));
    }

    /** Fresh Silent Sync entries, so a bound line needs no provider call. */
    private static function cached(string ...$offerIds): void
    {
        foreach ($offerIds as $offerId) {
            $_SESSION['sphinx_price_cache'][md5($offerId)] = ['api_price_raw' => $offerId === 'offer-luxury' ? 1500.0 : 45.0, 'timestamp' => time()];
        }
    }

    /**
     * The exploit: a cheap catalogue product carrying the luxury offer's
     * extras and a booking id that names no row. It used to pass (the
     * cached price matched the forged extra.total_price) and place_order_post
     * booked the luxury offer.
     */
    public function testACheapLineCarryingAnotherOffersExtrasAndAForgedIdIsRejected(): void
    {
        self::cached('offer-luxury');
        $cart = ['products' => [
            7 => self::line(999, 'offer-luxury', 45.0, ['total_price' => 1500.0], ['product_id' => 3]),
        ]];

        $result = $this->verifier()->verify($cart);

        self::assertSame('no such booking', $result['rejected'][7]['reason'] ?? null);
        self::assertSame([], $result['corrections']);
        self::assertSame([], self::$calls, 'the binding needs no provider');
    }

    public function testALineNamingAnotherVisitorsBookingIsRejected(): void
    {
        $this->rows[10] = self::row(10, 'offer-luxury', 1500.0, 77);

        $result = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 1500.0)]]);

        self::assertSame('booking is not owned by this visitor', $result['rejected'][7]['reason'] ?? null);
    }

    public function testALineWhoseOfferIsNotItsBookingsIsRejected(): void
    {
        // Its own cheap booking row, with the luxury offer in extra.
        $result = $this->verifier()->verify(['products' => [7 => self::line(9, 'offer-luxury', 45.0)]]);

        self::assertSame('booking is for another offer', $result['rejected'][7]['reason'] ?? null);
    }

    /**
     * Its own luxury booking on a line charging the catalogue price: the
     * line is corrected to the row's price and the customer re-confirms.
     */
    public function testALineChargingLessThanItsBookingIsCorrectedToTheRowPrice(): void
    {
        self::cached('offer-luxury');
        $line = self::line(10, 'offer-luxury', 45.0, ['total_price' => 45.0, 'currency' => 'RON']);

        $result = $this->verifier()->verify(['products' => [7 => $line]]);

        self::assertSame([], $result['rejected']);
        self::assertSame(1500.0, $result['corrections'][7]['api_price'] ?? null);
        self::assertSame('EUR', $result['corrections'][7]['currency'] ?? null, "the row's currency, not the line's");
        self::assertSame(10, $result['corrections'][7]['booking_id'] ?? null);
        self::assertTrue($result['reconfirm']);
    }

    public function testTwoLinesForOneQuantityArePriceTimesAmount(): void
    {
        self::cached('offer-luxury');

        $result = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 1500.0, [], ['amount' => 2])]]);

        self::assertArrayHasKey(7, $result['corrections']);
        self::assertTrue($result['reconfirm']);
    }

    public function testADepositLineChargingItsDepositPasses(): void
    {
        self::cached('offer-luxury');
        $deposit = [DepositCartLine::EXTRA_KEY => self::PLAN_30];

        $ok = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 450.0, $deposit)]]);
        $short = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 45.0, $deposit)]]);

        self::assertSame([], $ok['corrections']);
        self::assertSame([], $ok['rejected']);
        self::assertFalse($ok['reconfirm']);
        self::assertArrayHasKey(7, $short['corrections'], 'a deposit line charging less than the deposit');
    }

    /**
     * Review of H2: the ratio came from extra.travel_deposit. Its own bound
     * EUR 1,500 booking, a forged 1% plan and a EUR 15 line passed, and
     * place_order_post booked the full offer.
     */
    public function testABoundLineWithAForgedDepositRatioDoesNotPass(): void
    {
        self::cached('offer-luxury');
        $forged = [DepositCartLine::EXTRA_KEY => ['ratio' => 0.01, 'balance_due' => '2099-01-01']];

        $result = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 15.0, $forged)]]);

        self::assertSame("deposit does not match the booking's payment terms", $result['rejected'][7]['reason'] ?? null);
        self::assertSame([], $result['corrections']);
    }

    /**
     * Deposits switched off while a deposit line sits in the cart: the line
     * is corrected to the full price (full_payment) and re-confirmed — no
     * longer removed as if its booking data were missing.
     */
    public function testADepositLineWhenDepositsAreOffIsCorrectedToTheFullPrice(): void
    {
        self::cached('offer-luxury');
        $deposit = [DepositCartLine::EXTRA_KEY => self::PLAN_30];

        $result = $this->verifier(deposits: false)->verify(['products' => [7 => self::line(10, 'offer-luxury', 450.0, $deposit)]]);

        self::assertSame([], $result['rejected']);
        self::assertSame(1500.0, $result['corrections'][7]['api_price'] ?? null);
        self::assertSame(1, $result['corrections'][7]['full_payment'] ?? null);
        self::assertSame(0, $result['corrections'][7]['update_row'] ?? null);
        self::assertTrue($result['reconfirm']);
    }

    /**
     * Review of H2, round 2: the genuine ratio and date, the genuine deposit
     * charged — but the saved balance 0, so travel_core recorded nothing
     * owed and the remaining 70% of the booked offer was never collected.
     */
    public function testADepositLineSavingAnotherBalanceIsCorrected(): void
    {
        self::cached('offer-luxury');

        foreach ([['balance' => 0.0], ['full' => 450.0, 'balance' => 0.0], ['deposit' => 4.5]] as $forged) {
            $line = self::line(10, 'offer-luxury', 450.0, [DepositCartLine::EXTRA_KEY => $forged + self::PLAN_30]);

            $result = $this->verifier()->verify(['products' => [7 => $line]]);

            self::assertSame([], $result['rejected'], (string) json_encode($forged));
            self::assertSame(1500.0, $result['corrections'][7]['api_price'] ?? null, (string) json_encode($forged));
            self::assertSame(0, $result['corrections'][7]['full_payment'] ?? null);
            self::assertSame(0, $result['corrections'][7]['update_row'] ?? null);
            self::assertTrue($result['reconfirm']);
        }
    }

    public function testTheProviderReCheckComparesTheRowPriceNotExtraTotalPrice(): void
    {
        self::cached('offer-luxury');
        // extra.total_price says 100 — the line charges and the row holds 1500.
        $result = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 1500.0, ['total_price' => 100.0])]]);

        self::assertSame([], $result['corrections']);
        self::assertSame([], $result['rejected']);
        self::assertFalse($result['reconfirm']);
    }

    public function testABookingWithoutAPriceIsRejectedNotSkipped(): void
    {
        $this->rows[10] = self::row(10, 'offer-luxury', 0.0);

        $result = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 0.0, ['total_price' => 0])]]);

        self::assertSame('booking has no price', $result['rejected'][7]['reason'] ?? null);
    }

    public function testALineChargedTheCataloguePriceIsRejected(): void
    {
        $result = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 1500.0, [], ['stored_price' => 'N'])]]);

        self::assertSame('line is charged the catalogue price', $result['rejected'][7]['reason'] ?? null);
    }

    public function testOneBookingCannotBeCarriedByTwoLines(): void
    {
        self::cached('offer-luxury');

        $result = $this->verifier()->verify(['products' => [
            7 => self::line(10, 'offer-luxury', 1500.0),
            8 => self::line(10, 'offer-luxury', 1500.0),
        ]]);

        self::assertArrayNotHasKey(7, $result['rejected']);
        self::assertSame('booking already held by another cart line', $result['rejected'][8]['reason'] ?? null);
    }

    public function testACircuitWithoutADepartureIsRejectedNotSkipped(): void
    {
        $this->rows[10] = ['check_in' => null, 'hotel_id' => null, 'room_type' => 'circuit'] + self::row(10, 'offer-luxury', 1500.0);

        // Whatever the line calls itself: relabelled a hotel, it used to
        // reach the hotel endpoint, throw there and be let through.
        foreach (['circuit', 'hotel'] as $label) {
            $line = self::line(10, 'offer-luxury', 1500.0, ['booking_type' => $label]);

            $result = $this->verifier()->verify(['products' => [7 => $line]]);

            self::assertSame('circuit booking has no circuit or departure date', $result['rejected'][7]['reason'] ?? null, $label);
        }
        self::assertSame([], self::$calls);
    }

    public function testTheProviderIsAskedForTheTypeTheRowWasBookedAs(): void
    {
        self::$answers['verifyHotelOffer:offer-luxury'] = ['available' => true, 'pricing' => ['selling_price' => 1500.0]];
        // A hotel booking whose line claims to be a circuit.
        $line = self::line(10, 'offer-luxury', 1500.0, ['booking_type' => 'circuit']);

        $this->verifier()->verify(['products' => [7 => $line]]);

        self::assertSame(['verifyHotelOffer:offer-luxury'], self::$calls);
    }

    /**
     * Back after a failed payment, the cart's row is on that order and booked
     * live. It needs no provider re-check: a stale "unavailable" used to have
     * the hook delete the confirmed booking's row.
     */
    public function testARowOnTheCartsOwnOrderGetsNoProviderReCheck(): void
    {
        $this->rows[10] = ['order_id' => 77, 'status' => 'confirmed'] + self::row(10, 'offer-luxury', 1500.0);
        // No cache entry, and the provider would call the offer gone.
        self::$answers['verifyHotelOffer:offer-luxury'] = ['available' => false];
        $cart = ['failed_order_id' => 77, 'products' => [7 => self::line(10, 'offer-luxury', 1500.0)]];

        $result = $this->verifier()->verify($cart);

        self::assertSame([], self::$calls);
        self::assertSame([], $result['unavailable']);
        self::assertSame([], $result['rejected']);
        self::assertSame([], $result['corrections']);

        // Still charged its booking's price: corrected, never written to the row.
        $short = $this->verifier()->verify(['products' => [7 => self::line(10, 'offer-luxury', 45.0)]] + $cart);
        self::assertSame(0, $short['corrections'][7]['update_row'] ?? null);
    }

    public function testACircuitIsReQuotedForTheBookedDepartureNotTheLinesCopy(): void
    {
        $this->rows[10] = ['hotel_id' => '31', 'room_type' => 'circuit'] + self::row(10, 'offer-luxury', 1500.0);
        self::$answers['getCircuitQuote'] = [['offer_id' => 'offer-luxury', 'pricing' => ['selling_price' => 1500.0]]];
        // The line lost its departure; the row has it.
        $line = self::line(10, 'offer-luxury', 1500.0, ['booking_type' => 'circuit', 'hotel_id' => '', 'check_in' => '']);

        $result = $this->verifier()->verify(['products' => [7 => $line]]);

        self::assertSame(['getCircuitQuote'], self::$calls);
        self::assertSame([], $result['rejected']);
        self::assertSame([], $result['corrections']);
    }

    /**
     * An API outage used to return at the first uncached line, so the
     * lines after it were never looked at. It skips only the re-check now.
     */
    public function testAnApiOutageStillBindsEveryLine(): void
    {
        Container::override('api', static function (): SphinxApi {
            throw new \RuntimeException('not configured');
        });

        $result = $this->verifier()->verify(['products' => [
            7 => self::line(10, 'offer-luxury', 1500.0),
            8 => self::line(999, 'offer-luxury', 45.0, [], ['product_id' => 3]),
        ]]);

        self::assertArrayNotHasKey(7, $result['rejected'], 'a bound line passes the outage');
        self::assertSame('no such booking', $result['rejected'][8]['reason'] ?? null);
    }

    public function testAThrowingProviderCheckStillBindsEveryLine(): void
    {
        self::$apiThrows = true;

        $result = $this->verifier()->verify(['products' => [
            7 => self::line(10, 'offer-luxury', 1500.0),
            8 => self::line(10, 'offer-luxury', 45.0),
        ]]);

        self::assertSame(['verifyHotelOffer:offer-luxury'], self::$calls);
        self::assertArrayNotHasKey(7, $result['rejected']);
        self::assertArrayHasKey(8, $result['rejected']);
    }

    public function testEditingAPlacedOrderInTheAdminIsLeftAlone(): void
    {
        $cart = ['order_id' => 55, 'products' => [7 => self::line(999, 'offer-luxury', 45.0)]];

        self::assertSame([], $this->verifier(admin: true)->verify($cart)['rejected']);
        self::assertArrayHasKey(7, $this->verifier()->verify($cart)['rejected'], 'a storefront cart never is');
    }
}
