<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A booking's cart line when the guest pays a deposit.
 *
 * The line PRICE is the deposit, so checkout — and whichever payment method
 * the guest picks (the store's own CS-Cart methods) — charges only that. The
 * full price stays where every provider already reads it (extra.total_price,
 * the booking row), and extra.travel_deposit carries the plan plus the
 * amounts at the line's current price, in the store's primary currency:
 *
 *   {ratio, balance_due, full, deposit, balance}
 *
 * The supplier booking is unaffected: neither sphinx nor novoton is sent a
 * price. A pre-order price correction goes through reprice(), which keeps a
 * deposit line on its deposit (rescaled) instead of reverting it to full.
 *
 * Pure: works on the cart-row array.
 */
final class DepositCartLine
{
    public const EXTRA_KEY = 'travel_deposit';

    /**
     * Put the deposit on a row built at full price (primary currency).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function apply(array $row, DepositPlan $plan): array
    {
        return self::withFull($row, TypeCoerce::toFloat($row['price'] ?? 0), $plan);
    }

    /**
     * Set a row's price after a correction: the new FULL price in the primary
     * currency; a deposit line keeps charging its (rescaled) deposit.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function reprice(array $row, float $fullPrimary): array
    {
        $plan = self::plan($row);
        if ($plan !== null) {
            return self::withFull($row, $fullPrimary, $plan);
        }
        $row['price'] = $fullPrimary;
        $row['base_price'] = $fullPrimary;
        $row['original_price'] = $fullPrimary;

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function plan(array $row): ?DepositPlan
    {
        $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];

        return DepositPlan::fromSaved($extra[self::EXTRA_KEY] ?? null);
    }

    /**
     * The saved amounts of a row / order item, or [] for a full payment.
     *
     * @param array<string, mixed> $row
     * @return array{ratio: float, balance_due: string, full: float, deposit: float, balance: float}|array{}
     */
    public static function amounts(array $row): array
    {
        $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        $saved = $extra[self::EXTRA_KEY] ?? null;
        if (self::plan($row) === null || !is_array($saved)) {
            return [];
        }

        return [
            'ratio' => TypeCoerce::toFloat($saved['ratio'] ?? 0),
            'balance_due' => TypeCoerce::toString($saved['balance_due'] ?? ''),
            'full' => TypeCoerce::toFloat($saved['full'] ?? 0),
            'deposit' => TypeCoerce::toFloat($saved['deposit'] ?? 0),
            'balance' => TypeCoerce::toFloat($saved['balance'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withFull(array $row, float $full, DepositPlan $plan): array
    {
        $saved = $plan->toArray($full);
        $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        $extra[self::EXTRA_KEY] = $saved;
        $row['extra'] = $extra;
        $row['price'] = $saved['deposit'];
        $row['base_price'] = $saved['deposit'];
        $row['original_price'] = $saved['deposit'];

        return $row;
    }
}
