<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\SyncLogRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The dashboard's Recent sync activity: one table row per run, from the sync
 * log, All or only the Problems, CS-Cart paginated, as on Sphinx's and
 * Novoton's dashboards.
 *
 * A run says what happened: Completed, Partly done (with the items that
 * failed counted), Failed (its error, the full text folded away when long),
 * Running, or Never finished. Partly done means items FAILED: the log does
 * not count skipped items apart, and skipping is normal here (Hotel details
 * skips the hotels whose details are fresh), so "synced fewer than it looked
 * at" would flag nearly every run.
 */
final class SyncActivity
{
    /** A run still "started" after this long never finished (as Travel Core's CronHealth judges a job). */
    public const int STALL_AFTER = 6 * 3600;

    /** Rows a page unless the pagination asks for another number. */
    public const int PER_PAGE = 10;

    /** Errors longer than this show their start, with the whole text folded under it. */
    public const int ERROR_SHOWN = 160;

    /**
     * What the page asked for: which page, how many rows a page, and whether
     * only the problems (activity=problems).
     *
     * @param array<array-key, mixed> $request
     *
     * @return array{page: int, per_page: int, problems: bool}
     */
    public static function request(array $request): array
    {
        $perPage = TypeCoerce::toInt($request['items_per_page'] ?? self::PER_PAGE);

        return [
            'page' => max(1, TypeCoerce::toInt($request['page'] ?? 1)),
            'per_page' => $perPage >= 1 && $perPage <= 100 ? $perPage : self::PER_PAGE,
            'problems' => TypeCoerce::toString($request['activity'] ?? '') === 'problems',
        ];
    }

    /**
     * One eurosite_sync_log row as the table shows it.
     *
     * @param array<string, mixed> $log
     *
     * @return array{at: string, job: string, synced: int, total: int, failed: int, state: string, error: string, error_full: string, seconds: float|null}
     */
    public static function row(array $log, int $now): array
    {
        $status = TypeCoerce::toString($log['status'] ?? '');
        $at = TypeCoerce::toString($log['started_at'] ?? '');
        $startedAt = $at === '' ? false : strtotime($at);
        $failed = TypeCoerce::toInt($log['items_failed'] ?? 0);

        $state = match (true) {
            $status === 'failed' => 'failed',
            $status === 'completed' && $failed > 0 => 'partial',
            $status === 'completed' => 'ok',
            $status === 'started' && $startedAt !== false && $now - $startedAt > self::STALL_AFTER => 'stalled',
            $status === 'started' => 'running',
            default => 'other',
        };

        $error = trim(TypeCoerce::toString($log['error_message'] ?? ''));
        $long = mb_strlen($error) > self::ERROR_SHOWN;
        $ms = TypeCoerce::toInt($log['duration_ms'] ?? 0);
        $synced = TypeCoerce::toInt($log['items_synced'] ?? 0);

        return [
            'at' => $at,
            'job' => TypeCoerce::toString($log['sync_type'] ?? ''),
            'synced' => $synced,
            'total' => max(TypeCoerce::toInt($log['items_total'] ?? 0), $synced),
            'failed' => $failed,
            'state' => $state,
            'error' => $long ? rtrim(mb_substr($error, 0, self::ERROR_SHOWN - 1)) . '…' : $error,
            'error_full' => $long ? $error : '',
            'seconds' => $ms > 0 ? round($ms / 1000, 1) : null,
        ];
    }

    /**
     * The page the dashboard shows, its counts for the All / Problems
     * buttons, and $search for common/pagination.tpl.
     *
     * @param array<array-key, mixed> $request
     *
     * @return array{
     *     rows: list<array{at: string, job: string, synced: int, total: int, failed: int, state: string, error: string, error_full: string, seconds: float|null}>,
     *     problems: bool,
     *     counts: array{all: int, problems: int},
     *     search: array{page: int, items_per_page: int, total_items: int, activity: string}
     * }
     */
    public static function page(SyncLogRepository $repo, array $request, int $now): array
    {
        $ask = self::request($request);
        $counts = ['all' => $repo->count(false, $now), 'problems' => $repo->count(true, $now)];
        $total = $ask['problems'] ? $counts['problems'] : $counts['all'];
        // A page past the end (the log was trimmed meanwhile) shows the last one.
        $page = min($ask['page'], max(1, (int) ceil($total / $ask['per_page'])));

        $rows = [];
        foreach ($repo->findPage($page, $ask['per_page'], $ask['problems'], $now) as $log) {
            $rows[] = self::row($log, $now);
        }

        return [
            'rows' => $rows,
            'problems' => $ask['problems'],
            'counts' => $counts,
            'search' => self::search($page, $ask['per_page'], $total, $ask['problems']),
        ];
    }

    /**
     * An empty page, for a dashboard whose log could not be read: the card
     * still renders, saying no run is logged.
     *
     * @return array{
     *     rows: list<array{at: string, job: string, synced: int, total: int, failed: int, state: string, error: string, error_full: string, seconds: float|null}>,
     *     problems: bool,
     *     counts: array{all: int, problems: int},
     *     search: array{page: int, items_per_page: int, total_items: int, activity: string}
     * }
     */
    public static function none(): array
    {
        return ['rows' => [], 'problems' => false, 'counts' => ['all' => 0, 'problems' => 0], 'search' => self::search(1, self::PER_PAGE, 0, false)];
    }

    /** @return array{page: int, items_per_page: int, total_items: int, activity: string} */
    private static function search(int $page, int $perPage, int $total, bool $problems): array
    {
        return ['page' => $page, 'items_per_page' => $perPage, 'total_items' => $total, 'activity' => $problems ? 'problems' : ''];
    }
}
