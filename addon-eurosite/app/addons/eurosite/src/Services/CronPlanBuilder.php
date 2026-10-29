<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Everything the dashboard needs to say about scheduled work, in one place.
 *
 * The dashboard used to carry two tables describing the same eight jobs: a
 * catalog table (rows, last sync, "Sync now") and a cron table (mode, URL,
 * schedule). Reading one told you nothing about the other — whether a catalog
 * was stale, whether its job was even scheduled, whether the URL would work.
 * This builder produces ONE row per job, carrying both halves, in the order
 * the pipeline actually runs them.
 *
 * It is deliberately free of Tygh, Smarty and the database: it takes the
 * numbers the controller already gathered and returns arrays, so every rule
 * below — the ordering, the schedule wording, when a catalog counts as
 * overdue, what the crontab looks like — is unit-testable.
 */
final class CronPlanBuilder
{
    /**
     * The order FullSyncCommand runs its steps in. A dashboard that lists jobs
     * alphabetically puts `cleanup` second, which reads like it runs second.
     */
    public const PIPELINE = ['countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags'];

    /**
     * Jobs that are not part of the full pipeline, in the order they belong
     * after it: details and pictures (04:00), then which hotels have an
     * Immediate offer (04:15), then products for them (05:30), then the
     * existing products brought up to date (06:00). The booking-status check
     * runs all day, every 15 minutes.
     */
    public const STANDALONE = ['product_info', 'availability', 'add_products', 'update_products', 'booking_status', 'cleanup'];

    /** One-off, read-only diagnostics: run by hand, never on the dashboard or in the crontab. */
    public const DIAGNOSTIC = ['probe_availability'];

    /**
     * Suggested crontab slot per mode, plus how often it is expected to run.
     *
     * `every_hours` is not decoration: "overdue" means a catalog has not been
     * synced in twice its own interval, which is the only staleness test that
     * means anything when jobs run monthly, weekly and daily side by side.
     * `full` runs each of its steps only when that step's own interval has
     * passed (stepDue()).
     *
     * Eurosite's answers (28 Oct 2026) set them: the static catalogs once a
     * month, the hotel lists weekly, details kept 30 days, availability as
     * often as needed, bulk work between 01:00 and 06:00, and a booking
     * waiting for confirmation checked on their schedule
     * (BookingStatusSchedule).
     *
     * @var array<string, array{cron: string, every_hours: int}>
     */
    private const SCHEDULES = [
        'full' => ['cron' => '0 1 * * *', 'every_hours' => 24],
        'countries' => ['cron' => '0 1 1 * *', 'every_hours' => self::MONTH_HOURS],
        'own_cities' => ['cron' => '30 1 1 * *', 'every_hours' => self::MONTH_HOURS],
        'cities' => ['cron' => '0 2 1 * *', 'every_hours' => self::MONTH_HOURS],
        'hotels' => ['cron' => '0 3 * * 0', 'every_hours' => 168],
        'room_types' => ['cron' => '30 3 1 * *', 'every_hours' => self::MONTH_HOURS],
        'tags' => ['cron' => '45 3 1 * *', 'every_hours' => self::MONTH_HOURS],
        'product_info' => ['cron' => '0 4 * * *', 'every_hours' => 24],
        'availability' => ['cron' => '15 4 * * *', 'every_hours' => 24],
        'add_products' => ['cron' => '30 5 * * *', 'every_hours' => 24],
        'update_products' => ['cron' => '0 6 * * *', 'every_hours' => 24],
        'booking_status' => ['cron' => '*/15 * * * *', 'every_hours' => 1],
        'cleanup' => ['cron' => '0 5 * * 0', 'every_hours' => 168],
    ];

    /** A monthly job's interval: the longest month. */
    private const MONTH_HOURS = 31 * 24;

    /** How early `full` may run a weekly step: its last run may have ended a little after 01:00. */
    private const STEP_SLACK = 3 * 3600;

    private const FALLBACK = ['cron' => '0 4 * * *', 'every_hours' => 24];

    private const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /**
     * @param string $dirRoot the store's DIR_ROOT, so CLI lines work from any
     *                        working directory (a crontab or cPanel job runs
     *                        from the account's home, not the docroot); ''
     *                        keeps them relative
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $accessKey,
        private readonly string $dirRoot = '',
    ) {
    }

    /**
     * FALSE when no cron access key is configured.
     *
     * The endpoint answers 403 to everything in that state, so the dashboard
     * must not print commands: nine URLs that cannot work are worse than none.
     */
    public function hasKey(): bool
    {
        return trim($this->accessKey) !== '';
    }

    /**
     * TRUE when this mode has a slot of its own rather than the fallback.
     *
     * A new command registering a mode nobody scheduled still gets a row (see
     * rows()), but it lands on the generic nightly slot — which is fine for a
     * day and wrong as a habit. The dashboard's contract test uses this to say
     * so out loud; asking "is its cron expression the fallback string?" cannot,
     * because a real slot is allowed to equal it.
     */
    public static function hasSchedule(string $mode): bool
    {
        return isset(self::SCHEDULES[$mode]);
    }

    /**
     * Whether `full` runs this step now: it never finished well, or its own
     * interval has passed since. A monthly step runs once a calendar month
     * (on the 1st with the nightly `full`), a weekly one once its 7 days are
     * up, give or take STEP_SLACK.
     *
     * @param int|null $lastOkAt when the step last finished well (unix time)
     */
    public static function stepDue(string $mode, ?int $lastOkAt, int $now): bool
    {
        if ($lastOkAt === null || $lastOkAt <= 0) {
            return true;
        }
        $hours = (self::SCHEDULES[$mode] ?? self::FALLBACK)['every_hours'];
        if ($hours >= self::MONTH_HOURS) {
            return date('Y-m', $lastOkAt) !== date('Y-m', $now);
        }

        return $now - $lastOkAt >= $hours * 3600 - self::STEP_SLACK;
    }

    public function url(string $mode): string
    {
        return rtrim($this->baseUrl, '/')
            . '/index.php?dispatch=eurosite_cron.run&access_key=' . rawurlencode($this->accessKey)
            . '&cron_mode=' . rawurlencode($mode);
    }

    public function cli(string $mode): string
    {
        $root = $this->dirRoot === '' ? '' : rtrim($this->dirRoot, '/') . '/';

        return 'php ' . $root . 'app/addons/eurosite/cron.php access_key=' . $this->accessKey . ' mode=' . $mode;
    }

    /**
     * One row per job, pipeline order first, then the standalone jobs, then
     * anything a new command registered that this class does not know about
     * (so a new mode still appears, with the fallback slot).
     *
     * @param array<string, string> $modes dispatcher mode => description
     * @param array<string, int> $counts catalog key => row count
     * @param array<string, array<string, mixed>> $lastSyncs sync_type => last log row
     * @param int|null $now unix time; injectable so the relative wording is testable
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $modes, array $counts, array $lastSyncs, ?int $now = null): array
    {
        $now ??= time();
        $rows = [];

        foreach ($this->orderedModes($modes) as $mode) {
            $schedule = self::SCHEDULES[$mode] ?? self::FALLBACK;
            $last = $lastSyncs[$mode] ?? null;
            $health = $this->health($last, $schedule['every_hours'], $now);

            $rows[] = [
                'mode' => $mode,
                'description' => TypeCoerce::toString($modes[$mode] ?? ''),
                'count' => $counts[$this->countKey($mode)] ?? null,
                'in_pipeline' => in_array($mode, self::PIPELINE, true),
                'schedule_cron' => $schedule['cron'],
                'schedule_human' => self::humanSchedule($schedule['cron']),
                'last' => $last,
                'state' => $health['state'],
                'state_label' => $health['label'],
                'url' => $this->url($mode),
                'cli' => $this->cli($mode),
                // A crontab line must be a command; a bare URL is not one.
                'crontab_line' => $schedule['cron'] . '  ' . $this->cli($mode),
            ];
        }

        return $rows;
    }

    /**
     * A crontab block ready to paste into the server crontab or cPanel.
     *
     * Two plans, because listing both a nightly `full` and a per-catalog
     * schedule — which the old page effectively did — leaves the operator to
     * guess which set to install, and installing both runs everything twice.
     *
     * @param array<string, string> $modes
     */
    public function crontab(string $plan, string $format, array $modes, string $generatedOn = ''): string
    {
        $perCatalog = $plan === 'per';
        $lines = [
            $perCatalog
                ? '# Eurosite Touring — per-catalog schedule'
                : '# Eurosite Touring — nightly full pipeline (each step only when due: catalogs monthly, hotels weekly)',
        ];
        if ($generatedOn !== '') {
            $lines[] = '# generated ' . $generatedOn;
        }

        if ($format !== 'cli') {
            // The URL form is for a cron service or the browser, not for a
            // crontab: a bare URL does not run there. Each address is listed
            // under the slot to schedule it at, as a comment, so nothing in
            // this block can be pasted into a crontab by mistake and fail.
            $lines[] = '# URLs for a cron service: add each one at the time shown';
            foreach ($this->plannedModes($plan, $modes) as $mode) {
                $schedule = self::SCHEDULES[$mode] ?? self::FALLBACK;
                $lines[] = '# ' . $schedule['cron'] . ' (' . self::humanSchedule($schedule['cron']) . ')';
                $lines[] = $this->url($mode);
            }

            return implode("\n", $lines);
        }

        foreach ($this->plannedModes($plan, $modes) as $mode) {
            $schedule = self::SCHEDULES[$mode] ?? self::FALLBACK;
            $lines[] = $schedule['cron'] . '  ' . $this->cli($mode);
        }

        return implode("\n", $lines);
    }

    /**
     * The modes a plan schedules.
     *
     * `full` covers the pipeline, so the nightly plan schedules it once and
     * then only the jobs the pipeline does NOT include.
     *
     * @param array<string, string> $modes
     *
     * @return list<string>
     */
    public function plannedModes(string $plan, array $modes): array
    {
        $known = array_keys($modes);
        $out = [];

        if ($plan === 'per') {
            foreach ($this->orderedModes($modes) as $mode) {
                $out[] = $mode;
            }

            return $out;
        }

        if (in_array('full', $known, true)) {
            $out[] = 'full';
        }
        foreach ($this->orderedModes($modes) as $mode) {
            if (!in_array($mode, self::PIPELINE, true)) {
                $out[] = $mode;
            }
        }

        return $out;
    }

    /**
     * "0 2 * * 0" -> "Sun 02:00"; "0 3 * * *" -> "Daily 03:00";
     * "0 1 1 * *" -> "Monthly 1st 01:00"; "*\/15 * * * *" -> "Every 15 min".
     *
     * Only the shapes this class generates are translated; anything else is
     * handed back untouched rather than mistranslated.
     */
    public static function humanSchedule(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (!is_array($parts) || count($parts) !== 5) {
            return $cron;
        }

        [$minute, $hour, $dom, $mon, $dow] = $parts;
        if ($hour === '*' && $dom === '*' && $mon === '*' && $dow === '*' && preg_match('/^\*\/(\d+)$/', $minute, $m) === 1) {
            return 'Every ' . $m[1] . ' min';
        }
        if (!ctype_digit($minute) || !ctype_digit($hour) || $mon !== '*') {
            return $cron;
        }

        $time = sprintf('%02d:%02d', (int) $hour, (int) $minute);

        if ($dom === '*' && ctype_digit($dow) && (int) $dow <= 6) {
            return self::DAY_NAMES[(int) $dow] . ' ' . $time;
        }
        if ($dom === '*' && $dow === '*') {
            return 'Daily ' . $time;
        }
        if (ctype_digit($dom) && (int) $dom >= 1 && (int) $dom <= 31 && $dow === '*') {
            return 'Monthly ' . self::ordinal((int) $dom) . ' ' . $time;
        }

        return $cron;
    }

    /** 1 -> "1st", 2 -> "2nd", 11 -> "11th", 23 -> "23rd". */
    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return $n . $suffix;
    }

    /**
     * Pipeline order, then standalone jobs, then unknown modes alphabetically.
     * `full` is never a row: it is the dashboard's headline action, not a
     * catalog.
     *
     * @param array<string, string> $modes
     *
     * @return list<string>
     */
    private function orderedModes(array $modes): array
    {
        $known = array_keys($modes);
        $ordered = [];

        foreach ([...self::PIPELINE, ...self::STANDALONE] as $mode) {
            if (in_array($mode, $known, true)) {
                $ordered[] = $mode;
            }
        }

        $rest = array_diff($known, $ordered, ['full'], self::DIAGNOSTIC);
        sort($rest);

        return [...$ordered, ...$rest];
    }

    /**
     * Which `$counts` key holds this mode's row count. The cache catalog is
     * counted under 'cache' but synced under 'product_info'; the availability
     * check shows the Immediate hotels, the product jobs the products.
     */
    private function countKey(string $mode): string
    {
        return match ($mode) {
            'product_info' => 'cache',
            'availability' => 'immediate',
            'add_products', 'update_products' => 'products',
            default => $mode,
        };
    }

    /**
     * How a job is doing, as a state plus the words to show for it.
     *
     * @param array<string, mixed>|null $last
     *
     * @return array{state: string, label: string}
     */
    private function health(?array $last, int $everyHours, int $now): array
    {
        if ($last === null || $last === []) {
            return ['state' => 'never', 'label' => 'never run'];
        }

        $status = TypeCoerce::toString($last['status'] ?? '');
        $startedAt = TypeCoerce::toString($last['started_at'] ?? '');
        $ts = $startedAt === '' ? false : strtotime($startedAt);
        $ago = $ts === false ? '' : self::relative($now - $ts);

        if ($status === 'failed') {
            return ['state' => 'failed', 'label' => $ago === '' ? 'failed' : 'failed ' . $ago];
        }
        if ($ts !== false && ($now - $ts) > $everyHours * 3600 * 2) {
            return ['state' => 'overdue', 'label' => $ago];
        }
        if ($status !== 'completed') {
            return ['state' => 'running', 'label' => $status === '' ? 'unknown' : $status];
        }

        return ['state' => 'ok', 'label' => $ago === '' ? 'completed' : $ago];
    }

    /** "6h ago", "2d ago" — a timestamp alone makes you do the arithmetic. */
    private static function relative(int $seconds): string
    {
        if ($seconds < 0) {
            return 'just now';
        }
        if ($seconds < 90) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return (int) round($seconds / 60) . 'm ago';
        }
        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . 'h ago';
        }

        return (int) floor($seconds / 86400) . 'd ago';
    }
}
