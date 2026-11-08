<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A provider's scheduled jobs, as its dashboard shows them: one row per job,
 * in the order the jobs depend on each other, each with its schedule in
 * words, how it last went, and its URL, CLI and crontab commands.
 *
 * WHY IT IS HERE. Novoton built this table for its dashboard (job rows, the
 * ⋯ menu, Run as an admin action, commands shown with the key masked), and
 * Sphinx and Eurosite need the same one. Kept once in Travel Core, with the
 * templates that draw it (components/jobs_card.tpl, job_row.tpl,
 * run_job.tpl, job_commands.tpl) and js/addons/travel_core/cron-jobs.js, a
 * fix reaches every dashboard at once.
 *
 * HOW A PROVIDER USES IT. It extends this class and lists its jobs in the
 * constants below (Novoton: CronPlanBuilder): the stages in running order,
 * each job's slot and when it counts as late, which extra actions a job
 * answers, which jobs run only on demand. The rows name the provider's own
 * labels (LABEL_PREFIX . 'job_<mode>', 'job_<mode>_desc', 'stage_<stage>');
 * the words for schedules and run states are Travel Core's
 * (travel_core.jobs_*).
 *
 * Free of Tygh, Smarty and the database: the controller hands in what it
 * gathered (the dispatcher's modes, the run log, legacy sync_log rows) and
 * gets arrays back, so the order, the schedules, the "late" rule and the
 * crontab text are all unit-tested.
 */
abstract class CronJobPlan
{
    /** The add-on whose run log (CronRunLog) the rows read, e.g. 'novoton_holidays'. */
    public const string ADDON = '';

    /** Named on the first line of the crontab block: "# <TITLE>: scheduled jobs, in the order they run". */
    protected const string TITLE = '';

    /** The add-on's public cron endpoint, e.g. 'novoton_cron.run'. */
    protected const string CRON_DISPATCH = '';

    /** The endpoint's parameter naming the job: 'mode' for Novoton, 'cron_mode' for Sphinx. */
    protected const string URL_MODE_PARAM = 'mode';

    /** The CLI entry point, from the store root, e.g. 'app/addons/novoton_holidays/cron.php'. */
    protected const string CLI_SCRIPT = '';

    /** Prefix of the add-on's job and stage labels, e.g. 'novoton_holidays.dash_'. */
    protected const string LABEL_PREFIX = '';

    /**
     * The order the jobs run in: each stage needs the one before it.
     *
     * @var array<string, list<string>>
     */
    public const array STAGES = [];

    /**
     * Stage names in the crontab block's comments (the page prints them from labels).
     *
     * @var array<string, string>
     */
    protected const array STAGE_TITLES = [];

    /**
     * Each scheduled job's slot, and after how many hours without a finished
     * run it counts as late.
     *
     * @var array<string, array{cron: string, late_after_hours: int}>
     */
    protected const array SCHEDULES = [];

    /**
     * Jobs singled out with a "Recommended" badge on their row.
     *
     * @var list<string>
     */
    public const array RECOMMENDED = [];

    /**
     * Batched jobs, which also answer status=1, force_full=1 and reset=1.
     *
     * @var list<string>
     */
    public const array BATCHED = [];

    /**
     * Jobs that answer status=1 without being batched (they drain a backlog).
     *
     * @var list<string>
     */
    public const array HAS_STATUS = [];

    /**
     * Backlog jobs that answer force=1: redo every item, also the ones already done.
     *
     * @var list<string>
     */
    public const array REDO_ALL = [];

    /**
     * Real jobs with no place in the schedule: listed after the stages, with Run and the commands.
     *
     * @var list<string>
     */
    public const array ON_DEMAND = [];

    /**
     * One-item diagnostics (they need an id): never on the dashboard.
     *
     * @var list<string>
     */
    public const array DIAGNOSTIC = [];

    /**
     * sync_log types a job wrote under before Travel Core's run log existed,
     * so a job that ran last week does not read "no runs yet" on the day its
     * dashboard ships. The run log wins whenever it has a record.
     *
     * @var array<string, list<string>>
     */
    protected const array LEGACY_LOG_TYPES = [];

    /** A run still open after this long is treated as dead (as CronHealth). */
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
    final public function hasKey(): bool
    {
        return trim($this->accessKey) !== '';
    }

    final public function url(string $mode): string
    {
        return rtrim($this->baseUrl, '/')
            . '/index.php?dispatch=' . static::CRON_DISPATCH . '&access_key=' . rawurlencode($this->accessKey)
            . '&' . static::URL_MODE_PARAM . '=' . rawurlencode($mode);
    }

    final public function cli(string $mode): string
    {
        $root = $this->dirRoot === '' ? '' : rtrim($this->dirRoot, '/') . '/';

        return 'php ' . $root . static::CLI_SCRIPT . ' access_key=' . $this->accessKey . ' mode=' . $mode;
    }

    /** The cron expression suggested for a job, '' for one without a slot. */
    final public static function cron(string $mode): string
    {
        return static::SCHEDULES[$mode]['cron'] ?? '';
    }

    /**
     * sync_log types to read for a job's last run before the run log had one.
     *
     * @return list<string>
     */
    final public static function legacyLogTypes(string $mode): array
    {
        return static::LEGACY_LOG_TYPES[$mode] ?? [$mode];
    }

    /**
     * The scheduled jobs, grouped by stage, that the dispatcher actually has.
     *
     * A stage whose jobs are all missing is dropped. A mode the dispatcher has
     * that the plan does not know goes to the on-demand list (see
     * onDemandRows), so a new command is visible, never silently missing.
     *
     * @param array<string, string> $modes dispatcher mode => description
     * @param array<string, array<string, mixed>> $records mode => CronRunLog::get()
     * @param array<string, array{at: int, ok: bool}> $legacy mode => last sync_log row
     *
     * @return list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}>
     */
    final public function stages(array $modes, array $records, array $legacy, int $now): array
    {
        $out = [];
        $number = 0;
        foreach (static::STAGES as $stage => $jobs) {
            $rows = [];
            foreach ($jobs as $mode) {
                if (!array_key_exists($mode, $modes)) {
                    continue;
                }
                $rows[] = $this->row($mode, $modes[$mode], $records[$mode] ?? [], $legacy[$mode] ?? null, $now);
            }
            if ($rows !== []) {
                $out[] = ['stage' => $stage, 'number' => ++$number, 'title_key' => static::LABEL_PREFIX . 'stage_' . $stage, 'jobs' => $rows];
            }
        }

        return $out;
    }

    /**
     * Jobs with no schedule: ON_DEMAND, then any mode the plan does not know.
     *
     * @param array<string, string> $modes
     * @param array<string, array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    final public function onDemandRows(array $modes, array $records, int $now): array
    {
        $unknown = array_values(array_diff(array_keys($modes), self::staged(), static::ON_DEMAND, static::DIAGNOSTIC));
        sort($unknown);

        $rows = [];
        foreach ([...static::ON_DEMAND, ...$unknown] as $mode) {
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
    final public function crontab(array $modes, string $format, string $generatedOn = ''): string
    {
        $lines = ['# ' . static::TITLE . ': scheduled jobs, in the order they run'];
        if ($generatedOn !== '') {
            $lines[] = '# generated ' . $generatedOn;
        }
        if ($format === 'url') {
            $lines[] = '# URLs for a cron service: add each one at the time shown';
        }

        $number = 0;
        foreach (static::STAGES as $stage => $jobs) {
            $present = array_values(array_filter($jobs, static fn (string $m): bool => array_key_exists($m, $modes)));
            if ($present === []) {
                continue;
            }
            $lines[] = '# ' . (++$number) . '. ' . (static::STAGE_TITLES[$stage] ?? $stage);
            foreach ($present as $mode) {
                $cron = static::cron($mode);
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
    final public static function scheduleWords(string $cron): array
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (!is_array($parts) || count($parts) !== 5) {
            return ['key' => 'travel_core.jobs_sched_raw', 'params' => ['[cron]' => $cron]];
        }
        [$minute, $hour, $dom, $mon, $dow] = $parts;

        if ($mon === '*' && $dom === '*' && $dow === '*') {
            if ($hour === '*' && preg_match('/^\*\/(\d+)$/', $minute, $m) === 1) {
                return ['key' => 'travel_core.jobs_sched_every_minutes', 'params' => ['[n]' => $m[1]]];
            }
            if ($hour === '*' && ctype_digit($minute)) {
                return ['key' => 'travel_core.jobs_sched_hourly', 'params' => ['[minute]' => sprintf('%02d', (int) $minute)]];
            }
            if (ctype_digit($minute) && preg_match('/^\*\/(\d+)$/', $hour, $m) === 1) {
                return ['key' => 'travel_core.jobs_sched_every_hours', 'params' => ['[n]' => $m[1], '[minute]' => sprintf('%02d', (int) $minute)]];
            }
            if (ctype_digit($minute) && ctype_digit($hour)) {
                return ['key' => 'travel_core.jobs_sched_daily', 'params' => ['[time]' => self::time($hour, $minute)]];
            }
            if (ctype_digit($minute) && preg_match('/^(\d+),(\d+)$/', $hour, $m) === 1) {
                return ['key' => 'travel_core.jobs_sched_twice_daily', 'params' => ['[time]' => self::time($m[1], $minute), '[time2]' => self::time($m[2], $minute)]];
            }
        }
        if ($mon === '*' && $dom === '*' && ctype_digit($dow) && (int) $dow <= 6 && ctype_digit($minute) && ctype_digit($hour)) {
            return ['key' => 'travel_core.jobs_sched_weekly_' . self::DAY_NAMES[(int) $dow], 'params' => ['[time]' => self::time($hour, $minute)]];
        }
        if ($mon === '*' && $dow === '*' && preg_match('/^\*\/(\d+)$/', $dom, $m) === 1 && ctype_digit($minute) && ctype_digit($hour)) {
            return ['key' => 'travel_core.jobs_sched_every_days', 'params' => ['[n]' => $m[1], '[time]' => self::time($hour, $minute)]];
        }

        return ['key' => 'travel_core.jobs_sched_raw', 'params' => ['[cron]' => $cron]];
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
    final public static function health(string $mode, array $record, ?array $legacy, int $now): array
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

        $lateAfter = static::SCHEDULES[$mode]['late_after_hours'] ?? 0;
        if ($lateAfter > 0 && $now - $finished > $lateAfter * 3600) {
            return ['state' => 'late', 'at' => $finished, 'error' => ''];
        }

        return ['state' => 'ok', 'at' => $finished, 'error' => ''];
    }

    /**
     * Every job the stages name, in running order.
     *
     * @return list<string>
     */
    private static function staged(): array
    {
        return array_merge(...array_values(static::STAGES));
    }

    /**
     * @param array<string, mixed> $record
     * @param array{at: int, ok: bool}|null $legacy
     *
     * @return array<string, mixed>
     */
    private function row(string $mode, string $description, array $record, ?array $legacy, int $now): array
    {
        $cron = static::cron($mode);
        $words = $cron === '' ? ['key' => 'travel_core.jobs_sched_on_demand', 'params' => []] : self::scheduleWords($cron);
        $health = static::health($mode, $record, $legacy, $now);

        return [
            'mode' => $mode,
            'description' => $description,
            // Jobs the plan knows have a name and a description in the
            // add-on's language packs; a mode a new command added shows its
            // code and the dispatcher's own description until it gets them.
            'labelled' => in_array($mode, [...self::staged(), ...static::ON_DEMAND], true),
            'name_key' => static::LABEL_PREFIX . 'job_' . $mode,
            'desc_key' => static::LABEL_PREFIX . 'job_' . $mode . '_desc',
            'recommended' => in_array($mode, static::RECOMMENDED, true),
            'batched' => in_array($mode, static::BATCHED, true),
            'has_status' => in_array($mode, static::BATCHED, true) || in_array($mode, static::HAS_STATUS, true),
            'redo_all' => in_array($mode, static::REDO_ALL, true),
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
