<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * When each travel add-on's cron jobs last ran, how that went, and when a
 * request was last refused for a wrong key.
 *
 * WHY IT EXISTS. The Tools page answers "is every scheduled job running?" for
 * all travel add-ons at once. Before this, only Eurosite recorded every run;
 * Sphinx recorded 7 of its 28 job types, Novoton wrote rows under names that
 * did not match its modes, and a request refused for a wrong key — the exact
 * symptom of a crontab left on an old key after a rotation — was recorded
 * nowhere readable for Eurosite or Sphinx. This is one record, written from
 * the one place every job passes through: each provider's
 * CronDispatcher::dispatch() (via record()) and each cron entry point's
 * refusal (via refused()).
 *
 * STORAGE. ?:storage_data, one key per (addon, mode), NOT a table and NOT one
 * blob per addon:
 *   - not a table, because a new travel_core table is created by a schema
 *     heal, and on older CS-Cart cores that heal can be skipped entirely
 *     (init.php runs before func.php there); storage_data always exists;
 *   - not one blob per addon, because two jobs of the same add-on finishing
 *     at once would read-modify-write the same value and one would be lost.
 *     A mode cannot overlap itself — CronRunLock already prevents that — so a
 *     key per mode has no race.
 *
 * NEVER THROWS. This runs inside every scheduled sync. A logging failure must
 * never be the reason a sync does not run, or reports failure: every write is
 * guarded, and record() re-throws the job's own exception untouched.
 */
final class CronRunLog
{
    /** A refusal is written at most this often per add-on (a flood must not become a write per request). */
    public const int REFUSAL_THROTTLE = 60;

    private const string PREFIX = 'travel_cron_run.';

    /** @var null|\Closure(string): (string|null) test seam; production uses fn_get_storage_data */
    private static ?\Closure $reader = null;

    /** @var null|\Closure(string, string): void test seam; production uses fn_set_storage_data */
    private static ?\Closure $writer = null;

    /** @var null|\Closure(): int test seam for the clock */
    private static ?\Closure $clock = null;

    /**
     * Run $job, recording that it started and how it finished.
     *
     * Returns the job's own result unchanged; re-throws its exception
     * unchanged after recording the failure. The job's `success` flag is the
     * outcome — every dispatcher returns one.
     *
     * @template T of array
     *
     * @param callable(): T $job
     *
     * @return T
     */
    public static function record(string $addon, string $mode, callable $job): array
    {
        $via = self::via();
        self::write($addon, $mode, ['started' => self::now(), 'via' => $via]);

        try {
            $result = $job();
        } catch (\Throwable $e) {
            self::write($addon, $mode, [
                'finished' => self::now(),
                'ok' => false,
                'error' => self::clip($e->getMessage()),
                'via' => $via,
            ]);

            throw $e;
        }

        // Read through outcome(), not $result[...] here: offset checks would
        // narrow $result away from T, and the job's own result goes back
        // to the caller untouched.
        [$ok, $error] = self::outcome($result);
        self::write($addon, $mode, [
            'finished' => self::now(),
            'ok' => $ok,
            'error' => self::clip($error),
            'via' => $via,
        ]);

        return $result;
    }

    /**
     * Note a refused cron request.
     *
     * $keyGiven separates the two refusals that mean different things: a
     * WRONG key is what a crontab still on the old key sends after a
     * rotation; NO key is usually a probe of the URL. Only the first is shown
     * as a problem.
     */
    public static function refused(string $addon, bool $keyGiven): void
    {
        if ($addon === '') {
            return;
        }

        $name = $keyGiven ? '_refused_wrong_key' : '_refused_no_key';
        $last = TypeCoerce::toInt(self::read($addon, $name)['at'] ?? 0);
        if ($last > self::now() - self::REFUSAL_THROTTLE) {
            return;
        }

        self::put(self::PREFIX . $addon . '.' . $name, ['at' => self::now()]);
    }

    /**
     * The stored record of one mode: started / finished / ok / error / via.
     *
     * @return array{started?: int, finished?: int, ok?: bool, error?: string, via?: string}
     */
    public static function get(string $addon, string $mode): array
    {
        $raw = self::read($addon, $mode);

        $out = [];
        if (isset($raw['started'])) {
            $out['started'] = TypeCoerce::toInt($raw['started']);
        }
        if (isset($raw['finished'])) {
            $out['finished'] = TypeCoerce::toInt($raw['finished']);
        }
        if (isset($raw['ok'])) {
            $out['ok'] = TypeCoerce::toBool($raw['ok']);
        }
        if (isset($raw['error'])) {
            $out['error'] = TypeCoerce::toString($raw['error']);
        }
        if (isset($raw['via'])) {
            $out['via'] = TypeCoerce::toString($raw['via']);
        }

        return $out;
    }

    /** When a wrong-key request was last refused for $addon, 0 if never. */
    public static function lastWrongKeyRefusal(string $addon): int
    {
        return TypeCoerce::toInt(self::read($addon, '_refused_wrong_key')['at'] ?? 0);
    }

    /**
     * Replace storage and clock — tests only.
     *
     * @param null|\Closure(string): (string|null) $reader
     * @param null|\Closure(string, string): void $writer
     * @param null|\Closure(): int $clock
     */
    public static function useStorage(?\Closure $reader, ?\Closure $writer, ?\Closure $clock = null): void
    {
        self::$reader = $reader;
        self::$writer = $writer;
        self::$clock = $clock;
    }

    /**
     * How this run was started: 'cli' (server crontab), 'http' (a cron URL)
     * or 'admin' (someone pressed a button). Health looks at scheduled runs
     * only — an admin clicking "Sync now" must not make a broken crontab look
     * healthy.
     */
    private static function via(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }

        return defined('AREA') && AREA === 'A' ? 'admin' : 'http';
    }

    /** @param array<string, bool|int|string> $fields */
    private static function write(string $addon, string $mode, array $fields): void
    {
        self::put(self::PREFIX . $addon . '.' . $mode, $fields + self::read($addon, $mode));
    }

    /**
     * A job result's success flag and error text. The providers' results
     * say 'error' or 'message', depending on the add-on.
     *
     * @param array<mixed> $result
     *
     * @return array{bool, string}
     */
    private static function outcome(array $result): array
    {
        $ok = TypeCoerce::toBool($result['success'] ?? false);

        return [$ok, $ok ? '' : TypeCoerce::toString($result['error'] ?? ($result['message'] ?? ''))];
    }

    /** @return array<mixed> */
    private static function read(string $addon, string $mode): array
    {
        try {
            $raw = self::$reader !== null
                ? (self::$reader)(self::PREFIX . $addon . '.' . $mode)
                : (function_exists('fn_get_storage_data') ? fn_get_storage_data(self::PREFIX . $addon . '.' . $mode) : null);
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<mixed> $value */
    private static function put(string $key, array $value): void
    {
        try {
            $json = (string) json_encode($value);
            if (self::$writer !== null) {
                (self::$writer)($key, $json);
            } elseif (function_exists('fn_set_storage_data')) {
                fn_set_storage_data($key, $json);
            }
        } catch (\Throwable) {
            // Never let the log break the job it is logging.
        }
    }

    private static function now(): int
    {
        return self::$clock !== null ? (self::$clock)() : time();
    }

    private static function clip(string $text): string
    {
        return mb_substr(trim($text), 0, 300);
    }
}
