<?php

declare(strict_types=1);

namespace Netopia\CsCart\Status;

use Netopia\Payment2\Enum\PaymentStatus;

/**
 * Maps NETOPIA payment statuses to CS-Cart order statuses.
 *
 * Default mapping per status group:
 *   success → P (Processed)
 *   pending → O (Open)
 *   cancel  → I (Cancelled)
 *   refund  → split: full refund → B (Backordered, restocks inventory and
 *             clears paid-accounting), partial refund → P (the order is
 *             still mostly paid, only `netopia_refunded_amount` records
 *             the partial deduction). See `mapRefund()`.
 *   fail    → F (Failed)
 *
 * Processor params may override per-status via `status_map_<int>` keys.
 * Refund (status 8) is special-cased: it supports two override keys —
 * `status_map_8_full` and `status_map_8_partial` — so admins can route
 * the two cases to distinct order-list-visible states. See `mapRefund()`.
 */
final class StatusMapper
{
    /**
     * Resolve the CS-Cart order status for a NETOPIA status code.
     *
     * Refund statuses (group=='refund') always default to the FULL refund
     * mapping when called via this method — IPN/refund callers that know
     * whether the refund is partial must use `mapRefund()` instead. This
     * keeps the legacy contract intact for non-refund callers.
     *
     * @param array<string, mixed> $processorParams Optional custom mapping
     */
    public function map(int $netopiaStatus, array $processorParams = []): string
    {
        $custom = $processorParams['status_map_' . $netopiaStatus] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        $enum = PaymentStatus::tryFrom($netopiaStatus);
        if ($enum === null) {
            return 'O';
        }

        return self::defaultCsCartStatus($enum->group());
    }

    /**
     * Resolve the CS-Cart order status for a NETOPIA refund (status 8).
     *
     * Reads `status_map_8_partial` for partial refunds, `status_map_8_full`
     * for full refunds. Defaults: `B` (Backordered, restocks inventory and
     * clears paid-accounting) for full, `P` (Processed) for partial — partial
     * refunds keep the order's Paid presentation while
     * `netopia_refunded_amount` records the deduction.
     *
     * @param array<string, mixed> $processorParams
     */
    public function mapRefund(array $processorParams, bool $isPartial): string
    {
        $key = $isPartial ? 'status_map_8_partial' : 'status_map_8_full';
        $custom = $processorParams[$key] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        return $isPartial ? 'P' : 'B';
    }

    /**
     * Return the status definitions used by the admin status-mapping UI.
     *
     * @return array<int, array{label: string, default: string, group: string}>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach (PaymentStatus::cases() as $status) {
            $group = $status->group();
            $definitions[$status->value] = [
                'label' => $status->label(),
                'default' => self::defaultCsCartStatus($group),
                'group' => $group,
            ];
        }

        return $definitions;
    }

    /**
     * Map a NETOPIA status group to the default CS-Cart order status code.
     */
    private static function defaultCsCartStatus(string $group): string
    {
        return match ($group) {
            'success' => 'P',
            'refund' => 'B',
            'cancel' => 'I',
            'fail' => 'F',
            default => 'O',
        };
    }
}
