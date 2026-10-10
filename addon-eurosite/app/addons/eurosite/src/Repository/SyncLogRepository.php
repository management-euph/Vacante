<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

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
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 10): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:eurosite_sync_log ORDER BY started_at DESC, log_id DESC LIMIT ?i',
            $limit,
        ));
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
