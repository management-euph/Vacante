<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * One-time removal of the storefront blocks whose types are gone: the
 * "Sphinx: Booking Form" block (a destination browse with no hotel) and the
 * "Sphinx: Best Deals" block (hotels and packages from the provider cache,
 * with no store product behind them). Guests book hotels from their product
 * pages only.
 *
 * Existing stores still have those blocks placed; without their schema and
 * template they would break the layout. The same deletes as the uninstall
 * step (func.php), run once per store, then a storage_data flag skips it.
 */
final class RemovedBlocksCleanup
{
    public const REMOVED_TYPES = ['sphinx_booking_engine', 'sphinx_best_deals'];

    public const FLAG = 'sphinx_holidays_removed_blocks';

    public static function runOnce(): void
    {
        if (!function_exists('fn_get_storage_data') || fn_get_storage_data(self::FLAG) === 'Y') {
            return;
        }
        try {
            $blockIds = TypeCoerce::toIntList(db_get_fields('SELECT block_id FROM ?:bm_blocks WHERE type IN (?a)', self::REMOVED_TYPES));
            if ($blockIds !== []) {
                db_query('DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)', $blockIds);
                db_query('DELETE FROM ?:bm_snapping WHERE block_id IN (?n)', $blockIds);
                db_query('DELETE FROM ?:bm_blocks WHERE block_id IN (?n)', $blockIds);
            }
            fn_set_storage_data(self::FLAG, 'Y');
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx: removing the old storefront blocks failed: ' . $e->getMessage()]);
        }
    }
}
