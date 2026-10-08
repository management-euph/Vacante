<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\BalanceRepository;

/**
 * The balance of a booking paid with a deposit — from the deposit order to
 * the order that pays the rest.
 *
 *   deposit order placed   → one open travel_balances row per deposit item
 *   "Pay balance" link     → a cart line for the balance (extra.travel_balance_id),
 *                            paid through the normal checkout with the
 *                            store's own CS-Cart payment methods
 *   balance order placed   → linked (balance_order_id)
 *   balance order P / C    → balance paid
 *
 * A line links or settles a balance only when it is the line the pay link
 * built (balancePaidBy): a bare extra.travel_balance_id proves nothing, and
 * the writes only ever move a balance that is still open.
 *   deposit order I / D    → open balance cancelled (nothing more to collect)
 *
 * The pay link carries an HMAC key, so a guest checkout can pay from the
 * email without an account, and nobody can pay (or read) another balance.
 */
class BalanceService
{
    public const EXTRA_BALANCE_ID = 'travel_balance_id';
    public const PAID_STATUSES = ['P', 'C'];
    public const CANCEL_STATUSES = ['I', 'D'];

    public function __construct(
        private readonly BalanceRepository $repo = new BalanceRepository(),
        private readonly string $secret = '',
        /** @var (\Closure(string): void)|null receives each refused balance line; default fn_log_event */
        private readonly ?\Closure $log = null,
    ) {
    }

    /**
     * place_order_post: record the balances of a deposit order, and link a
     * balance order to the balances it pays.
     *
     * @param array<string, mixed> $orderInfo fn_get_order_info()
     */
    public function onOrderPlaced(int $orderId, array $orderInfo): void
    {
        foreach (self::items($orderInfo) as $itemId => $item) {
            $extra = TypeCoerce::toStringMap($item['extra'] ?? null);
            if (TypeCoerce::toInt($extra[self::EXTRA_BALANCE_ID] ?? 0) > 0) {
                // A balance line never records a balance of its own.
                $balanceId = $this->balancePaidBy($item, $orderId, 'link');
                if ($balanceId !== null && !$this->repo->linkOpen($balanceId, $orderId)) {
                    $this->warn("order {$orderId}: balance {$balanceId} was settled meanwhile, not linked");
                }
                continue;
            }
            $a = DepositCartLine::amounts($item);
            if ($a === [] || $a['balance'] <= 0) {
                continue;
            }
            $this->repo->insertIfNew([
                'order_id' => $orderId,
                'item_id' => (string) $itemId,
                'product_id' => TypeCoerce::toInt($item['product_id'] ?? 0),
                'user_id' => TypeCoerce::toInt($orderInfo['user_id'] ?? 0),
                'provider' => self::provider($extra),
                'hotel_name' => TypeCoerce::toString($extra['hotel_name'] ?? ($item['product'] ?? '')),
                'check_in' => self::date($extra['check_in'] ?? null),
                'check_out' => self::date($extra['check_out'] ?? null),
                'full_amount' => $a['full'],
                'deposit_amount' => $a['deposit'],
                'amount' => $a['balance'],
                'due_date' => $a['balance_due'],
                'status' => BalanceRepository::STATUS_OPEN,
            ]);
        }
    }

    /**
     * change_order_status: a paid balance order settles its balances; a
     * cancelled or declined deposit order cancels what is still open.
     *
     * @param array<string, mixed> $orderInfo
     */
    public function onStatusChanged(string $statusTo, array $orderInfo): void
    {
        $orderId = TypeCoerce::toInt($orderInfo['order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        if (in_array($statusTo, self::PAID_STATUSES, true)) {
            foreach (self::items($orderInfo) as $item) {
                $extra = is_array($item['extra'] ?? null) ? $item['extra'] : [];
                if (TypeCoerce::toInt($extra[self::EXTRA_BALANCE_ID] ?? 0) <= 0) {
                    continue;
                }
                $balanceId = $this->balancePaidBy($item, $orderId, 'settle');
                if ($balanceId !== null && !$this->repo->markPaidIfOpen($balanceId, $orderId, date('Y-m-d H:i:s'))) {
                    $this->warn("order {$orderId}: balance {$balanceId} is no longer open, not marked paid");
                }
            }
        }
        if (in_array($statusTo, self::CANCEL_STATUSES, true)) {
            $this->repo->cancelOpenForOrder($orderId);
        }
    }

    /**
     * The balances of an order, for its details page / emails, each with its
     * pay link while it is open.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $out = [];
        foreach ($this->repo->forOrder($orderId) as $row) {
            $row['pay_query'] = TypeCoerce::toString($row['status'] ?? '') === BalanceRepository::STATUS_OPEN
                ? $this->payQuery(TypeCoerce::toInt($row['balance_id'] ?? 0), $orderId)
                : '';
            $out[] = $row;
        }

        return $out;
    }

    /** Dispatch query of the pay link: travel_balance.pay?balance_id=…&key=… */
    public function payQuery(int $balanceId, int $orderId): string
    {
        return 'travel_balance.pay?balance_id=' . $balanceId . '&key=' . $this->key($balanceId, $orderId);
    }

    public function key(int $balanceId, int $orderId): string
    {
        return substr(hash_hmac('sha256', $balanceId . '|' . $orderId, $this->secret()), 0, 32);
    }

    public function keyMatches(int $balanceId, int $orderId, string $key): bool
    {
        return $key !== '' && hash_equals($this->key($balanceId, $orderId), $key);
    }

    private function secret(): string
    {
        if ($this->secret !== '') {
            return $this->secret;
        }
        $configured = class_exists('\Tygh\Registry') ? RegistryCoerce::string('config.crypt_key') : '';

        return $configured !== '' ? $configured : 'travel_core_balance';
    }

    /**
     * The balance an order line really pays, or null.
     *
     * extra.travel_balance_id alone proves nothing: it is a cart-line key, and
     * a line can carry it without travel_balance.pay having written it. The
     * line pays the balance only when it is the line that controller builds:
     * the balance's own product, for the deposit order the balance belongs
     * to, charging at least the balance (price × amount, both in the store's
     * primary currency like travel_balances.amount), while the balance is
     * still open. Anything else is logged and neither links nor settles.
     *
     * @param array<string, mixed> $item order line (fn_get_order_info)
     */
    private function balancePaidBy(array $item, int $orderId, string $step): ?int
    {
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : [];
        $balanceId = TypeCoerce::toInt($extra[self::EXTRA_BALANCE_ID] ?? 0);
        $balance = $this->repo->find($balanceId) ?? [];
        $status = TypeCoerce::toString($balance['status'] ?? '');
        $depositOrderId = TypeCoerce::toInt($balance['order_id'] ?? 0);
        $productId = TypeCoerce::toInt($item['product_id'] ?? 0);
        $charged = round(TypeCoerce::toFloat($item['price'] ?? 0) * TypeCoerce::toFloat($item['amount'] ?? 0), 2);
        $owed = round(TypeCoerce::toFloat($balance['amount'] ?? 0), 2);
        $linkedOrderId = TypeCoerce::toInt($balance['balance_order_id'] ?? 0);
        if ($status === BalanceRepository::STATUS_PAID && $linkedOrderId === $orderId) {
            return null; // P then C: already settled by this very order.
        }
        if ($step === 'link' && $status === BalanceRepository::STATUS_OPEN && $linkedOrderId === $orderId) {
            return null; // Order re-placed (admin edit): already linked, and MySQL reports 0 changed rows.
        }
        $refusal = match (true) {
            $balance === [] => 'no such balance',
            $status !== BalanceRepository::STATUS_OPEN => "balance is {$status}",
            $productId <= 0 || $productId !== TypeCoerce::toInt($balance['product_id'] ?? 0) => "product {$productId} is not the balance's",
            $depositOrderId === $orderId || TypeCoerce::toInt($extra['parent_order_id'] ?? 0) !== $depositOrderId => 'not for the balance\'s deposit order',
            $owed <= 0 || $charged < $owed => "line charges {$charged} of {$owed}",
            default => null,
        };
        if ($refusal !== null) {
            $this->warn("order {$orderId}: line for balance {$balanceId} refused ({$step}): {$refusal}");

            return null;
        }

        return $balanceId;
    }

    private function warn(string $message): void
    {
        $message = '[TravelBalance] ' . $message;
        if ($this->log !== null) {
            ($this->log)($message);
        } elseif (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', ['message' => $message]);
        }
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @return array<array-key, array<string, mixed>>
     */
    private static function items(array $orderInfo): array
    {
        $products = is_array($orderInfo['products'] ?? null) ? $orderInfo['products'] : [];
        $out = [];
        foreach ($products as $itemId => $item) {
            if (is_array($item)) {
                $out[$itemId] = TypeCoerce::toStringMap($item);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $extra */
    private static function provider(array $extra): string
    {
        return match (true) {
            !empty($extra['novoton_booking']) => 'novoton',
            !empty($extra['sphinx_booking']) => 'sphinx',
            default => TypeCoerce::toString($extra['travel_provider'] ?? ''),
        };
    }

    private static function date(mixed $value): ?string
    {
        $s = trim(TypeCoerce::toString($value));

        return $s === '' ? null : DateHelper::parseDate(substr($s, 0, 10));
    }
}
