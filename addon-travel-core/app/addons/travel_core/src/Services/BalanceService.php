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
            $balanceId = TypeCoerce::toInt($extra[self::EXTRA_BALANCE_ID] ?? 0);
            if ($balanceId > 0) {
                $this->repo->update($balanceId, ['balance_order_id' => $orderId]);
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
                $balanceId = TypeCoerce::toInt($extra[self::EXTRA_BALANCE_ID] ?? 0);
                if ($balanceId > 0) {
                    $this->repo->update($balanceId, [
                        'status' => BalanceRepository::STATUS_PAID,
                        'balance_order_id' => $orderId,
                        'paid_at' => date('Y-m-d H:i:s'),
                    ]);
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
