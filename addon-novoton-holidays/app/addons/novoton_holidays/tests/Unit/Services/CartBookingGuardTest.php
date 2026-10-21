<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\CartBookingGuard;
use Tygh\Addons\TravelCore\Services\DepositCartLine;

/**
 * Audit H3: a cart line may carry a Novoton booking only while the booking is
 * this visitor's to buy and the line charges its price. The guard never
 * attaches a booking to a plain hotel line (the old calculate_cart_items
 * injection did, at the catalogue price), and removes a line that claims a
 * foreign, already-ordered or under-priced booking - left behind without its
 * booking it would be the hotel at the catalogue price.
 */
#[CoversClass(CartBookingGuard::class)]
final class CartBookingGuardTest extends TestCase
{
    private const int PRODUCT = 77;

    /** @var array<int, array<string, mixed>> booking_id => cart-stage row the lookup "finds" */
    private array $owned = [];

    /** @var list<list<int>> */
    private array $lookups = [];

    protected function setUp(): void
    {
        $this->owned = [];
        $this->lookups = [];
    }

    // ── plain lines ──────────────────────────────────────────────────────────

    public function testPlainHotelLineIsNeverGivenABooking(): void
    {
        // The session owns an unused booking for this very product: the old
        // hook attached it to the checkout.add line at the catalogue price.
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $plain = ['product_id' => self::PRODUCT, 'amount' => 1, 'price' => 45.0, 'extra' => []];
        $cart = ['products' => ['c1' => $plain]];
        $cartProducts = ['c1' => $plain];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['kept' => [], 'removed' => [], 'booking_ids' => []], $result);
        $this->assertSame($plain, $cart['products']['c1']);
        $this->assertSame($plain, $cartProducts['c1']);
        $this->assertSame([], $this->lookups, 'a plain line triggers no booking lookup');
    }

    // ── keep ─────────────────────────────────────────────────────────────────

    public function testOwnedBookingChargedAtItsPriceIsKept(): void
    {
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = $this->bookingLine(5, 1500.0);
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['c1'], $result['kept']);
        $this->assertSame([], $result['removed']);
        $this->assertSame([5], $result['booking_ids']);
        $this->assertSame($line, $cart['products']['c1']);
        $this->assertSame([[5]], $this->lookups);
    }

    public function testDepositLineChargingTheDepositIsKept(): void
    {
        $this->owned = [5 => $this->booking(5, 1000.0)];
        $line = $this->bookingLine(5, 300.0);
        $line['extra'][DepositCartLine::EXTRA_KEY] = ['ratio' => 0.3, 'balance_due' => '2027-05-01', 'full' => 1000.0, 'deposit' => 300.0, 'balance' => 700.0];
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $this->assertSame(['c1'], $this->guard()->reconcile($cart, $cartProducts)['kept']);
    }

    public function testConvertedPriceWithinRateDriftIsKept(): void
    {
        // RON store, EUR API: 1500 EUR × 5 = 7500 RON at add time; the rate
        // moved 1 % since, which the stored line price does not follow.
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = $this->bookingLine(5, 7500.0);
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $guard = $this->guard(static fn (float $eur): float => round($eur * 5.05, 2), CartBookingGuard::RATE_DRIFT);

        $this->assertSame(['c1'], $guard->reconcile($cart, $cartProducts)['kept']);
    }

    /**
     * A stored-price line is charged its base_price; a catalogue or cart
     * promotion may rewrite price. The booking is still the line's.
     */
    public function testLineWhosePriceAPromotionRewroteIsKeptOnItsStoredBasePrice(): void
    {
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = ['price' => 1350.0] + $this->bookingLine(5, 1500.0);
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $this->assertSame(['c1'], $this->guard()->reconcile($cart, $cartProducts)['kept']);
    }

    /**
     * Payment retry of a booking whose two rooms used different packages:
     * submission left group 1 (700) on booking 5's row and put group 2 on a
     * row of its own. The line charges both groups (line_total 1600).
     */
    public function testPlacedBookingSplitIntoPackageGroupsIsComparedWithItsLineTotal(): void
    {
        $this->owned = [5 => ['order_id' => 812, 'line_total' => 1600.0] + $this->booking(5, 700.0)];
        $line = $this->bookingLine(5, 1600.0);
        $cart = ['products' => ['c1' => $line], 'processed_order_id' => [812]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['c1'], $result['kept']);
        $this->assertSame([], $result['removed']);
    }

    /**
     * Payment retry of two separate bookings of one stay in one order (two
     * add_to_cart lines, 700 and 300). Their rows stay separate, but they share
     * the order's line_total (1000): comparing each line with that total alone
     * removed both lines while both reservations were live.
     */
    public function testTwoSeparateBookingsOfOneStayAreBothKeptOnPaymentRetry(): void
    {
        $this->owned = [
            5 => ['order_id' => 812, 'line_total' => 1000.0] + $this->booking(5, 700.0),
            6 => ['order_id' => 812, 'line_total' => 1000.0] + $this->booking(6, 300.0),
        ];
        $cart = ['products' => ['c1' => $this->bookingLine(5, 700.0), 'c2' => $this->bookingLine(6, 300.0)], 'failed_order_id' => [812]];
        $cartProducts = $cart['products'];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['c1', 'c2'], $result['kept']);
        $this->assertSame([], $result['removed']);
    }

    public function testAPlacedLineMatchingNeitherTotalIsRemoved(): void
    {
        $this->owned = [5 => ['order_id' => 812, 'line_total' => 1000.0] + $this->booking(5, 700.0)];
        $line = $this->bookingLine(5, 450.0);
        $cart = ['products' => ['c1' => $line], 'processed_order_id' => [812]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame([], $result['kept']);
        $this->assertRemoved($cart, $cartProducts, 'c1');
    }

    public function testARemovalIsFlaggedForTheRestOfTheRequest(): void
    {
        CartBookingGuard::takeRemoval();
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $kept = $this->bookingLine(5, 1500.0);
        $cart = ['products' => ['c1' => $kept]];
        $cartProducts = ['c1' => $kept];
        $this->guard()->reconcile($cart, $cartProducts);
        $this->assertFalse(CartBookingGuard::takeRemoval(), 'nothing removed, nothing to block');

        $foreign = $this->bookingLine(9, 1500.0);
        $cart = ['products' => ['c1' => $foreign]];
        $cartProducts = ['c1' => $foreign];
        $this->guard()->reconcile($cart, $cartProducts);

        $this->assertTrue(CartBookingGuard::takeRemoval());
        $this->assertFalse(CartBookingGuard::takeRemoval(), 'read once');
    }

    public function testOrderEditingLeavesLinkedBookingsAlone(): void
    {
        // Admin order management works on a placed order whose bookings are
        // linked (order_id > 0) - not cart-stage, but not to be removed.
        $line = $this->bookingLine(5, 1500.0);
        $cart = ['order_id' => 812, 'products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['kept' => ['c1'], 'removed' => [], 'booking_ids' => null], $result);
        $this->assertSame($line, $cart['products']['c1']);
        $this->assertSame([], $this->lookups);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testForeignOrAlreadyOrderedBookingIsRemoved(): void
    {
        // The lookup returns only pending, order-less bookings of this
        // visitor: booking 9 (another customer's, or already on an order) is
        // simply not among them.
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = $this->bookingLine(9, 1500.0);
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line + ['is_hotel_booking' => true, 'product_options_value' => 'x']];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame([], $result['kept']);
        $this->assertSame([], $result['booking_ids']);
        $this->assertArrayHasKey('c1', $result['removed']);
        $this->assertStringContainsString('not owned', $result['removed']['c1']);
        $this->assertRemoved($cart, $cartProducts, 'c1');
        $this->assertSame([[9]], $this->lookups);
    }

    public function testBookingOnCataloguePricedLineIsRemoved(): void
    {
        // checkout.add with forged extras: CS-Cart prices it from the
        // catalogue (stored_price N), even though the booking is the visitor's.
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = ['product_id' => self::PRODUCT, 'amount' => 1, 'price' => 45.0, 'stored_price' => 'N', 'extra' => $this->extra(5, 1500.0)];
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame('line is charged the catalogue price', $result['removed']['c1'] ?? null);
        $this->assertRemoved($cart, $cartProducts, 'c1');
    }

    public function testStoredPriceBelowTheBookingIsRemoved(): void
    {
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = $this->bookingLine(5, 45.0);
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard(null, CartBookingGuard::RATE_DRIFT)->reconcile($cart, $cartProducts);

        $this->assertStringContainsString('does not match the booking', $result['removed']['c1'] ?? '');
        $this->assertRemoved($cart, $cartProducts, 'c1');
    }

    public function testConvertedPriceBeyondRateDriftIsRemoved(): void
    {
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = $this->bookingLine(5, 7000.0); // 1500 EUR is 7500 RON
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $guard = $this->guard(static fn (float $eur): float => round($eur * 5.0, 2), CartBookingGuard::RATE_DRIFT);

        $this->assertArrayHasKey('c1', $guard->reconcile($cart, $cartProducts)['removed']);
    }

    public function testBookingOfAnotherProductIsRemoved(): void
    {
        $this->owned = [5 => ['product_id' => 99] + $this->booking(5, 1500.0)];
        $line = $this->bookingLine(5, 1500.0);
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $this->assertSame('booking belongs to another product', $this->guard()->reconcile($cart, $cartProducts)['removed']['c1'] ?? null);
    }

    public function testSecondLineClaimingTheSameBookingIsRemoved(): void
    {
        $this->owned = [5 => $this->booking(5, 1500.0)];
        $line = $this->bookingLine(5, 1500.0);
        $cart = ['products' => ['c1' => $line, 'c2' => $line]];
        $cartProducts = ['c1' => $line, 'c2' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['c1'], $result['kept']);
        $this->assertSame(['c2'], array_keys($result['removed']));
        $this->assertSame($line, $cart['products']['c1']);
        $this->assertRemoved($cart, $cartProducts, 'c2');
    }

    public function testBookingFlagWithoutIdIsRemovedWithoutLookup(): void
    {
        $extra = $this->extra(0, 1500.0);
        unset($extra['novoton_booking_id']);
        $line = ['product_id' => self::PRODUCT, 'amount' => 1, 'price' => 1500.0, 'stored_price' => 'Y', 'extra' => $extra];
        $cart = ['products' => ['c1' => $line]];
        $cartProducts = ['c1' => $line];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame('no booking id', $result['removed']['c1'] ?? null);
        $this->assertRemoved($cart, $cartProducts, 'c1');
        $this->assertSame([], $this->lookups);
    }

    public function testClaimOnlyInTheStoredCartIsVerified(): void
    {
        // place_order_post submits from $cart['products']; a claiming row
        // that is not among this calculation's rows is still checked.
        $line = $this->bookingLine(9, 1500.0);
        $cart = ['products' => ['c1' => $line, 'c2' => ['product_id' => 5, 'extra' => []]]];
        $cartProducts = ['c2' => ['product_id' => 5, 'extra' => []]];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame(['c1'], array_keys($result['removed']));
        $this->assertSame(['c2'], array_keys($cart['products']));
        $this->assertSame([[9]], $this->lookups);
    }

    public function testClaimOnlyInTheCalculationRowsIsVerified(): void
    {
        $plain = ['product_id' => self::PRODUCT, 'amount' => 1, 'price' => 45.0, 'extra' => []];
        $cart = ['products' => ['c1' => $plain]];
        $cartProducts = ['c1' => $this->bookingLine(9, 1500.0)];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertArrayHasKey('c1', $result['removed']);
        $this->assertRemoved($cart, $cartProducts, 'c1');
    }

    public function testCopiesClaimingDifferentBookingsAreRemoved(): void
    {
        $this->owned = [5 => $this->booking(5, 1500.0), 6 => $this->booking(6, 1500.0)];
        $cart = ['products' => ['c1' => $this->bookingLine(5, 1500.0)]];
        $cartProducts = ['c1' => $this->bookingLine(6, 1500.0)];

        $result = $this->guard()->reconcile($cart, $cartProducts);

        $this->assertSame('the cart copies of the line claim different bookings', $result['removed']['c1'] ?? null);
        $this->assertRemoved($cart, $cartProducts, 'c1');
    }

    public function testOwnOrderIdsCollectProcessedAndFailedOrders(): void
    {
        $this->assertSame([812, 813, 700], CartBookingGuard::ownOrderIds([
            'processed_order_id' => [812, '813'],
            'failed_order_id' => [812, 0, 700],
        ]));
        $this->assertSame([9], CartBookingGuard::ownOrderIds(['failed_order_id' => '9']));
        $this->assertSame([], CartBookingGuard::ownOrderIds([]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param (\Closure(float): float)|null $toPrimary
     */
    private function guard(?\Closure $toPrimary = null, float $drift = 0.0): CartBookingGuard
    {
        return new CartBookingGuard(
            function (array $ids): array {
                $this->lookups[] = $ids;

                return array_intersect_key($this->owned, array_flip($ids));
            },
            $toPrimary ?? static fn (float $amount): float => $amount,
            $drift,
        );
    }

    /** @return array<string, mixed> */
    private function booking(int $id, float $total): array
    {
        return ['booking_id' => $id, 'product_id' => self::PRODUCT, 'total_price' => $total, 'currency' => 'EUR'];
    }

    /** @return array<string, mixed> */
    private function extra(int $bookingId, float $total): array
    {
        return [
            'travel_booking' => true,
            'novoton_booking' => true,
            'novoton_booking_id' => $bookingId,
            'hotel_id' => 'H1',
            'check_in' => '2027-07-01',
            'check_out' => '2027-07-08',
            'rooms_data' => [['room_id' => 'DBL']],
            'total_price' => $total,
        ];
    }

    /** @return array<string, mixed> add_to_cart's row shape */
    private function bookingLine(int $bookingId, float $price): array
    {
        return [
            'product_id' => self::PRODUCT,
            'amount' => 1,
            'price' => $price,
            'base_price' => $price,
            'original_price' => $price,
            'stored_price' => 'Y',
            'extra' => $this->extra($bookingId, 1500.0),
        ];
    }

    /**
     * @param array<string, mixed> $cart
     * @param array<array-key, mixed> $cartProducts
     */
    private function assertRemoved(array $cart, array $cartProducts, string $cartId): void
    {
        $products = is_array($cart['products'] ?? null) ? $cart['products'] : [];
        $this->assertArrayNotHasKey($cartId, $products, 'the line must leave the order-building cart');
        $this->assertArrayNotHasKey($cartId, $cartProducts, 'the line must leave this calculation');
    }
}
