<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Since when offers_update asks Novoton for changed offers, per country.
 *
 * It lives in CS-Cart's storage_data, not in ?:novoton_sync_log. The job
 * used to read its baseline from the newest 'product_import' log row, which
 * only the job itself wrote, and only when the API listed some offers. An
 * empty answer (most of the off-season) wrote nothing, the row aged, and
 * the nightly cleanup — which keeps the newest 100 log rows — deleted it.
 * From then on the job stopped for good with "No baseline import". A store
 * that never had the row could never start it at all.
 *
 * Now every answer moves the baseline to the time the question was asked,
 * empty answers included, and a first run asks about the last
 * FIRST_RUN_DAYS days.
 */
final class OffersUpdateBaseline
{
    public const int FIRST_RUN_DAYS = 7;

    private const string PREFIX = 'novoton_offers_update_since.';

    /**
     * Pure: the stored baseline, else the legacy product_import row (the
     * first run after this change), else FIRST_RUN_DAYS before $now.
     */
    public static function resolve(?string $stored, ?string $legacy, int $now): string
    {
        foreach ([$stored, $legacy] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && strtotime($value) !== false) {
                return $value;
            }
        }

        return date('Y-m-d H:i:s', $now - self::FIRST_RUN_DAYS * 86400);
    }

    public static function get(string $country): ?string
    {
        if (!function_exists('fn_get_storage_data')) {
            return null;
        }
        $value = TypeCoerce::toString(fn_get_storage_data(self::key($country)));

        return $value === '' ? null : $value;
    }

    /** Record that Novoton answered a question asked at $askedAt (Y-m-d H:i:s). */
    public static function advance(string $country, string $askedAt): void
    {
        if (function_exists('fn_set_storage_data')) {
            fn_set_storage_data(self::key($country), $askedAt);
        }
    }

    private static function key(string $country): string
    {
        return self::PREFIX . strtoupper(trim($country));
    }
}
