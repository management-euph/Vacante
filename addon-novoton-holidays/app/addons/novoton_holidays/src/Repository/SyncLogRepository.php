<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Sync Log Repository
 *
 * Centralized database access for sync logs.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

class SyncLogRepository implements SyncLogRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Find log by ID
     * @return array<string, mixed>|null
     */
    public function findById(int $log_id): ?array
    {
        $log = self::asRow(db_get_row('SELECT * FROM ?:novoton_sync_log WHERE log_id = ?i', $log_id));
        return $log === [] ? null : $log;
    }

    /**
     * Find recent logs
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $limit = 20, string $type = ''): array
    {
        $where = '';
        if (!empty($type)) {
            $where = db_quote('WHERE sync_type = ?s', $type);
        }

        return self::asRowList(db_get_array(
            "SELECT * FROM ?:novoton_sync_log {$where} ORDER BY sync_date DESC LIMIT ?i",
            $limit,
        ));
    }

    /**
     * Find logs by type
     * @return list<array<string, mixed>>
     */
    public function findByType(string $type, int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:novoton_sync_log WHERE sync_type = ?s ORDER BY sync_date DESC {$limit_clause}",
            $type,
        ));
    }

    /**
     * Get last sync for a type
     * @return array<string, mixed>|null
     */
    public function getLastSync(string $type): ?array
    {
        $log = self::asRow(db_get_row(
            'SELECT * FROM ?:novoton_sync_log WHERE sync_type = ?s ORDER BY sync_date DESC LIMIT 1',
            $type,
        ));
        return $log === [] ? null : $log;
    }

    /**
     * Get last sync date for a type
     */
    public function getLastSyncDate(string $type): ?string
    {
        $result = TypeCoerce::toString(db_get_field(
            'SELECT sync_date FROM ?:novoton_sync_log WHERE sync_type = ?s ORDER BY sync_date DESC LIMIT 1',
            $type,
        ));
        return $result === '' ? null : $result;
    }

    /**
     * Create log entry
     * @param array<string, mixed> $data
     */
    public function create(string $type, array $data): int
    {
        $log_data = [
            'sync_type' => $type,
            'sync_date' => date('Y-m-d H:i:s'),
            'products_total' => $data['total'] ?? $data['products_total'] ?? 0,
            'products_updated' => $data['updated'] ?? $data['products_updated'] ?? 0,
            'products_failed' => $data['failed'] ?? $data['errors'] ?? $data['products_failed'] ?? 0,
            'duration_seconds' => $data['duration'] ?? $data['duration_seconds'] ?? 0,
            'status' => $data['status'] ?? 'completed',
            'notes' => isset($data['details']) ? (is_array($data['details']) ? json_encode($data['details']) : $data['details']) : '',
        ];

        $log_data = array_filter($log_data, static fn ($v): bool => $v !== null);
        return TypeCoerce::toInt(db_query('INSERT INTO ?:novoton_sync_log ?e', $log_data));
    }

    /**
     * Log sync result (convenience method)
     * @param array<string, mixed> $details
     */
    public function logSync(string $type, int $total, int $updated, int $failed = 0, int $duration = 0, string $status = 'completed', array $details = []): int
    {
        return $this->create($type, [
            'total' => $total,
            'updated' => $updated,
            'failed' => $failed,
            'duration' => $duration,
            'status' => $status,
            'details' => $details,
        ]);
    }

    /**
     * Delete old logs
     */
    public function deleteOld(int $days = 30): int
    {
        // In CS-Cart, db_query returns affected rows count for DELETE
        $affected = db_query('DELETE FROM ?:novoton_sync_log WHERE sync_date < DATE_SUB(NOW(), INTERVAL ?i DAY)', $days);
        return TypeCoerce::toInt($affected);
    }

    /**
     * Count logs, optionally of one type and/or one status.
     */
    public function count(string $type = '', string $status = ''): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:novoton_sync_log ?p', self::where($type, $status)));
    }

    /** WHERE clause for the optional type / status filters ('' when neither is set). */
    private static function where(string $type, string $status): string
    {
        $conditions = [];
        if ($type !== '') {
            $conditions[] = db_quote('sync_type = ?s', $type);
        }
        if ($status !== '') {
            $conditions[] = db_quote('status = ?s', $status);
        }

        return $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
    }

    /**
     * Find logs with pagination (for AJAX)
     *
     * @param int $page Current page (1-based)
     * @param int $per_page Items per page
     * @param string $type Optional sync type filter
     * @param string $status Optional status filter (completed / failed / running)
     * @return array<string, mixed> ['items' => array, 'total' => int, 'pages' => int]
     */
    public function findPaginated(int $page = 1, int $per_page = 10, string $type = '', string $status = ''): array
    {
        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;

        $where = self::where($type, $status);

        $items = self::asRowList(db_get_array(
            "SELECT * FROM ?:novoton_sync_log {$where} ORDER BY sync_date DESC LIMIT ?i OFFSET ?i",
            $per_page,
            $offset,
        ));

        $total = $this->count($type, $status);
        $pages = (int) ceil($total / $per_page);

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Trim logs to keep only the N most recent entries.
     *
     * @return int Number of rows deleted
     */
    public function trimToLatest(int $keep = 100): int
    {
        $total = $this->count();
        if ($total <= $keep) {
            return 0;
        }
        $threshold_id = TypeCoerce::toInt(db_get_field(
            'SELECT log_id FROM ?:novoton_sync_log ORDER BY sync_date DESC LIMIT 1 OFFSET ?i',
            $keep - 1,
        ));
        if ($threshold_id <= 0) {
            return 0;
        }
        return TypeCoerce::toInt(db_query('DELETE FROM ?:novoton_sync_log WHERE log_id < ?i', $threshold_id));
    }

    /**
     * Get sync statistics
     * @return array<string, array<string, mixed>>
     */
    public function getStats(int $days = 7): array
    {
        $stats = self::asRowList(db_get_array(
            'SELECT sync_type,
                    COUNT(*) as count,
                    SUM(products_total) as total_processed,
                    SUM(products_updated) as total_updated,
                    SUM(products_failed) as total_failed,
                    AVG(duration_seconds) as avg_duration
             FROM ?:novoton_sync_log
             WHERE sync_date > DATE_SUB(NOW(), INTERVAL ?i DAY)
             GROUP BY sync_type',
            $days,
        ));

        $result = [];
        foreach ($stats as $row) {
            $syncType = TypeCoerce::toString($row['sync_type'] ?? '');
            if ($syncType === '') {
                continue;
            }
            $result[$syncType] = $row;
        }
        return $result;
    }
}
