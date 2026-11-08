<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * Eurosite's scheduled jobs on Travel Core's shared jobs card, tested away
 * from Smarty and the database: the order they run in, their slots and when
 * each is late, what Run and the ⋯ menu pass on, the size of each catalog,
 * and the two crontab plans (Travel Core's CronJobPlanTest covers the rows,
 * the schedule words and the "late" rule themselves).
 */
#[CoversClass(CronPlanBuilder::class)]
final class CronPlanBuilderTest extends TestCase
{
    private const string KEY = 'abc123';

    private const int NOW = 1_758_000_000;

    /** Every mode the dispatcher registers, in the alphabetical order it hands them over. */
    private const array MODES = [
        'add_products' => 'Create products',
        'availability' => 'Check which hotels have an Immediate offer',
        'booking_status' => 'Check bookings waiting for confirmation',
        'cities' => 'Sync city catalogs',
        'cleanup' => 'Trim the sync log',
        'countries' => 'Sync the country catalog',
        'full' => 'Run every static-data sync in order',
        'hotels' => 'Sync own hotels + rooms',
        'own_cities' => 'Sync cities with own offers',
        'probe_availability' => 'Read-only: search the next months',
        'product_info' => 'Warm the product-details cache',
        'room_types' => 'Sync the room-type catalog',
        'tags' => 'Sync the offer-tag catalog',
        'update_products' => 'Refresh the Eurosite products',
    ];

    private static function plan(string $key = self::KEY, string $root = ''): CronPlanBuilder
    {
        return new CronPlanBuilder('https://shop.example.ro', $key, $root);
    }

    /**
     * @param array<string, string> $modes
     * @param array<string, array<string, mixed>> $records
     * @param array<string, array{at: int, ok: bool}> $legacy
     *
     * @return array<string, array<string, mixed>> mode => row
     */
    private static function rows(array $modes = self::MODES, array $records = [], array $legacy = []): array
    {
        $rows = [];
        foreach (self::plan()->stages($modes, $records, $legacy, self::NOW) as $stage) {
            foreach ($stage['jobs'] as $job) {
                $rows[(string) $job['mode']] = $job;
            }
        }

        return $rows;
    }

    // ── Order ────────────────────────────────────────────────────────────

    /**
     * The dispatcher hands modes over alphabetically, which put `cleanup`
     * second, as if housekeeping ran before anything was synced. The card
     * follows the order they depend on each other, `full` first as the one
     * line that runs the catalogs.
     */
    public function testTheJobsRunInStagesInTheOrderTheyDependOnEachOther(): void
    {
        $stages = self::plan()->stages(self::MODES, [], [], self::NOW);

        self::assertSame(['catalogs', 'hotels', 'products', 'upkeep'], array_column($stages, 'stage'));
        self::assertSame([1, 2, 3, 4], array_column($stages, 'number'));
        self::assertSame(
            [
                ['full', 'countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags'],
                ['product_info', 'availability'],
                ['add_products', 'update_products'],
                ['booking_status', 'cleanup'],
            ],
            array_map(static fn (array $stage): array => array_column($stage['jobs'], 'mode'), $stages),
        );
        self::assertSame('eurosite.jobs_stage_catalogs', $stages[0]['title_key']);
        self::assertSame(['full', ...CronPlanBuilder::PIPELINE], CronPlanBuilder::STAGES['catalogs'], 'FullSyncCommand\'s order');
    }

    /** The availability probe is a read-only tool with its own form: never a row, never a crontab line. */
    public function testTheProbeIsNeverOnTheCard(): void
    {
        self::assertArrayNotHasKey('probe_availability', self::rows());
        self::assertSame([], self::plan()->onDemandRows(self::MODES, [], self::NOW));
        foreach (CronPlanBuilder::PLANS as $plan) {
            self::assertStringNotContainsString('probe_availability', self::plan()->crontabFor($plan, self::MODES, 'cli'));
        }
    }

    /** A mode a new command registers is shown, with its code, to run on demand: never silently missing. */
    public function testAModeThePlanDoesNotKnowIsShownOnDemand(): void
    {
        $rows = self::plan()->onDemandRows(self::MODES + ['experiments' => 'Something new'], [], self::NOW);

        self::assertSame(['experiments'], array_column($rows, 'mode'));
        self::assertFalse($rows[0]['labelled']);
        self::assertSame('', $rows[0]['cron']);
    }

    /** Every mode the real dispatcher registers is on the card with a slot of its own, or is the probe. */
    public function testEveryDispatcherModeHasAPlace(): void
    {
        CronDispatcher::reset();
        $modes = CronDispatcher::getAvailableModes();
        self::assertNotEmpty($modes);

        $rows = self::rows($modes);
        foreach (array_keys($modes) as $mode) {
            if (in_array($mode, CronPlanBuilder::DIAGNOSTIC, true)) {
                continue;
            }
            self::assertArrayHasKey($mode, $rows, "{$mode} has no row: add it to CronPlanBuilder::STAGES");
            self::assertNotSame('', $rows[$mode]['cron'], "{$mode} has no slot: add it to CronPlanBuilder::SCHEDULES");
            self::assertTrue($rows[$mode]['labelled']);
        }
    }

    // ── Commands ─────────────────────────────────────────────────────────

    public function testUrlAndCliCarryTheKeyAndTheMode(): void
    {
        self::assertSame('https://shop.example.ro/index.php?dispatch=eurosite_cron.run&access_key=abc123&cron_mode=hotels', self::plan()->url('hotels'));
        self::assertSame('php app/addons/eurosite/cron.php access_key=abc123 mode=hotels', self::plan()->cli('hotels'));
        self::assertSame(
            'php /home/shop/public_html/app/addons/eurosite/cron.php access_key=abc123 mode=hotels',
            self::plan(self::KEY, '/home/shop/public_html/')->cli('hotels'),
            'absolute when the store root is known: a crontab runs from the account\'s home',
        );
        self::assertStringNotContainsString('ro//index.php', (new CronPlanBuilder('https://shop.example.ro/', self::KEY))->url('cities'));
    }

    public function testAKeyWithUrlSpecialCharactersIsEncodedInTheUrlButNotTheCliCommand(): void
    {
        $plan = new CronPlanBuilder('https://shop.example.ro', 'a b&c=d');

        self::assertStringContainsString('access_key=a%20b%26c%3Dd&cron_mode=cities', $plan->url('cities'));
        // The shell takes the raw value: percent-encoding it would send another key.
        self::assertStringContainsString('access_key=a b&c=d mode=cities', $plan->cli('cities'));
    }

    /**
     * REGRESSION: the live store rendered `access_key=&cron_mode=cities` nine
     * times over, and every one was refused: with no key, no commands.
     */
    public function testHasKeyIsFalseForAnEmptyOrBlankKey(): void
    {
        self::assertFalse(self::plan('')->hasKey());
        self::assertFalse(self::plan('   ')->hasKey());
        self::assertTrue(self::plan()->hasKey());
    }

    public function testEachRowsCrontabLineIsItsSlotAndItsCliCommand(): void
    {
        foreach (self::rows() as $mode => $row) {
            self::assertSame($row['cron'] . '  ' . $row['cli'], $row['crontab_line'], $mode);
            self::assertStringNotContainsString('curl', (string) $row['crontab_line']);
        }
    }

    // ── Schedules ────────────────────────────────────────────────────────

    /** Eurosite's answers (28 Oct 2026): the catalogs monthly, the hotel lists weekly, bulk work 01:00–06:00. */
    public function testEachJobHasItsSlotInWords(): void
    {
        $rows = self::rows();

        self::assertSame(['travel_core.jobs_sched_daily', ['[time]' => '01:00']], [$rows['full']['schedule_key'], $rows['full']['schedule_params']]);
        self::assertSame(['travel_core.jobs_sched_monthly', ['[day]' => '1', '[time]' => '01:00']], [$rows['countries']['schedule_key'], $rows['countries']['schedule_params']]);
        self::assertSame(['travel_core.jobs_sched_monthly', ['[day]' => '1', '[time]' => '03:45']], [$rows['tags']['schedule_key'], $rows['tags']['schedule_params']]);
        self::assertSame(['travel_core.jobs_sched_weekly_sun', ['[time]' => '03:00']], [$rows['hotels']['schedule_key'], $rows['hotels']['schedule_params']]);
        self::assertSame(['travel_core.jobs_sched_daily', ['[time]' => '04:00']], [$rows['product_info']['schedule_key'], $rows['product_info']['schedule_params']]);
        self::assertSame(['travel_core.jobs_sched_every_minutes', ['[n]' => '15']], [$rows['booking_status']['schedule_key'], $rows['booking_status']['schedule_params']]);
        self::assertSame(['travel_core.jobs_sched_weekly_sun', ['[time]' => '05:00']], [$rows['cleanup']['schedule_key'], $rows['cleanup']['schedule_params']]);
    }

    /**
     * Late is measured against the job's OWN interval, twice over: a monthly
     * catalog synced 40 days ago is fine, a daily job 3 days ago is not.
     */
    public function testLateIsTwiceTheJobsOwnInterval(): void
    {
        $finished = static fn (int $daysAgo): array => ['started' => self::NOW - $daysAgo * 86400 - 60, 'finished' => self::NOW - $daysAgo * 86400, 'ok' => true, 'error' => ''];
        $state = static fn (string $mode, int $daysAgo): string => (string) self::rows(self::MODES, [$mode => $finished($daysAgo)])[$mode]['state'];

        self::assertSame('ok', $state('countries', 40));
        self::assertSame('late', $state('countries', 63));
        self::assertSame('ok', $state('hotels', 13));
        self::assertSame('late', $state('hotels', 15));
        self::assertSame('ok', $state('product_info', 1));
        self::assertSame('late', $state('product_info', 3));
    }

    /** Each catalog is measured on its own row, whichever plan runs it: `full` itself is never late. */
    public function testFullIsNeverLate(): void
    {
        $longAgo = ['started' => self::NOW - 90 * 86400 - 60, 'finished' => self::NOW - 90 * 86400, 'ok' => true, 'error' => ''];

        self::assertSame('ok', self::rows(self::MODES, ['full' => $longAgo])['full']['state']);
    }

    /** A catalog the run log never saw shows its last sync from the sync log; the run log wins when it has one. */
    public function testTheSyncLogIsTheFallback(): void
    {
        $legacy = ['countries' => ['at' => self::NOW - 5 * 86400, 'ok' => true], 'cities' => ['at' => self::NOW - 86400, 'ok' => false]];
        $rows = self::rows(self::MODES, ['cities' => ['started' => self::NOW - 7200, 'finished' => self::NOW - 3600, 'ok' => true, 'error' => '']], $legacy);

        self::assertSame(['ok', self::NOW - 5 * 86400], [$rows['countries']['state'], $rows['countries']['at']]);
        self::assertSame(['ok', self::NOW - 3600], [$rows['cities']['state'], $rows['cities']['at']]);
        self::assertSame('never', $rows['tags']['state']);
    }

    public function testLegacyRunsAreTheFinishedRowsOfTheSyncLog(): void
    {
        $runs = CronPlanBuilder::legacyRuns([
            'countries' => ['status' => 'completed', 'started_at' => '2026-10-01 01:00:00', 'completed_at' => '2026-10-01 01:02:00'],
            'cities' => ['status' => 'failed', 'started_at' => '2026-10-01 02:00:00', 'completed_at' => '2026-10-01 02:05:00'],
            // Written before completed_at was filled: when it started.
            'tags' => ['status' => 'completed', 'started_at' => '2026-10-01 03:45:00', 'completed_at' => '0000-00-00 00:00:00'],
            // Never finished: no run to show.
            'hotels' => ['status' => 'started', 'started_at' => '2026-10-04 03:00:00', 'completed_at' => null],
            'room_types' => ['status' => 'completed', 'started_at' => '', 'completed_at' => ''],
        ]);

        self::assertSame([
            'countries' => ['at' => (int) strtotime('2026-10-01 01:02:00'), 'ok' => true],
            'cities' => ['at' => (int) strtotime('2026-10-01 02:05:00'), 'ok' => false],
            'tags' => ['at' => (int) strtotime('2026-10-01 03:45:00'), 'ok' => true],
        ], $runs);
    }

    // ── What Run and the ⋯ menu pass on ──────────────────────────────────

    public function testEachJobOffersTheActionsItAnswers(): void
    {
        $rows = self::rows();

        self::assertTrue($rows['full']['recommended']);
        self::assertSame([['action' => 'force', 'label' => 'eurosite.jobs_full_force', 'confirm' => 'eurosite.jobs_full_force_confirm']], $rows['full']['extras']);
        self::assertSame(['force'], CronPlanBuilder::offeredActions('full'));
        self::assertTrue($rows['product_info']['force_full']);
        self::assertSame(['force_full'], CronPlanBuilder::offeredActions('product_info'));
        foreach (['countries', 'hotels', 'availability', 'add_products', 'booking_status', 'cleanup'] as $mode) {
            self::assertSame([], CronPlanBuilder::offeredActions($mode), $mode);
        }
    }

    /**
     * Run passes nothing on: `full` then runs what is due, as the nightly
     * line does. "Run every catalog now" is force=1, Force full sync of the
     * hotel details full=1, as their commands read them. Anything else the
     * request carries is dropped.
     */
    public function testRunPassesOnOnlyTheFlagsOfAnOfferedAction(): void
    {
        self::assertSame([], CronPlanBuilder::runParams('full', ''));
        self::assertSame(['force' => '1'], CronPlanBuilder::runParams('full', 'force'));
        self::assertSame(['full' => '1'], CronPlanBuilder::runParams('product_info', 'force_full'));

        self::assertSame([], CronPlanBuilder::runParams('countries', 'force'), 'not offered for countries');
        self::assertSame([], CronPlanBuilder::runParams('product_info', 'reset'));
        self::assertSame([], CronPlanBuilder::runParams('full', 'force_full'));
        self::assertSame([], CronPlanBuilder::runParams('add_products', 'dry_run'));
    }

    // ── The size of each catalog ─────────────────────────────────────────

    public function testEachRowCarriesTheSizeOfItsCatalog(): void
    {
        $stages = CronPlanBuilder::withCounts(
            self::plan()->stages(self::MODES, [], [], self::NOW),
            ['countries' => 86, 'cities' => 1277, 'cache' => 42, 'immediate' => 0, 'products' => 12],
        );
        $rows = [];
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $rows[(string) $job['mode']] = [$job['count'], $job['count_key']];
            }
        }

        self::assertSame([86, 'eurosite.jobs_count_countries'], $rows['countries']);
        self::assertSame([1277, 'eurosite.jobs_count_cities'], $rows['cities']);
        // product_info fills the cache, which is counted as 'cache'.
        self::assertSame([42, 'eurosite.jobs_count_cache'], $rows['product_info']);
        self::assertSame([0, 'eurosite.jobs_count_immediate'], $rows['availability'], 'a zero is a figure, not a missing one');
        self::assertSame([12, 'eurosite.jobs_count_products'], $rows['add_products']);
        self::assertSame([12, 'eurosite.jobs_count_products'], $rows['update_products']);
        self::assertSame([null, 'eurosite.jobs_count_hotels'], $rows['hotels'], 'not counted this time');
        self::assertSame([null, ''], $rows['full'], 'no catalog of its own');
        self::assertSame([null, ''], $rows['cleanup']);
        self::assertSame(array_column(self::plan()->stages(self::MODES, [], [], self::NOW), 'stage'), array_column($stages, 'stage'));
    }

    // ── The two plans ────────────────────────────────────────────────────

    /** @return list<string> the modes a crontab block schedules, in order */
    private static function scheduled(string $text): array
    {
        preg_match_all('/ mode=([a-z_]+)$/m', $text, $m);

        return $m[1];
    }

    /** The recommended plan: `full` runs the catalogs; installing them too would run each twice. */
    public function testTheNightlyPlanRunsTheCatalogsThroughFull(): void
    {
        self::assertSame(
            ['full', 'product_info', 'availability', 'add_products', 'update_products', 'booking_status', 'cleanup'],
            self::scheduled(self::plan()->crontabFor('full', self::MODES, 'cli')),
        );
    }

    public function testThePerCatalogPlanSchedulesEachCatalogAndNotFull(): void
    {
        self::assertSame(
            ['countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags', 'product_info', 'availability', 'add_products', 'update_products', 'booking_status', 'cleanup'],
            self::scheduled(self::plan()->crontabFor('per', self::MODES, 'cli')),
        );
    }

    public function testEachPlanSaysWhatItIsAndIsPasteable(): void
    {
        $lines = explode("\n", self::plan()->crontabFor('full', self::MODES, 'cli', '2026-11-08, server time Europe/Bucharest'));

        self::assertSame('# Eurosite Touring: the catalogs in one nightly run (each only when due: monthly, hotels weekly), then the other jobs', $lines[0]);
        self::assertSame('# generated 2026-11-08, server time Europe/Bucharest', $lines[1]);
        self::assertSame('# 1. Catalogs', $lines[2]);
        self::assertSame('0 1 * * *  php app/addons/eurosite/cron.php access_key=abc123 mode=full', $lines[3]);
        self::assertSame('# 2. Hotel details and availability', $lines[4]);
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^(#.*|[\d*\/ ,-]+\s{2}php app\/addons\/eurosite\/cron\.php access_key=abc123 mode=[a-z_]+)$/', $line);
        }

        $per = self::plan()->crontabFor('per', self::MODES, 'cli');
        self::assertStringStartsWith("# Eurosite Touring: each job on its own line, in the order they run\n# 1. Catalogs\n0 1 1 * *  php app/addons/eurosite/cron.php access_key=abc123 mode=countries\n", $per);
        self::assertStringNotContainsString('# generated', $per, 'no date was given');
    }

    /**
     * The URL form is for a cron service, not a crontab: every slot sits in
     * a comment, and the only other lines are the plain addresses.
     */
    public function testTheUrlFormListsPlainUrlsUnderTheirSlot(): void
    {
        $text = self::plan()->crontabFor('full', self::MODES, 'url');
        $lines = explode("\n", $text);

        self::assertSame('# URLs for a cron service: add each one at the time shown', $lines[1]);
        self::assertSame(['# 1. Catalogs', '# 0 1 * * *', self::plan()->url('full')], array_slice($lines, 2, 3));
        self::assertStringNotContainsString('curl', $text);
        foreach ($lines as $line) {
            self::assertTrue(str_starts_with($line, '#') || str_starts_with($line, 'https://shop.example.ro/index.php?'), $line);
        }
    }

    /** The page masks the key by replacing it in the text, so it must appear verbatim in every command. */
    public function testEveryCommandCarriesTheKeyVerbatim(): void
    {
        foreach (CronPlanBuilder::PLANS as $plan) {
            foreach (['url', 'cli'] as $format) {
                foreach (explode("\n", self::plan()->crontabFor($plan, self::MODES, $format)) as $line) {
                    if (!str_starts_with($line, '#')) {
                        self::assertStringContainsString(self::KEY, $line, "{$plan}/{$format}: {$line}");
                    }
                }
            }
        }
    }

    /** A job the dispatcher does not have is in no plan. */
    public function testAPlanListsOnlyTheJobsTheDispatcherHas(): void
    {
        $modes = self::MODES;
        unset($modes['full'], $modes['tags']);

        self::assertNotContains('full', self::scheduled(self::plan()->crontabFor('full', $modes, 'cli')));
        self::assertNotContains('tags', self::scheduled(self::plan()->crontabFor('per', $modes, 'cli')));
    }

    public function testWhichPlanSchedulesWhichJob(): void
    {
        self::assertTrue(CronPlanBuilder::inPlan('full', 'full'));
        self::assertFalse(CronPlanBuilder::inPlan('per', 'full'));
        foreach (CronPlanBuilder::PIPELINE as $catalog) {
            self::assertFalse(CronPlanBuilder::inPlan('full', $catalog), $catalog);
            self::assertTrue(CronPlanBuilder::inPlan('per', $catalog), $catalog);
        }
        self::assertTrue(CronPlanBuilder::inPlan('full', 'availability'));
        self::assertTrue(CronPlanBuilder::inPlan('per', 'availability'));
        self::assertSame(['full', 'per'], CronPlanBuilder::PLANS, 'the recommended plan first: the card shows it on load');
    }
}
