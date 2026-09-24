<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

/**
 * One add-on's cron health, worked out from its CronRunLog records.
 *
 * Pure: records and "now" go in, a verdict comes out — so every rule below is
 * unit-tested without CS-Cart.
 *
 * WHAT IT DOES NOT CLAIM. There is deliberately no "late" verdict per job:
 * only Eurosite declares how often each of its jobs should run, and inventing
 * schedules for Sphinx and Novoton would make the page confidently wrong.
 * What CAN be known without a schedule is below, most serious first.
 *
 *   refused — a request with a WRONG key was refused after the add-on's last
 *             successful scheduled run: its crontab is almost certainly still
 *             on an old key. The one-line diagnosis of a botched rotation.
 *   failed  — a job's latest run finished with an error.
 *   stalled — a job started and never finished (killed, fatal error, timeout).
 *   quiet   — no scheduled run of ANY job for QUIET_AFTER: the crontab is
 *             probably not set up, or not reaching the site.
 *   never   — nothing recorded yet (a store where this feature is new).
 *   ok      — none of the above.
 *
 * Only SCHEDULED runs (cron URL or server crontab) count towards refused and
 * quiet: someone pressing "Sync now" in the admin must not make a crontab
 * that stopped working look healthy.
 */
final class CronHealth
{
    public const string OK = 'ok';
    public const string REFUSED = 'refused';
    public const string FAILED = 'failed';
    public const string STALLED = 'stalled';
    public const string QUIET = 'quiet';
    public const string NEVER = 'never';

    /** A run still open after this long is treated as dead (the longest full syncs take a few hours). */
    public const int STALL_AFTER = 6 * 3600;

    /** No scheduled run of any job for this long means the crontab is not working. */
    public const int QUIET_AFTER = 2 * 86400;

    /** Failures and refusals older than this are history, not a current problem. */
    public const int RELEVANT_FOR = 7 * 86400;

    /**
     * @param array<string, array{started?: int, finished?: int, ok?: bool, error?: string, via?: string}> $records mode => CronRunLog::get()
     *
     * @return array{state: string, failed: list<string>, stalled: list<string>, running: list<string>, last: array{mode: string, at: int, ok: bool}|null, wrong_key_at: int, last_scheduled_ok: int}
     */
    public static function assess(array $records, int $wrongKeyAt, int $now): array
    {
        $failed = [];
        $stalled = [];
        $running = [];
        $last = null;
        $lastScheduledOk = 0;
        $lastScheduledStart = 0;
        $any = false;

        foreach ($records as $mode => $r) {
            $started = $r['started'] ?? 0;
            $finished = $r['finished'] ?? 0;
            if ($started === 0 && $finished === 0) {
                continue;
            }
            $any = true;
            $scheduled = in_array($r['via'] ?? '', ['cli', 'http'], true);
            $mode = (string) $mode;

            if ($started > $finished) {
                // Open run: either still going, or it died.
                if ($now - $started > self::STALL_AFTER) {
                    $stalled[] = $mode;
                } else {
                    $running[] = $mode;
                }
            } elseif (($r['ok'] ?? true) === false && $now - $finished <= self::RELEVANT_FOR) {
                $failed[] = $mode;
            }

            if ($finished > 0 && ($last === null || $finished > $last['at'])) {
                $last = ['mode' => $mode, 'at' => $finished, 'ok' => ($r['ok'] ?? false) === true];
            }
            if ($scheduled) {
                $lastScheduledStart = max($lastScheduledStart, $started);
                if (($r['ok'] ?? false) === true && $finished >= $started) {
                    $lastScheduledOk = max($lastScheduledOk, $finished);
                }
            }
        }

        $refusedNow = $wrongKeyAt > $lastScheduledOk && $now - $wrongKeyAt <= self::RELEVANT_FOR;

        $state = match (true) {
            $refusedNow => self::REFUSED,
            $failed !== [] => self::FAILED,
            $stalled !== [] => self::STALLED,
            !$any => self::NEVER,
            $now - $lastScheduledStart > self::QUIET_AFTER => self::QUIET,
            default => self::OK,
        };

        sort($failed);
        sort($stalled);
        sort($running);

        return [
            'state' => $state,
            'failed' => $failed,
            'stalled' => $stalled,
            'running' => $running,
            'last' => $last,
            'wrong_key_at' => $wrongKeyAt,
            // When a SCHEDULED run last succeeded — how the re-copy checklist
            // knows an add-on's crontab already works with a new key.
            'last_scheduled_ok' => $lastScheduledOk,
        ];
    }

    /**
     * The job names behind a health verdict, as the page's hints print them:
     * "Failed: hotels, circuits".
     *
     * @param array{failed: list<string>, stalled: list<string>, running: list<string>} $health
     *
     * @return array{failed_list: string, stalled_list: string, running_list: string}
     */
    public static function lists(array $health): array
    {
        return [
            'failed_list' => implode(', ', $health['failed']),
            'stalled_list' => implode(', ', $health['stalled']),
            'running_list' => implode(', ', $health['running']),
        ];
    }

    /** TRUE when $state is something an operator should act on. */
    public static function needsAttention(string $state): bool
    {
        return in_array($state, [self::REFUSED, self::FAILED, self::STALLED, self::QUIET], true);
    }
}
