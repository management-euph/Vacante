<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DepositCartLine;

/**
 * Decides, on every cart calculation, which cart lines may carry a Novoton
 * booking (audit H3).
 *
 * place_order_post submits the booking named by a line's
 * extra.novoton_booking_id, while the customer is charged the line's price.
 * A line therefore keeps its booking only when
 *   - the booking is still this visitor's to buy (the injected lookup,
 *     CartBookingRepository::findCartStage: pending with no order, or linked
 *     to this cart's own processed/failed order; owned by this account, this
 *     session, or verified earlier by this session's cart),
 *   - it is for the line's product and no earlier line already holds it,
 *   - the line charges the booking: stored price, equal to the booking total
 *     converted to the primary currency (the deposit on a deposit line).
 * Any other line claiming a booking is REMOVED from the cart. Left in place
 * without its booking keys it would become the hotel product at the
 * catalogue "from" price — purchasable, and, for a booking already
 * submitted, beside a live reservation. Removed, there is nothing to order
 * until the customer books again (the hook tells them so).
 *
 * The guard never ATTACHES a booking: a line without booking keys (e.g. the
 * hotel product added through CS-Cart's own checkout.add) is not touched.
 * The old calculate_cart_items "DB → cart sync" handed such a line the
 * session's first unused booking at the catalogue price.
 *
 * Works on the cart arrays; DB and currency come in as closures. The one
 * piece of state is the request-scoped "a line was removed" flag.
 */
final class CartBookingGuard
{
    /**
     * Relative difference allowed between the line price and the converted
     * booking total when the store's primary currency is not the API
     * currency: a rate update since add_to_cart moves the conversion, not
     * the stored line price. Same currency: one cent of rounding only.
     */
    public const float RATE_DRIFT = 0.02;

    /**
     * Session key holding the booking ids this session's cart verified on
     * its last calculation: the ownership proof that survives CS-Cart's
     * session-id regeneration at login (see CartBookingRepository).
     */
    public const string SESSION_KEY = 'novoton_cart_booking_ids';

    private const float CENT = 0.01;

    /**
     * A line was removed during this request. When that request is the order
     * click, the order must not go through: CS-Cart may already have counted
     * the removed line in this calculation's totals (the hook runs after the
     * lines are priced), so the customer re-confirms the cart without it
     * (pre_place_order reads it with takeRemoval()). Request-scoped on purpose.
     */
    private static bool $removedThisRequest = false;

    /**
     * $cartStageBookings returns booking_id => row for the ids this visitor
     * may still be charged for; $toPrimary converts an API-currency amount to
     * the store's primary currency (CurrencyService::convertFromApiCurrency,
     * as add_to_cart); $rateDrift is self::RATE_DRIFT when the primary
     * currency differs from the API's, else 0.
     *
     * @param \Closure(list<int>): array<int, array<string, mixed>> $cartStageBookings
     * @param \Closure(float): float $toPrimary
     */
    public function __construct(
        private readonly \Closure $cartStageBookings,
        private readonly \Closure $toPrimary,
        private readonly float $rateDrift = 0.0,
    ) {
    }

    /**
     * The cart's own placed orders: CS-Cart records them in processed_order_id
     * when the order is created and in failed_order_id when its payment fails,
     * and keeps the cart for the retry.
     *
     * @param array<string, mixed> $cart
     * @return list<int>
     */
    public static function ownOrderIds(array $cart): array
    {
        $ids = [];
        foreach (['processed_order_id', 'failed_order_id'] as $key) {
            $value = $cart[$key] ?? [];
            array_push($ids, ...TypeCoerce::toIntList(is_array($value) ? $value : [$value]));
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Whether a line was removed during this request; resets the flag.
     */
    public static function takeRemoval(): bool
    {
        $removed = self::$removedThisRequest;
        self::$removedThisRequest = false;

        return $removed;
    }

    /**
     * Remove every line that may not carry the booking it claims.
     *
     * Both copies are checked and cleaned: $cart['products'] (what the order
     * is built from and place_order_post submits, and what is saved) and
     * $cartProducts (this calculation's rows) — a line claiming a booking in
     * either copy is verified.
     *
     * Returns the cart ids kept, removed cart id => reason, and the booking
     * ids of the kept lines (null: not checked — a placed order being edited).
     *
     * @param array<string, mixed> $cart
     * @param array<array-key, mixed> $cartProducts
     * @return array{kept: list<array-key>, removed: array<array-key, string>, booking_ids: list<int>|null}
     */
    public function reconcile(array &$cart, array &$cartProducts): array
    {
        $stored = is_array($cart['products'] ?? null) ? $cart['products'] : [];

        /** @var array<array-key, int> $claims cart id => claimed booking id (0 = flag without an id, -1 = copies disagree) */
        $claims = [];
        foreach (array_keys($stored + $cartProducts) as $cartId) {
            $ids = [];
            foreach ([$stored[$cartId] ?? null, $cartProducts[$cartId] ?? null] as $row) {
                $extra = is_array($row) && is_array($row['extra'] ?? null) ? $row['extra'] : [];
                if (!empty($extra['novoton_booking']) || !empty($extra['novoton_booking_id'])) {
                    $ids[] = TypeCoerce::toInt($extra['novoton_booking_id'] ?? 0);
                }
            }
            if ($ids === []) {
                continue; // plain line: no booking claimed, nothing to verify or attach
            }
            $ids = array_values(array_unique($ids));
            $claims[$cartId] = count($ids) === 1 ? $ids[0] : -1;
        }

        // Editing a placed order (admin order management sets cart.order_id):
        // its bookings are linked to that order by now, so the cart-stage
        // rule does not apply - and place_order_post never resubmits a linked
        // booking. Leave the lines as they are.
        if (TypeCoerce::toInt($cart['order_id'] ?? 0) > 0) {
            return ['kept' => array_keys(array_filter($claims, static fn (int $id): bool => $id > 0)), 'removed' => [], 'booking_ids' => null];
        }
        if ($claims === []) {
            return ['kept' => [], 'removed' => [], 'booking_ids' => []];
        }

        $ids = array_values(array_unique(array_filter($claims, static fn (int $id): bool => $id > 0)));
        $bookings = $ids === [] ? [] : ($this->cartStageBookings)($ids);

        $kept = [];
        $removed = [];
        $used = [];
        foreach ($claims as $cartId => $bookingId) {
            $line = self::line($cart, $cartProducts, $cartId);
            $reason = $this->rejection($line, $bookingId, $bookings[$bookingId] ?? null, isset($used[$bookingId]));
            if ($reason === null) {
                $used[$bookingId] = true;
                $kept[] = $cartId;
                continue;
            }
            self::remove($cart, $cartProducts, $cartId);
            $removed[$cartId] = $reason;
            self::$removedThisRequest = true;
        }

        return ['kept' => $kept, 'removed' => $removed, 'booking_ids' => array_keys($used)];
    }

    /**
     * Why a line may not carry the booking it claims; null when it may.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed>|null $booking the lookup's row, null = not this visitor's / ordered elsewhere / gone
     */
    private function rejection(array $line, int $bookingId, ?array $booking, bool $alreadyUsed): ?string
    {
        if ($bookingId < 0) {
            return 'the cart copies of the line claim different bookings';
        }
        if ($bookingId === 0) {
            return 'no booking id';
        }
        if ($booking === null) {
            return 'booking not owned by this visitor, ordered elsewhere, failed or gone';
        }
        if ($alreadyUsed) {
            return 'booking already held by another cart line';
        }
        if (TypeCoerce::toInt($booking['product_id'] ?? 0) !== TypeCoerce::toInt($line['product_id'] ?? 0)) {
            return 'booking belongs to another product';
        }

        // What the line may charge: the booking's own total. A booking already
        // placed in one of this cart's orders may also have been split into
        // one row per package group at submission, and then the line charges
        // all of them (CartBookingRepository's line_total). Either is a price
        // the server stored: two separate bookings of one stay in one order
        // share a line_total but each line charges only its own row.
        $totals = array_values(array_unique(array_filter([
            TypeCoerce::toFloat($booking['total_price'] ?? 0),
            TypeCoerce::toFloat($booking['line_total'] ?? 0),
        ], static fn (float $total): bool => $total > 0)));
        if ($totals === []) {
            return 'booking has no price';
        }
        if (TypeCoerce::toString($line['stored_price'] ?? '') !== 'Y') {
            return 'line is charged the catalogue price';
        }

        // The price the line must charge for this booking: the converted total,
        // or — on a deposit line — the deposit of that total. A stored-price
        // line is charged its base_price (add_to_cart and DepositCartLine set
        // price and base_price alike); price is what a promotion may rewrite.
        $charged = TypeCoerce::toFloat($line['base_price'] ?? $line['price'] ?? 0);
        $expected = 0.0;
        foreach ($totals as $total) {
            $expected = TypeCoerce::toFloat(DepositCartLine::reprice($line, ($this->toPrimary)($total))['price'] ?? 0);
            if (abs($charged - $expected) <= max(self::CENT, $expected * $this->rateDrift) + 1e-9) {
                return null;
            }
        }

        return sprintf('line price %.2f does not match the booking (%.2f)', $charged, $expected);
    }

    /**
     * The line as stored in the cart (stored_price / price live there), with
     * this calculation's row as the fallback.
     *
     * @param array<string, mixed> $cart
     * @param array<array-key, mixed> $cartProducts
     * @return array<string, mixed>
     */
    private static function line(array $cart, array $cartProducts, int|string $cartId): array
    {
        $stored = is_array($cart['products'] ?? null) ? ($cart['products'][$cartId] ?? null) : null;
        if (is_array($stored)) {
            return TypeCoerce::toStringMap($stored);
        }

        return TypeCoerce::toStringMap($cartProducts[$cartId] ?? []);
    }

    /**
     * @param array<string, mixed> $cart
     * @param array<array-key, mixed> $cartProducts
     */
    private static function remove(array &$cart, array &$cartProducts, int|string $cartId): void
    {
        if (is_array($cart['products'] ?? null)) {
            $products = $cart['products'];
            unset($products[$cartId]);
            $cart['products'] = $products;
        }
        unset($cartProducts[$cartId]);
    }
}
