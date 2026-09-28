<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Everything the dashboard says about Novoton's scheduled jobs, in one place.
 *
 * The dashboard used to describe the same jobs three times — two
 * "recommended" panels, an XML panel and a 15-row table of URLs — each URL
 * carrying the shared cron key in plain text, with schedules as free text
 * ("Before Add Products") and no word on whether a job had run. This builder
 * gives ONE row per job, in the order the jobs depend on each other, with its
 * schedule, how it last went, and its URL and CLI commands.
 *
 * Free of Tygh, Smarty and the database: the controller hands in what it
 * gathered and gets arrays back, so the order, the schedules, the "late" rule
 * and the crontab text are all unit-tested.
 */
final class CronPlanBuilder
{
    public const string ADDON = 'novoton_holidays';

    /**
     * The order the jobs run in: each stage needs the one before it.
     *
     * Read from each command's prerequisites — hotel info and prices need the
     * hotel list; add_hotels_as_products needs room_price ("run mode=room_price
     * first — it sets has_room_price=Y"); offers_update needs the baseline that
     * add_hotels_as_products records; reassign_features and backfill_images
     * work on linked products.
     *
     * @var array<string, list<string>>
     */
    public const array STAGES = [
        'reference' => ['resort_list', 'list_facilities'],
        'hotels' => ['hotel_list', 'hotel_info_batched', 'hotel_facilities_batched', 'geocode_addresses'],
        'prices' => ['sync_priceinfo_batched', 'compute_prices', 'recompute_calendar_prices', 'room_price'],
        'products' => ['add_hotels_as_products', 'reassign_features', 'backfill_images', 'offers_update'],
        'upkeep' => ['resinfo', 'cleanup'],
    ];

    /** Stage names in the crontab block's comments (the page prints them from labels). */
    private const array STAGE_TITLES = [
        'reference' => 'Reference data',
        'hotels' => 'Hotels',
        'prices' => 'Prices',
        'products' => 'Products',
        'upkeep' => 'Bookings and upkeep',
    ];

    /** The two jobs the old page singled out as "recommended": a badge on their row now. */
    public const array RECOMMENDED = ['hotel_info_batched', 'sync_priceinfo_batched'];

    /** Batched jobs, which also answer status=1, force_full=1 and reset=1. */
    public const array BATCHED = ['hotel_info_batched', 'sync_priceinfo_batched', 'hotel_facilities_batched'];

    /** Jobs that answer status=1 without being batched (they drain a backlog). */
    public const array HAS_STATUS = ['geocode_addresses', 'backfill_images', 'backfill_descriptions'];

    /**
     * Jobs that are real but not part of the schedule: `full` repeats jobs the
     * stages already run, and the alternative-request jobs are driven by
     * bookings. backfill_descriptions is a one-off recovery for products
     * created before descriptions were read correctly. Listed separately,
     * with Run and the commands, no schedule.
     */
    public const array ON_DEMAND = ['full', 'alternative_rs', 'alternative_rs_bookings', 'notify_alternatives', 'expire_requests', 'backfill_descriptions'];

    /** One-hotel diagnostics (they need hotel_id=…): never on the dashboard. */
    public const array DIAGNOSTIC = ['diagnose_features', 'diagnose_image_urls', 'diagnose_hotel_facilities'];

    /**
     * Suggested slot per job, and after how many hours without a finished run
     * it counts as late. Daily times follow the stage order; the jobs that
     * work through a backlog run every few minutes and resume where they
     * stopped.
     *
     * @var array<string, array{cron: string, late_after_hours: int}>
     */
    private const array SCHEDULES = [
        'resort_list' => ['cron' => '0 1 * * 0', 'late_after_hours' => 2 * 168],
        'list_facilities' => ['cron' => '15 1 * * 0', 'late_after_hours' => 2 * 168],
        'hotel_list' => ['cron' => '0 2 */3 * *', 'late_after_hours' => 168],
        'hotel_info_batched' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'hotel_facilities_batched' => ['cron' => '30 2 * * 0', 'late_after_hours' => 2 * 168],
        'geocode_addresses' => ['cron' => '45 * * * *', 'late_after_hours' => 6],
        'sync_priceinfo_batched' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'compute_prices' => ['cron' => '*/5 * * * *', 'late_after_hours' => 2],
        'recompute_calendar_prices' => ['cron' => '30 3 * * *', 'late_after_hours' => 48],
        'room_price' => ['cron' => '0 4 * * *', 'late_after_hours' => 48],
        'add_hotels_as_products' => ['cron' => '30 4 * * *', 'late_after_hours' => 48],
        'reassign_features' => ['cron' => '0 5 * * *', 'late_after_hours' => 48],
        'backfill_images' => ['cron' => '50 * * * *', 'late_after_hours' => 6],
        'offers_update' => ['cron' => '0 */2 * * *', 'late_after_hours' => 6],
        'resinfo' => ['cron' => '15 */2 * * *', 'late_after_hours' => 6],
        'cleanup' => ['cron' => '0 6 * * *', 'late_after_hours' => 48],
    ];

    /**
     * sync_log types a job wrote under before Travel Core's run log existed,
     * so a job that ran last week does not read "no runs yet" on the day this
     * ships. The run log wins whenever it has a record.
     *
     * @var array<string, list<string>>
     */
    private const array LEGACY_LOG_TYPES = [
        'hotel_info_batched' => ['hotel_info_batched', 'hotelinfo'],
        'sync_priceinfo_batched' => ['sync_priceinfo_batched', 'sync_priceinfo'],
        'list_facilities' => ['list_facilities', 'facilities'],
        'hotel_facilities_batched' => ['hotel_facilities_batched', 'hotel_facilities'],
    ];

    /** A run still open after this long is treated as dead (as Travel Core's CronHealth). */
    public const int STALL_AFTER = 6 * 3600;

    private const array DAY_NAMES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @param string $dirRoot the store's DIR_ROOT, so CLI lines work from any
     *                        working directory (a crontab runs from the
     *                        account's home, not the docroot); '' keeps them
     *                        relative
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $accessKey,
        private readonly string $dirRoot = '',
    ) {
    }

    /** FALSE when no cron key is set: the endpoint then refuses everything, so no commands are shown. */
    public function hasKey(): bool
    {
        return trim($this->accessKey) !== '';
    }

    public function url(string $mode): string
    {
        return rtrim($this->baseUrl, '/')
            . '/index.php?dispatch=novoton_cron.run&access_key=' . rawurlencode($this->accessKey)
            . '&mode=' . rawurlencode($mode);
    }

    public function cli(string $mode): string
    {
        $root = $this->dirRoot === '' ? '' : rtrim($this->dirRoot, '/') . '/';

        return 'php ' . $root . 'app/addons/novoton_holidays/cron.php access_key=' . $this->accessKey . ' mode=' . $mode;
    }

    /** The cron expression suggested for a job, '' for one without a slot. */
    public static function cron(string $mode): string
    {
        return self::SCHEDULES[$mode]['cron'] ?? '';
    }

    /**
     * sync_log types to read for a job's last run before the run log had one.
     *
     * @return list<string>
     */
    public static function legacyLogTypes(string $mode): array
    {
        return self::LEGACY_LOG_TYPES[$mode] ?? [$mode];
    }

    /**
     * The scheduled jobs, grouped by stage, that the dispatcher actually has.
     *
     * A stage whose jobs are all missing is dropped. A mode the dispatcher has
     * that this class does not know goes to the on-demand list (see
     * onDemandRows), so a new command is visible, never silently missing.
     *
     * @param array<string, string> $modes dispatcher mode => description
     * @param array<string, array<string, mixed>> $records mode => CronRunLog::get()
     * @param array<string, array{at: int, ok: bool}> $legacy mode => last sync_log row
     *
     * @return list<array{stage: string, number: int, jobs: list<array<string, mixed>>}>
     */
    public function stages(array $modes, array $records, array $legacy, int $now): array
    {
        $out = [];
        $number = 0;
        foreach (self::STAGES as $stage => $jobs) {
            $rows = [];
            foreach ($jobs as $mode) {
                if (!array_key_exists($mode, $modes)) {
                    continue;
                }
                $rows[] = $this->row($mode, $modes[$mode], $records[$mode] ?? [], $legacy[$mode] ?? null, $now);
            }
            if ($rows !== []) {
                $out[] = ['stage' => $stage, 'number' => ++$number, 'jobs' => $rows];
            }
        }

        return $out;
    }

    /**
     * Jobs with no schedule: ON_DEMAND, then any mode this class does not know.
     *
     * @param array<string, string> $modes
     * @param array<string, array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    public function onDemandRows(array $modes, array $records, int $now): array
    {
        $known = array_merge(...array_values(self::STAGES));
        $unknown = array_values(array_diff(array_keys($modes), $known, self::ON_DEMAND, self::DIAGNOSTIC));
        sort($unknown);

        $rows = [];
        foreach ([...self::ON_DEMAND, ...$unknown] as $mode) {
            if (array_key_exists($mode, $modes)) {
                $rows[] = $this->row($mode, $modes[$mode], $records[$mode] ?? [], null, $now);
            }
        }

        return $rows;
    }

    /**
     * The block to paste into the server crontab or cPanel.
     *
     * CLI lines, because a bare URL does not run in a crontab. The URL form
     * lists each address under the time to add it at, as comments, so nothing
     * in it can be pasted into a crontab by mistake.
     *
     * @param array<string, string> $modes
     */
    public function crontab(array $modes, string $format, string $generatedOn = ''): string
    {
        $lines = ['# Novoton Holidays: scheduled jobs, in the order they run'];
        if ($generatedOn !== '') {
            $lines[] = '# generated ' . $generatedOn;
        }
        if ($format === 'url') {
            $lines[] = '# URLs for a cron service: add each one at the time shown';
        }

        $number = 0;
        foreach (self::STAGES as $stage => $jobs) {
            $present = array_values(array_filter($jobs, static fn (string $m): bool => array_key_exists($m, $modes)));
            if ($present === []) {
                continue;
            }
            $lines[] = '# ' . (++$number) . '. ' . self::STAGE_TITLES[$stage];
            foreach ($present as $mode) {
                $cron = self::SCHEDULES[$mode]['cron'];
                if ($format === 'url') {
                    $lines[] = '# ' . $cron;
                    $lines[] = $this->url($mode);
                } else {
                    $lines[] = $cron . '  ' . $this->cli($mode);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A cron expression as words, as a label key plus its placeholders, so the
     * page prints it in the admin's language: "*\/5 * * * *" → every [n] min.
     * Anything this class did not generate comes back as the raw expression.
     *
     * @return array{key: string, params: array<string, string>}
     */
    public static function scheduleWords(string $cron): array
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (!is_array($parts) || count($parts) !== 5) {
            return ['key' => 'novoton_holidays.dash_sched_raw', 'params' => ['[cron]' => $cron]];
        }
        [$minute, $hour, $dom, $mon, $dow] = $parts;

        if ($mon === '*' && $dom === '*' && $dow === '*') {
            if ($hour === '*' && preg_match('/^\*\/(\d+)$/', $minute, $m) === 1) {
                return ['key' => 'novoton_holidays.dash_sched_every_minutes', 'params' => ['[n]' => $m[1]]];
            }
            if ($hour === '*' && ctype_digit($minute)) {
                return ['key' => 'novoton_holidays.dash_sched_hourly', 'params' => ['[minute]' => sprintf('%02d', (int) $minute)]];
            }
            if (ctype_digit($minute) && preg_match('/^\*\/(\d+)$/', $hour, $m) === 1) {
                return ['key' => 'novoton_holidays.dash_sched_every_hours', 'params' => ['[n]' => $m[1], '[minute]' => sprintf('%02d', (int) $minute)]];
            }
            if (ctype_digit($minute) && ctype_digit($hour)) {
                return ['key' => 'novoton_holidays.dash_sched_daily', 'params' => ['[time]' => self::time($hour, $minute)]];
            }
        }
        if ($mon === '*' && $dom === '*' && ctype_digit($dow) && (int) $dow <= 6 && ctype_digit($minute) && ctype_digit($hour)) {
            return ['key' => 'novoton_holidays.dash_sched_weekly_' . self::DAY_NAMES[(int) $dow], 'params' => ['[time]' => self::time($hour, $minute)]];
        }
        if ($mon === '*' && $dow === '*' && preg_match('/^\*\/(\d+)$/', $dom, $m) === 1 && ctype_digit($minute) && ctype_digit($hour)) {
            return ['key' => 'novoton_holidays.dash_sched_every_days', 'params' => ['[n]' => $m[1], '[time]' => self::time($hour, $minute)]];
        }

        return ['key' => 'novoton_holidays.dash_sched_raw', 'params' => ['[cron]' => $cron]];
    }

    /**
     * How a job last went.
     *
     * The run log (Travel Core, every run since it shipped) is the source;
     * the sync_log row is the fallback for a job the run log has not seen yet.
     *
     *   running — started, not finished, within STALL_AFTER
     *   stalled — started, never finished, longer ago than that
     *   failed  — the last run finished with an error
     *   late    — the last run finished longer ago than the job's own interval allows
     *   ok      — finished fine, in time
     *   never   — nothing recorded anywhere
     *
     * @param array<string, mixed> $record CronRunLog::get()
     * @param array{at: int, ok: bool}|null $legacy
     *
     * @return array{state: string, at: int, error: string}
     */
    public static function health(string $mode, array $record, ?array $legacy, int $now): array
    {
        $started = TypeCoerce::toInt($record['started'] ?? 0);
        $finished = TypeCoerce::toInt($record['finished'] ?? 0);
        $error = TypeCoerce::toString($record['error'] ?? '');

        if ($started === 0 && $finished === 0) {
            if ($legacy === null || $legacy['at'] <= 0) {
                return ['state' => 'never', 'at' => 0, 'error' => ''];
            }
            $finished = $legacy['at'];
            $ok = $legacy['ok'];
        } else {
            if ($started > $finished) {
                return ['state' => $now - $started > self::STALL_AFTER ? 'stalled' : 'running', 'at' => $started, 'error' => ''];
            }
            $ok = TypeCoerce::toBool($record['ok'] ?? false);
        }

        if (!$ok) {
            return ['state' => 'failed', 'at' => $finished, 'error' => $error];
        }

        $lateAfter = self::SCHEDULES[$mode]['late_after_hours'] ?? 0;
        if ($lateAfter > 0 && $now - $finished > $lateAfter * 3600) {
            return ['state' => 'late', 'at' => $finished, 'error' => ''];
        }

        return ['state' => 'ok', 'at' => $finished, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $record
     * @param array{at: int, ok: bool}|null $legacy
     *
     * @return array<string, mixed>
     */
    private function row(string $mode, string $description, array $record, ?array $legacy, int $now): array
    {
        $cron = self::SCHEDULES[$mode]['cron'] ?? '';
        $words = $cron === '' ? ['key' => 'novoton_holidays.dash_sched_on_demand', 'params' => []] : self::scheduleWords($cron);
        $health = self::health($mode, $record, $legacy, $now);

        return [
            'mode' => $mode,
            'description' => $description,
            // Jobs this class knows have a name and a description in the
            // language packs; a mode a new command added shows its code and
            // the dispatcher's own description until it gets them.
            'labelled' => in_array($mode, [...array_merge(...array_values(self::STAGES)), ...self::ON_DEMAND], true),
            'recommended' => in_array($mode, self::RECOMMENDED, true),
            'batched' => in_array($mode, self::BATCHED, true),
            'has_status' => in_array($mode, self::BATCHED, true) || in_array($mode, self::HAS_STATUS, true),
            'cron' => $cron,
            'schedule_key' => $words['key'],
            'schedule_params' => $words['params'],
            'state' => $health['state'],
            'at' => $health['at'],
            'error' => $health['error'],
            'url' => $this->url($mode),
            'cli' => $this->cli($mode),
            // A crontab line must be a command; a bare URL is not one.
            'crontab_line' => $cron === '' ? '' : $cron . '  ' . $this->cli($mode),
        ];
    }

    private static function time(string $hour, string $minute): string
    {
        return sprintf('%02d:%02d', (int) $hour, (int) $minute);
    }
}
