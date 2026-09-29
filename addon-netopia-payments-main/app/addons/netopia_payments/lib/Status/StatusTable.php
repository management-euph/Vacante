<?php

declare(strict_types=1);

namespace Netopia\CsCart\Status;

use Netopia\Payment2\Enum\PaymentStatus;

/**
 * Rows for the settings screen's "Order statuses" table.
 *
 * The NETOPIA API has 23 statuses; a store meets about nine of them. Those
 * are always shown, in the order a payment lives through them; the rest
 * (chargebacks, recurring payments, trials, intermediate 3D Secure steps)
 * sit in a folded "rare" list. Refunds (8) are two rows, full and partial,
 * because StatusMapper::mapRefund maps them separately.
 *
 * Each row carries its default, so the page can mark changed rows and put
 * everything back with "Reset to defaults".
 */
final class StatusTable
{
    /** Row keys shown first, in this order (the status_map_<key> suffix). */
    public const array COMMON = ['3', '5', '6', '8_full', '8_partial', '4', '12', '11', '23'];

    /**
     * @param array<string, mixed> $processorParams
     * @return array{common: list<array<string, mixed>>, rare: list<array<string, mixed>>, changed: int}
     */
    public static function build(array $processorParams): array
    {
        $rows = [];
        foreach (PaymentStatus::cases() as $status) {
            if ($status === PaymentStatus::Credit) {
                $rows['8_full'] = self::row('8_full', 8, 'refund', 'B', 'netopia_customer_msg_refunded', $processorParams);
                $rows['8_partial'] = self::row('8_partial', 8, 'refund', 'P', 'netopia_customer_msg_refunded', $processorParams);
                continue;
            }
            $key = (string) $status->value;
            $rows[$key] = self::row(
                $key,
                $status->value,
                $status->group(),
                self::defaultFor($status->group()),
                StatusMessage::customerKeyFor($status),
                $processorParams,
            );
        }

        $common = [];
        foreach (self::COMMON as $key) {
            $common[] = $rows[$key];
            unset($rows[$key]);
        }
        $rare = array_values($rows);

        $changed = 0;
        foreach ([...$common, ...$rare] as $row) {
            $changed += $row['changed'] ? 1 : 0;
        }

        return ['common' => $common, 'rare' => $rare, 'changed' => $changed];
    }

    public static function defaultFor(string $group): string
    {
        return match ($group) {
            'success' => 'P',
            'refund' => 'B',
            'cancel' => 'I',
            'fail' => 'F',
            default => 'O',
        };
    }

    /**
     * @param array<string, mixed> $processorParams
     * @return array{key: string, code: int, group: string, lang_key: string, default: string, value: string, changed: bool, customer_msg_key: string}
     */
    private static function row(string $key, int $code, string $group, string $default, string $customerKey, array $processorParams): array
    {
        $saved = $processorParams['status_map_' . $key] ?? null;
        $value = is_string($saved) && $saved !== '' ? $saved : $default;

        return [
            'key' => $key,
            'code' => $code,
            'group' => $group,
            'lang_key' => 'netopia_ntp_status_' . $key,
            'default' => $default,
            'value' => $value,
            'changed' => $value !== $default,
            'customer_msg_key' => $customerKey,
        ];
    }
}
