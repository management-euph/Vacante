<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\Services\DepositPlan;
use Tygh\Addons\TravelCore\Services\DepositPolicy;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Binds a Sphinx cart line to the booking row add_to_cart created for it
 * (audit H2).
 *
 * place_order_post books the offer of a line's extra.travel_booking_id while
 * the customer is charged the line's price, and extra is not proof of
 * anything: a cheap product carrying an expensive offer's extras used to be
 * charged its catalogue price while the expensive offer was booked. A line
 * may therefore carry its booking only when the row
 *   - exists and is for the line's offer (and product, when the row has one);
 *   - is still this visitor's to buy: pending with no order yet and owned by
 *     this account (user_id > 0), this session (non-empty session_id) or
 *     bound earlier by this session's cart (SESSION_KEY: CS-Cart regenerates
 *     the session id at login, the session data survives) — or linked to
 *     this cart's own placed order (a failed payment sends the customer back
 *     with the same cart) and not failed or cancelled there;
 * and the line charges the row's price: total_price converted to the primary
 * currency (the deposit of it on a deposit line), equal to price x amount
 * within a cent. A deposit plan on the line (extra.travel_deposit) counts
 * only when deposits are offered for sphinx, the row is a hotel booking and
 * the plan is the one the row's own payment terms give (what add_to_cart
 * worked out) — on a row of the cart's own placed order, the plan that
 * order's item was placed with: a line could otherwise charge any fraction
 * of its booking. The amounts the plan saves on the line (full, deposit,
 * balance: travel_core records the balance still owed from them) must be
 * the row price's too (canonicalLine / savesDepositOf). A genuine plan left
 * from before deposits were switched off is not refused: the line is to
 * charge the full price instead.
 *
 * Pure apart from the default wiring (fromSession): the row lookup, the
 * placed order item and the currency conversion come in as closures.
 */
final class CheckoutBookingBinder
{
    /** Session key: the booking ids this session's cart bound on its last calculation. */
    public const string SESSION_KEY = 'sphinx_cart_booking_ids';

    private const float CENT = 0.01;

    /** Submission outcomes with no live reservation behind them. */
    private const array DEAD_STATUSES = [TravelConstants::STATUS_FAILED, TravelConstants::STATUS_CANCELLED];

    /** room_type values of the non-hotel bookings (as BookingRetryService reads them). */
    private const array NON_HOTEL_TYPES = ['circuit', 'experience', 'package'];

    /** DepositPlan keeps its ratio to 6 decimals. */
    private const float RATIO_EPSILON = 1e-6;

    /**
     * $placedItem gives the item of a placed order (order id, booking id) that
     * carries the booking, or null when there is none; a null $placedItem
     * means no lookup.
     *
     * @param \Closure(int): (array<string, mixed>|null) $findBooking the ?:sphinx_bookings row of a booking id
     * @param \Closure(float, string): float $toPrimary an amount in the given (API) currency, in the store's primary currency
     * @param list<int> $verifiedIds booking ids this session's cart bound earlier (SESSION_KEY)
     * @param bool $adminArea the admin panel (order management), where a placed order is edited
     * @param bool $depositsOffered DepositPolicy offers a deposit for sphinx_holidays
     * @param string $today Y-m-d ('' = the current date)
     * @param (\Closure(int, int): (array<string, mixed>|null))|null $placedItem
     */
    public function __construct(
        private readonly \Closure $findBooking,
        private readonly \Closure $toPrimary,
        private readonly int $userId,
        private readonly string $sessionId,
        private readonly array $verifiedIds = [],
        private readonly bool $adminArea = false,
        private readonly bool $depositsOffered = true,
        private readonly string $today = '',
        private readonly ?\Closure $placedItem = null,
    ) {
    }

    /** The binder of the current request: its account, session and bound ids. */
    public static function fromSession(?SessionAccessor $session = null): self
    {
        $session ??= new SessionAccessor();
        $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';

        return new self(
            static fn (int $bookingId): ?array => Container::getBookingRepository()->findById($bookingId),
            static fn (float $amount, string $currency): float => (new CurrencyService($currency !== '' ? $currency : 'EUR'))
                ->convertFromApiCurrency($amount, $primary),
            TypeCoerce::toInt($session->auth()['user_id'] ?? 0),
            (string) session_id(),
            TypeCoerce::toIntList($session->get(self::SESSION_KEY)),
            defined('AREA') && AREA === 'A',
            DepositPolicy::current()->offers('sphinx_holidays'),
            '',
            self::placedItemLookup(),
        );
    }

    /**
     * The order item carrying a booking, from fn_get_order_info (one read per
     * order and request).
     *
     * @return \Closure(int, int): (array<string, mixed>|null)
     */
    private static function placedItemLookup(): \Closure
    {
        /** @var array<int, array<array-key, mixed>> $orders */
        $orders = [];

        return static function (int $orderId, int $bookingId) use (&$orders): ?array {
            if (!isset($orders[$orderId])) {
                $info = function_exists('fn_get_order_info') ? fn_get_order_info($orderId) : null;
                $orders[$orderId] = TypeCoerce::toArrayMap(is_array($info) ? ($info['products'] ?? null) : null);
            }
            foreach ($orders[$orderId] as $item) {
                $item = TypeCoerce::toStringMap($item);
                if (self::bookingIdOf($item) === $bookingId) {
                    return $item;
                }
            }

            return null;
        };
    }

    /**
     * The booking type of a row, from what add_to_cart stored (room_type is
     * 'circuit' / 'package' / 'experience' for those, the room name for a
     * hotel) — never the cart line's extra.booking_type.
     *
     * @param array<string, mixed> $row
     */
    public static function bookingTypeOf(array $row): string
    {
        $type = TypeCoerce::toString($row['room_type'] ?? '');

        return in_array($type, self::NON_HOTEL_TYPES, true) ? $type : 'hotel';
    }

    public function isAdminArea(): bool
    {
        return $this->adminArea;
    }

    /**
     * Admin order management editing a placed order (it sets cart.order_id):
     * its bookings are linked to that order by now and place_order_post never
     * resubmits a linked booking, so the cart-stage rules do not apply.
     *
     * @param array<string, mixed> $cart
     */
    public function editsPlacedOrder(array $cart): bool
    {
        return $this->adminArea && TypeCoerce::toInt($cart['order_id'] ?? 0) > 0;
    }

    /**
     * The cart's own placed orders: CS-Cart records them in processed_order_id
     * when the order is created and in failed_order_id when its payment fails.
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
     * The booking row a line names (extra.travel_booking_id), null when there is none.
     *
     * @param array<string, mixed> $line cart line
     * @return array<string, mixed>|null
     */
    public function rowOf(array $line): ?array
    {
        $bookingId = self::bookingIdOf($line);

        return $bookingId > 0 ? ($this->findBooking)($bookingId) : null;
    }

    /** @param array<string, mixed> $line cart line */
    public static function bookingIdOf(array $line): int
    {
        return TypeCoerce::toInt(TypeCoerce::toStringMap($line['extra'] ?? null)['travel_booking_id'] ?? 0);
    }

    /**
     * Why a line may not carry the booking row it names; null when it may.
     * The deposit plan the line charges by is checked here; the price
     * separately (expectedCharge / charges).
     *
     * @param array<string, mixed> $line cart line (product_id, extra)
     * @param array<string, mixed>|null $row rowOf($line)
     * @param list<int> $ownOrderIds ownOrderIds($cart)
     */
    public function rejection(array $line, ?array $row, array $ownOrderIds = []): ?string
    {
        $bookingId = self::bookingIdOf($line);
        if ($bookingId <= 0) {
            return 'no booking id';
        }
        if ($row === null || TypeCoerce::toInt($row['booking_id'] ?? 0) !== $bookingId) {
            return 'no such booking';
        }

        $extra = TypeCoerce::toStringMap($line['extra'] ?? null);
        $offerId = TypeCoerce::toString($row['offer_id'] ?? '');
        if ($offerId === '' || $offerId !== TypeCoerce::toString($extra['offer_id'] ?? '')) {
            return 'booking is for another offer';
        }
        $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
        if ($productId > 0 && $productId !== TypeCoerce::toInt($line['product_id'] ?? 0)) {
            return 'booking belongs to another product';
        }

        $status = TypeCoerce::toString($row['status'] ?? '');
        $orderId = TypeCoerce::toInt($row['order_id'] ?? 0);
        if ($orderId > 0) {
            return in_array($orderId, $ownOrderIds, true) && !in_array($status, self::DEAD_STATUSES, true)
                ? $this->depositRejection($line, $row)
                : 'booking is attached to another order';
        }
        if ($status !== TravelConstants::STATUS_PENDING) {
            return 'booking is not pending';
        }
        if (!$this->owns($row, $bookingId)) {
            return 'booking is not owned by this visitor';
        }

        return $this->depositRejection($line, $row);
    }

    /**
     * The line as it must stand for a row it may carry (rejection() null):
     * priced at the row's total_price (API currency) in the primary currency
     * — the deposit of that on a deposit line, whose saved amounts (full,
     * deposit, balance) are the row price's. A plan from before deposits were
     * switched off is dropped (full price), except on a row of the cart's own
     * placed order, which keeps the plan it was placed with. Null when the
     * row has no price (never a free pass).
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function canonicalLine(array $line, array $row): ?array
    {
        $total = TypeCoerce::toFloat($row['total_price'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        $full = ($this->toPrimary)($total, TypeCoerce::toString($row['currency'] ?? ''));
        if (!$this->depositsOffered && TypeCoerce::toInt($row['order_id'] ?? 0) <= 0) {
            $line = self::withoutDeposit($line);
        }

        return DepositCartLine::reprice($line, $full);
    }

    /**
     * The price the line must charge for the row (canonicalLine's), null
     * when the row has no price.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $row
     */
    public function expectedCharge(array $line, array $row): ?float
    {
        $canonical = $this->canonicalLine($line, $row);

        return $canonical === null ? null : TypeCoerce::toFloat($canonical['price'] ?? 0);
    }

    /**
     * Whether the deposit the line saves (extra.travel_deposit) is the
     * canonical line's: the same plan with the same full, deposit and
     * balance amounts, within a cent each — or neither has one. travel_core
     * records the balance still owed from these amounts.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $canonical canonicalLine()
     */
    public static function savesDepositOf(array $line, array $canonical): bool
    {
        $saved = DepositCartLine::amounts($line);
        $due = DepositCartLine::amounts($canonical);
        if ($saved === [] || $due === []) {
            return $saved === $due;
        }
        if (abs($saved['ratio'] - $due['ratio']) > self::RATIO_EPSILON || $saved['balance_due'] !== $due['balance_due']) {
            return false;
        }
        foreach (['full', 'deposit', 'balance'] as $amount) {
            if (abs($saved[$amount] - $due[$amount]) > self::CENT + 1e-9) {
                return false;
            }
        }

        return true;
    }

    /**
     * The line paying in full: its deposit plan removed.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public static function withoutDeposit(array $line): array
    {
        if (is_array($line['extra'] ?? null)) {
            $extra = $line['extra'];
            unset($extra[DepositCartLine::EXTRA_KEY]);
            $line['extra'] = $extra;
        }

        return $line;
    }

    /**
     * Whether the line charges $expected: price x amount within a cent.
     *
     * @param array<string, mixed> $line
     */
    public static function charges(array $line, float $expected): bool
    {
        $amount = array_key_exists('amount', $line) ? TypeCoerce::toInt($line['amount']) : 1;
        $charged = TypeCoerce::toFloat($line['price'] ?? 0) * $amount;

        return abs($charged - $expected) <= self::CENT + 1e-9;
    }

    /**
     * The booking ids the cart's Sphinx lines may carry (price aside), for
     * SESSION_KEY: remembered while the session id still matches, they keep
     * the bookings this visitor's after a login regenerates it.
     *
     * @param array<string, mixed> $cart
     * @return list<int>
     */
    public function boundBookingIds(array $cart): array
    {
        $ownOrderIds = self::ownOrderIds($cart);
        $ids = [];
        foreach (TypeCoerce::toArrayMap($cart['products'] ?? null) as $product) {
            $line = TypeCoerce::toStringMap($product);
            if (empty(TypeCoerce::toStringMap($line['extra'] ?? null)['sphinx_booking'])) {
                continue;
            }
            if ($this->rejection($line, $this->rowOf($line), $ownOrderIds) === null) {
                $ids[] = self::bookingIdOf($line);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Why the line's deposit plan is not the booking's; null when the line
     * carries none (it charges the full price) or the row's terms give it.
     *
     * On a row of the cart's own placed order (a retried payment) the plan
     * is the one the order's item was placed with: the orders sync rewrites
     * the row's terms as the supplier status moves, the placed plan stays.
     * Elsewhere the plan is rebuilt as add_to_cart built it: the row's terms
     * through SphinxBookingSidebarBuilder::terms and
     * DepositPlan::fromInstallments, for the day the row was written
     * (created_at / updated_at: add_to_cart upserts it) or today — an
     * installment falling due in between changes the ratio, and either day's
     * plan is one the terms gave. With deposits switched off a cart-stage
     * line is not refused: canonicalLine charges it the full price.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $row
     */
    private function depositRejection(array $line, array $row): ?string
    {
        $plan = DepositCartLine::plan($line);
        if ($plan === null) {
            return null;
        }
        $orderId = TypeCoerce::toInt($row['order_id'] ?? 0);
        $placed = $orderId > 0 && $this->placedItem !== null
            ? ($this->placedItem)($orderId, TypeCoerce::toInt($row['booking_id'] ?? 0))
            : null;
        if ($placed !== null) {
            $placedPlan = DepositCartLine::plan($placed);

            return $placedPlan !== null
                && abs($placedPlan->ratio - $plan->ratio) <= self::RATIO_EPSILON
                && $placedPlan->balanceDue === $plan->balanceDue
                ? null
                : 'deposit does not match the placed order';
        }
        if (!$this->depositsOffered) {
            // A placed order's line keeps its plan; without the placed item
            // to vouch for it, it is not one to trust.
            return $orderId > 0 ? 'deposits are not offered' : null;
        }
        if (self::bookingTypeOf($row) !== 'hotel') {
            return 'deposit on a booking without a payment schedule';
        }

        [, $installments] = SphinxBookingSidebarBuilder::terms($row['cancellation_fees_json'] ?? null, $row['payment_terms_json'] ?? null);
        $days = [$this->today !== '' ? $this->today : date('Y-m-d')];
        foreach (['created_at', 'updated_at'] as $column) {
            $day = substr(TypeCoerce::toString($row[$column] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && $day !== '0000-00-00') {
                $days[] = $day;
            }
        }
        foreach (array_unique($days) as $day) {
            $terms = DepositPlan::fromInstallments($installments, $day);
            if ($terms !== null
                && abs($terms->ratio - $plan->ratio) <= self::RATIO_EPSILON
                && $terms->balanceDue === $plan->balanceDue) {
                return null;
            }
        }

        return "deposit does not match the booking's payment terms";
    }

    /**
     * user_id counts only for a real account and session_id only when
     * non-empty: guest rows are stored with user_id = 0 (audit C1).
     *
     * @param array<string, mixed> $row
     */
    private function owns(array $row, int $bookingId): bool
    {
        if ($this->userId > 0 && TypeCoerce::toInt($row['user_id'] ?? 0) === $this->userId) {
            return true;
        }
        if (trim($this->sessionId) !== '' && TypeCoerce::toString($row['session_id'] ?? '') === $this->sessionId) {
            return true;
        }

        return in_array($bookingId, $this->verifiedIds, true);
    }
}
