<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\SyncLogRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The dashboard's Recent sync activity: one table row per run, All or only
 * the Problems, CS-Cart paginated.
 *
 * It used to be one tall card per run, and a run that synced 2 of 3 items
 * showed a green COMPLETED. A run now says what happened: Completed, Partly
 * done (with the items not synced counted), Failed (its error, the full text
 * folded away when long), Running, or Never finished.
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
     * One sphinx_sync_log row as the table shows it.
     *
     * @param array<string, mixed> $log
     *
     * @return array{at: string, job: string, mode: string, items: int, not_synced: int, state: string, error: string, error_full: string, rate_limited: int, seconds: float|null}
     */
    public static function row(array $log, int $now): array
    {
        $status = TypeCoerce::toString($log['status'] ?? '');
        $total = TypeCoerce::toInt($log['items_total'] ?? 0);
        $synced = TypeCoerce::toInt($log['items_synced'] ?? 0);
        $at = TypeCoerce::toString($log['started_at'] ?? '');
        $startedAt = $at === '' ? false : strtotime($at);
        $notSynced = in_array($status, ['completed', 'failed'], true) ? max(0, $total - $synced) : 0;

        $state = match (true) {
            $status === 'failed' => 'failed',
            $status === 'completed' && $notSynced > 0 => 'partial',
            $status === 'completed' => 'ok',
            $status === 'started' && $startedAt !== false && $now - $startedAt > self::STALL_AFTER => 'stalled',
            $status === 'started' => 'running',
            default => 'other',
        };

        $error = trim(TypeCoerce::toString($log['error_message'] ?? ''));
        $long = mb_strlen($error) > self::ERROR_SHOWN;
        $ms = TypeCoerce::toInt($log['duration_ms'] ?? 0);

        return [
            'at' => $at,
            'job' => TypeCoerce::toString($log['sync_type'] ?? ''),
            'mode' => TypeCoerce::toString($log['sync_mode'] ?? ''),
            'items' => max($total, $synced),
            'not_synced' => $notSynced,
            'state' => $state,
            'error' => $long ? rtrim(mb_substr($error, 0, self::ERROR_SHOWN - 1)) . '…' : $error,
            'error_full' => $long ? $error : '',
            'rate_limited' => TypeCoerce::toInt($log['rate_limit_hits'] ?? 0),
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
     *     rows: list<array{at: string, job: string, mode: string, items: int, not_synced: int, state: string, error: string, error_full: string, rate_limited: int, seconds: float|null}>,
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
        // A page past the end (the log was cleaned up meanwhile) shows the last one.
        $page = min($ask['page'], max(1, (int) ceil($total / $ask['per_page'])));

        $rows = [];
        foreach ($repo->findPage($page, $ask['per_page'], $ask['problems'], $now) as $log) {
            $rows[] = self::row($log, $now);
        }

        return [
            'rows' => $rows,
            'problems' => $ask['problems'],
            'counts' => $counts,
            'search' => [
                'page' => $page,
                'items_per_page' => $ask['per_page'],
                'total_items' => $total,
                'activity' => $ask['problems'] ? 'problems' : '',
            ],
        ];
    }
}
