<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\Eurosite\Services\SyncActivity;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_sync_log — start/complete audit rows for every cron sync
 * (sphinx_sync_log shape).
 */
class SyncLogRepository
{
    use RowNarrowingTrait;

    public function start(string $syncType, string $syncMode = 'full'): int
    {
        db_query(
            "INSERT INTO ?:eurosite_sync_log (sync_type, status, sync_mode, started_at)
             VALUES (?s, 'started', ?s, NOW())",
            $syncType,
            $syncMode,
        );

        return TypeCoerce::toInt(db_get_field('SELECT LAST_INSERT_ID()'));
    }

    /**
     * @param array<string, mixed> $stats total/synced/failed/error/duration_ms
     */
    public function complete(int $logId, string $status, array $stats = []): void
    {
        if ($logId <= 0) {
            return;
        }
        db_query(
            'UPDATE ?:eurosite_sync_log SET
                status = ?s, items_total = ?i, items_synced = ?i, items_failed = ?i,
                error_message = ?s, duration_ms = ?i, completed_at = NOW()
             WHERE log_id = ?i',
            $status,
            TypeCoerce::toInt($stats['total'] ?? 0),
            TypeCoerce::toInt($stats['synced'] ?? 0),
            TypeCoerce::toInt($stats['failed'] ?? 0),
            TypeCoerce::toString($stats['error'] ?? ''),
            TypeCoerce::toInt($stats['duration_ms'] ?? 0),
            $logId,
        );
    }

    /**
     * One page of the dashboard's Recent sync activity, newest first: every
     * run, or only the problems (Services\SyncActivity::row()'s rule).
     *
     * @return list<array<string, mixed>>
     */
    public function findPage(int $page, int $perPage, bool $problems, int $now): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:eurosite_sync_log ?p ORDER BY started_at DESC, log_id DESC LIMIT ?i OFFSET ?i',
            self::where($problems, $now),
            $perPage,
            (max(1, $page) - 1) * $perPage,
        ));
    }

    /** How many runs the log holds, or how many of them are problems. */
    public function count(bool $problems, int $now): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_sync_log ?p', self::where($problems, $now)));
    }

    /**
     * The most recent row per sync_type (dashboard "last sync" cards).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getLastPerType(): array
    {
        $rows = self::asRowList(db_get_array(
            'SELECT l.* FROM ?:eurosite_sync_log l
             JOIN (SELECT sync_type, MAX(log_id) AS log_id FROM ?:eurosite_sync_log GROUP BY sync_type) m
               ON m.log_id = l.log_id',
        ));
        $out = [];
        foreach ($rows as $row) {
            $out[TypeCoerce::toString($row['sync_type'] ?? '')] = $row;
        }

        return $out;
    }

    /**
     * The Problems filter, the rule Services\SyncActivity::row() applies to
     * one run: it failed, it finished with items that failed, or it started
     * more than SyncActivity::STALL_AFTER ago and never finished. Skipped
     * items are no problem (Hotel details skips the fresh ones), and the log
     * does not count them apart, so items_failed decides, not total - synced.
     */
    private static function where(bool $problems, int $now): string
    {
        if (!$problems) {
            return '';
        }

        return db_quote(
            "WHERE status = 'failed' OR (status = 'completed' AND items_failed > 0) OR (status = 'started' AND started_at < ?s)",
            date('Y-m-d H:i:s', $now - SyncActivity::STALL_AFTER),
        );
    }

    public function trim(int $keep = 200): void
    {
        $cutoff = TypeCoerce::toInt(db_get_field(
            'SELECT log_id FROM ?:eurosite_sync_log ORDER BY log_id DESC LIMIT ?i, 1',
            max(0, $keep - 1),
        ));
        if ($cutoff > 0) {
            db_query('DELETE FROM ?:eurosite_sync_log WHERE log_id < ?i', $cutoff);
        }
    }
}
