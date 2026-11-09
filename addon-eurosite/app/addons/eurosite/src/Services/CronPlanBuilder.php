<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Cron\CronJobPlan;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite's scheduled jobs, as its dashboard shows them on Travel Core's
 * shared jobs card: one row per job, in the order they depend on each other,
 * each with its schedule in words, how it last went, Run and its commands.
 *
 * The rows, the schedule words, the "late" rule and the menus are Travel
 * Core's (CronJobPlan); this class lists Eurosite's jobs. What is its own:
 *
 *  - two plans for the crontab (crontabFor()): the catalogs through one
 *    nightly `full` line, each only when it is due (stepDue(), what
 *    FullSyncCommand asks), or every catalog on its own line. Never both:
 *    that runs every catalog twice;
 *  - the size of each job's catalog, for its row (withCounts());
 *  - the flags a run from the dashboard passes on (runParams()).
 *
 * Free of Tygh, Smarty and the database, so every rule is unit-tested.
 */
final class CronPlanBuilder extends CronJobPlan
{
    public const string ADDON = 'eurosite';

    protected const string TITLE = 'Eurosite Touring';

    protected const string CRON_DISPATCH = 'eurosite_cron.run';

    protected const string URL_MODE_PARAM = 'cron_mode';

    protected const string CLI_SCRIPT = 'app/addons/eurosite/cron.php';

    protected const string LABEL_PREFIX = 'eurosite.jobs_';

    /** The catalogs `full` runs, in its order (FullSyncCommand::SEQUENCE). */
    public const array PIPELINE = ['countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags', 'package_routes'];

    /**
     * The order the jobs run in: the catalogs (01:00–03:50, `full` first, as
     * the one line that runs them), then each hotel's details and which
     * hotels have an Immediate offer (04:00, 04:15), then the products for
     * them (05:30, 06:00), then the bookings, every 15 minutes, and the
     * weekly clean-up.
     *
     * @var array<string, list<string>>
     */
    public const array STAGES = [
        'catalogs' => ['full', ...self::PIPELINE],
        'hotels' => ['product_info', 'availability'],
        'products' => ['add_products', 'update_products'],
        'upkeep' => ['booking_status', 'cleanup'],
    ];

    /** @var array<string, string> */
    protected const array STAGE_TITLES = [
        'catalogs' => 'Catalogs',
        'hotels' => 'Hotel details and availability',
        'products' => 'Products',
        'upkeep' => 'Bookings and clean-up',
    ];

    /** A monthly job's interval: the longest month. */
    private const int MONTH_HOURS = 31 * 24;

    /**
     * Each job's slot, how often it is meant to run, and when it counts as
     * late: after twice its own interval, the rule this dashboard always
     * had. `full` is never late itself: each catalog it runs is measured on
     * its own row, whichever plan runs it.
     *
     * Eurosite's answers (28 Oct 2026) set them: the static catalogs once a
     * month, the hotel lists weekly (and the package routes with them: the
     * spec only asks for them "now and then"), details kept 30 days, availability as
     * often as needed, bulk work between 01:00 and 06:00, and a booking
     * waiting for confirmation checked on their schedule
     * (BookingStatusSchedule).
     *
     * @var array<string, array{cron: string, late_after_hours: int, every_hours: int}>
     */
    protected const array SCHEDULES = [
        'full' => ['cron' => '0 1 * * *', 'every_hours' => 24, 'late_after_hours' => 0],
        'countries' => ['cron' => '0 1 1 * *', 'every_hours' => self::MONTH_HOURS, 'late_after_hours' => 2 * self::MONTH_HOURS],
        'own_cities' => ['cron' => '30 1 1 * *', 'every_hours' => self::MONTH_HOURS, 'late_after_hours' => 2 * self::MONTH_HOURS],
        'cities' => ['cron' => '0 2 1 * *', 'every_hours' => self::MONTH_HOURS, 'late_after_hours' => 2 * self::MONTH_HOURS],
        'hotels' => ['cron' => '0 3 * * 0', 'every_hours' => 168, 'late_after_hours' => 2 * 168],
        'room_types' => ['cron' => '30 3 1 * *', 'every_hours' => self::MONTH_HOURS, 'late_after_hours' => 2 * self::MONTH_HOURS],
        'tags' => ['cron' => '45 3 1 * *', 'every_hours' => self::MONTH_HOURS, 'late_after_hours' => 2 * self::MONTH_HOURS],
        'package_routes' => ['cron' => '50 3 * * 0', 'every_hours' => 168, 'late_after_hours' => 2 * 168],
        'product_info' => ['cron' => '0 4 * * *', 'every_hours' => 24, 'late_after_hours' => 48],
        'availability' => ['cron' => '15 4 * * *', 'every_hours' => 24, 'late_after_hours' => 48],
        'add_products' => ['cron' => '30 5 * * *', 'every_hours' => 24, 'late_after_hours' => 48],
        'update_products' => ['cron' => '0 6 * * *', 'every_hours' => 24, 'late_after_hours' => 48],
        'booking_status' => ['cron' => '*/15 * * * *', 'every_hours' => 1, 'late_after_hours' => 2],
        'cleanup' => ['cron' => '0 5 * * 0', 'every_hours' => 168, 'late_after_hours' => 2 * 168],
    ];

    /** The recommended way to schedule the catalogs: one nightly line. */
    public const array RECOMMENDED = ['full'];

    /** product_info's full=1: every hotel's details again, also the fresh ones. */
    public const array FORCE_FULL = ['product_info'];

    /**
     * Run on `full` runs the catalogs that are due, as the nightly line does;
     * this runs all of them now, as the old "Run full sync" did.
     *
     * @var array<string, list<array{action: string, label: string, confirm?: string}>>
     */
    protected const array EXTRA_ACTIONS = [
        'full' => [['action' => 'force', 'label' => 'eurosite.jobs_full_force', 'confirm' => 'eurosite.jobs_full_force_confirm']],
    ];

    /** Read-only, run by hand from its own form on the dashboard: never a row or a crontab line. */
    public const array DIAGNOSTIC = ['probe_availability'];

    /** The two crontab plans, the recommended one first. */
    public const array PLANS = ['full', 'per'];

    /**
     * Which figure of the dashboard's counts is a job's catalog: the cache is
     * counted as 'cache' but filled by product_info; the availability check
     * shows the Immediate hotels, the product jobs the products.
     *
     * @var array<string, string>
     */
    private const array COUNTS = [
        'countries' => 'countries',
        'own_cities' => 'own_cities',
        'cities' => 'cities',
        'hotels' => 'hotels',
        'room_types' => 'room_types',
        'tags' => 'tags',
        'package_routes' => 'package_routes',
        'product_info' => 'cache',
        'availability' => 'immediate',
        'add_products' => 'products',
        'update_products' => 'products',
    ];

    /** How early `full` may run a weekly step: its last run may have ended a little after 01:00. */
    private const int STEP_SLACK = 3 * 3600;

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
        $hours = self::SCHEDULES[$mode]['every_hours'] ?? 24;
        if ($hours >= self::MONTH_HOURS) {
            return date('Y-m', $lastOkAt) !== date('Y-m', $now);
        }

        return $now - $lastOkAt >= $hours * 3600 - self::STEP_SLACK;
    }

    /** Whether a plan schedules this job: 'full' runs the catalogs through `full`, 'per' each on its own line. */
    public static function inPlan(string $plan, string $mode): bool
    {
        if ($mode === 'full') {
            return $plan !== 'per';
        }
        if (in_array($mode, self::PIPELINE, true)) {
            return $plan === 'per';
        }

        return true;
    }

    /**
     * The block to paste into the server crontab or cPanel, for one plan.
     *
     * Travel Core's crontab() lists every job of every stage, which here
     * would run each catalog twice (through `full` and on its own line); this
     * lists one plan's. CLI lines, because a bare URL does not run in a
     * crontab; the URL form lists each address under the time to add it at,
     * as comments, so nothing in it can be pasted into a crontab by mistake.
     *
     * @param array<string, string> $modes dispatcher mode => description
     */
    public function crontabFor(string $plan, array $modes, string $format, string $generatedOn = ''): string
    {
        $lines = [
            $plan === 'per'
                ? '# ' . self::TITLE . ': each job on its own line, in the order they run'
                : '# ' . self::TITLE . ': the catalogs in one nightly run (each only when due: monthly; hotels and package routes weekly), then the other jobs',
        ];
        if ($generatedOn !== '') {
            $lines[] = '# generated ' . $generatedOn;
        }
        if ($format === 'url') {
            $lines[] = '# URLs for a cron service: add each one at the time shown';
        }

        $number = 0;
        foreach (self::STAGES as $stage => $jobs) {
            $present = array_values(array_filter(
                $jobs,
                static fn (string $mode): bool => array_key_exists($mode, $modes) && self::inPlan($plan, $mode),
            ));
            if ($present === []) {
                continue;
            }
            $lines[] = '# ' . (++$number) . '. ' . self::STAGE_TITLES[$stage];
            foreach ($present as $mode) {
                $cron = self::cron($mode);
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
     * The flags a run from the dashboard passes to its command: those of an
     * action the job's ⋯ menu offers, as the cron URL spells them, and
     * nothing else from the request.
     *
     * @return array<string, string>
     */
    public static function runParams(string $mode, string $action): array
    {
        $params = self::jobParams($mode);
        if ($action === '' || !in_array($action, self::offeredActions($mode), true)) {
            return $params;
        }

        return $params + match ($action) {
            // ProductInfoCacheCommand reads full=1: every hotel, also the fresh ones.
            'force_full' => ['full' => '1'],
            // FullSyncCommand reads force=1: every catalog, due or not.
            default => [$action => '1'],
        };
    }

    /**
     * The stages' rows with the size of each job's catalog, for Eurosite's
     * part of the row (components/job_extra.tpl): count, or null for a job
     * without one, and the label that says what it counts.
     *
     * @param list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}> $stages
     * @param array<string, int> $counts the dashboard's figures
     *
     * @return list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}>
     */
    public static function withCounts(array $stages, array $counts): array
    {
        return array_map(static function (array $stage) use ($counts): array {
            $stage['jobs'] = array_map(static function (array $job) use ($counts): array {
                $key = self::COUNTS[TypeCoerce::toString($job['mode'] ?? '')] ?? '';
                $job['count'] = $key === '' ? null : ($counts[$key] ?? null);
                $job['count_key'] = $key === '' ? '' : self::LABEL_PREFIX . 'count_' . $key;

                return $job;
            }, $stage['jobs']);

            return $stage;
        }, $stages);
    }

    /**
     * The last finished run of each job in the sync log, for a job Travel
     * Core's run log has not seen yet (a monthly catalog last synced before
     * it existed): when it finished, and whether it went well. A row still
     * 'started' is no finished run.
     *
     * @param array<string, array<string, mixed>> $lastPerType sync_type => its last sync_log row
     *
     * @return array<string, array{at: int, ok: bool}>
     */
    public static function legacyRuns(array $lastPerType): array
    {
        $out = [];
        foreach ($lastPerType as $type => $row) {
            $status = TypeCoerce::toString($row['status'] ?? '');
            if ($status !== 'completed' && $status !== 'failed') {
                continue;
            }
            $when = TypeCoerce::toString($row['completed_at'] ?? '');
            if ($when === '' || str_starts_with($when, '0000')) {
                $when = TypeCoerce::toString($row['started_at'] ?? '');
            }
            $at = $when === '' ? false : strtotime($when);
            if ($at === false || $at <= 0) {
                continue;
            }
            $out[$type] = ['at' => $at, 'ok' => $status === 'completed'];
        }

        return $out;
    }
}
