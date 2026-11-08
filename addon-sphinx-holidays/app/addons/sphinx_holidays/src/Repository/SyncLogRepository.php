<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\SphinxHolidays\Services\SyncActivity;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_sync_log table.
 *
 * @since 1.4.0
 */
class SyncLogRepository
{
    use RowNarrowingTrait;

    /**
     * One page of the log for the dashboard's Recent sync activity, newest
     * first; $problems keeps only the runs SyncActivity::row() calls a
     * problem (failed, partly done, never finished).
     *
     * @return list<array<string, mixed>>
     */
    public function findPage(int $page, int $perPage, bool $problems, int $now): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:sphinx_sync_log ?p ORDER BY started_at DESC, log_id DESC LIMIT ?i OFFSET ?i',
            self::where($problems, $now),
            $perPage,
            (max(1, $page) - 1) * $perPage,
        ));
    }

    /** How many runs the log holds, or how many of them are problems. */
    public function count(bool $problems, int $now): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_sync_log ?p', self::where($problems, $now)));
    }

    /**
     * The Problems filter, the rule SyncActivity::row() applies to one run:
     * it failed, it finished with items not synced, or it started more than
     * SyncActivity::STALL_AFTER ago and never finished.
     */
    private static function where(bool $problems, int $now): string
    {
        if (!$problems) {
            return '';
        }

        return db_quote(
            "WHERE status = 'failed' OR (status = 'completed' AND items_synced < items_total) OR (status = 'started' AND started_at < ?s)",
            date('Y-m-d H:i:s', $now - SyncActivity::STALL_AFTER),
        );
    }
}
