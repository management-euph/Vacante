<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\Commands\AbstractSyncCommand;
use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\SphinxHolidays\Cron\CronPlan;
use Tygh\Addons\TravelCore\Cron\CronJobPlan;

/**
 * Sphinx's scheduled jobs on Travel Core's shared jobs card: one row per job,
 * in the order the jobs need each other, with the schedules the dashboard
 * listed, commands for Sphinx's own endpoint, and what a Run from the
 * dashboard may pass to each job (sphinx_holidays.run_job).
 */
final class CronPlanTest extends TestCase
{
    private const string KEY = 'k3y-for-tests';

    private const int NOW = 1_790_000_000;

    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    protected function tearDown(): void
    {
        CronDispatcher::reset();
    }

    private static function plan(string $dirRoot = '/var/www/html'): CronPlan
    {
        return new CronPlan('http://shop.example/', self::KEY, $dirRoot);
    }

    /** @return array<string, string> every mode the plan knows, as the dispatcher lists them */
    private static function allModes(): array
    {
        $modes = [];
        foreach ([...array_merge(...array_values(CronPlan::STAGES)), ...CronPlan::ON_DEMAND, ...CronPlan::DIAGNOSTIC] as $mode) {
            $modes[$mode] = 'desc ' . $mode;
        }

        return $modes;
    }

    /**
     * @param list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}> $stages
     *
     * @return list<string>
     */
    private static function flatten(array $stages): array
    {
        $out = [];
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $out[] = (string) $job['mode'];
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> every scheduled row, by mode */
    private static function rows(): array
    {
        $rows = [];
        foreach (self::plan()->stages(self::allModes(), [], [], self::NOW) as $stage) {
            foreach ($stage['jobs'] as $job) {
                $rows[(string) $job['mode']] = $job;
            }
        }

        return $rows;
    }

    public function testJobsComeInTheOrderTheyRun(): void
    {
        $stages = self::plan()->stages(self::allModes(), [], [], self::NOW);

        self::assertSame(['reference', 'catalogue', 'products', 'locations', 'images', 'upkeep'], array_column($stages, 'stage'));
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($stages, 'number'));
        self::assertSame([
            'destinations',
            'hotels', 'circuits', 'package_routes',
            'add_products', 'add_circuit_products', 'discover_boards', 'assign_boards',
            'backfill_hotel_locations', 'geocode_hotels', 'update_products', 'calendar_prices',
            'enrich_hotel_data', 'sync_images', 'process_image_queue',
            'order_status', 'cleanup',
        ], self::flatten($stages));
    }

    /** The prerequisites the order stands on, stated in the commands themselves. */
    public function testEachJobComesAfterWhatItNeeds(): void
    {
        $order = array_flip(self::flatten(self::plan()->stages(self::allModes(), [], [], self::NOW)));

        foreach ([
            ['destinations', 'hotels'],
            ['hotels', 'add_products'],
            ['circuits', 'add_circuit_products'],
            ['add_products', 'discover_boards'],
            ['discover_boards', 'assign_boards'],
            ['backfill_hotel_locations', 'update_products'],
            ['geocode_hotels', 'update_products'],
            ['add_products', 'calendar_prices'],
            ['hotels', 'enrich_hotel_data'],
            ['enrich_hotel_data', 'sync_images'],
            ['sync_images', 'process_image_queue'],
        ] as [$first, $then]) {
            self::assertLessThan($order[$then], $order[$first], "{$first} must come before {$then}");
        }
    }

    /**
     * The slots the dashboard's old Cron Commands table gave, except the four
     * decided on 8 Nov 2026: Meal plans and Assign boards moved to 03:45 and
     * 04:15, Circuit products and Calendar prices scheduled.
     */
    public function testTheSchedulesAreTheOnesDecided(): void
    {
        $listed = [
            'destinations' => '0 2 * * 0',
            'hotels' => '0 3 * * *',
            'circuits' => '15 3 * * *',
            'package_routes' => '0 4 * * 1',
            'add_products' => '30 3 * * *',
            'add_circuit_products' => '40 3 * * *',
            'discover_boards' => '45 3 * * *',
            'assign_boards' => '15 4 * * *',
            'backfill_hotel_locations' => '30 4 * * *',
            'geocode_hotels' => '*/15 * * * *',
            'update_products' => '0 6 * * *',
            'calendar_prices' => '30 6 * * *',
            'enrich_hotel_data' => '*/5 * * * *',
            'sync_images' => '0 4 * * *',
            'process_image_queue' => '*/2 * * * *',
            'order_status' => '*/15 * * * *',
            'cleanup' => '0 5 * * *',
        ];

        $slots = [];
        foreach (array_merge(...array_values(CronPlan::STAGES)) as $mode) {
            $slots[$mode] = CronPlan::cron($mode);
        }
        ksort($slots);
        ksort($listed);
        self::assertSame($listed, $slots);
    }

    /** Minutes after midnight a daily job starts at. */
    private static function startsAt(string $mode): int
    {
        [$minute, $hour, $dom, $month, $dow] = explode(' ', CronPlan::cron($mode));
        self::assertTrue(ctype_digit($minute) && ctype_digit($hour), "{$mode} is not a job that starts at a fixed time");
        self::assertSame(['*', '*', '*'], [$dom, $month, $dow], "{$mode} does not run daily");

        return (int) $hour * 60 + (int) $minute;
    }

    /**
     * A daily job starts after the daily jobs whose data it uses, so it never
     * works on the day before's: Meal plans used to start with Hotels and
     * Assign boards with Create products.
     */
    public function testEachDailyJobStartsAfterTheJobsWhoseDataItUses(): void
    {
        foreach ([
            ['hotels', 'add_products'],
            ['hotels', 'discover_boards'],
            ['add_products', 'discover_boards'],
            ['discover_boards', 'assign_boards'],
            ['add_products', 'assign_boards'],
            ['circuits', 'add_circuit_products'],
            ['add_products', 'calendar_prices'],
            ['update_products', 'calendar_prices'],
        ] as [$first, $then]) {
            self::assertGreaterThan(self::startsAt($first), self::startsAt($then), "{$then} starts before {$first}, whose data it uses");
        }
    }

    /** Calendar prices and Meal plans both call the search API: they never start within an hour of each other. */
    public function testTheSearchApiJobsDoNotOverlap(): void
    {
        self::assertGreaterThanOrEqual(60, abs(self::startsAt('calendar_prices') - self::startsAt('discover_boards')));
    }

    public function testEveryScheduledJobHasASlotThatReadsAsWords(): void
    {
        foreach (array_merge(...array_values(CronPlan::STAGES)) as $mode) {
            self::assertNotSame('', CronPlan::cron($mode), $mode);
            self::assertNotSame('travel_core.jobs_sched_raw', CronPlan::scheduleWords(CronPlan::cron($mode))['key'], "{$mode} has no words for its schedule");
        }
        foreach ([...CronPlan::ON_DEMAND, ...CronPlan::DIAGNOSTIC] as $mode) {
            self::assertSame('', CronPlan::cron($mode), "{$mode} is not scheduled");
        }
    }

    /**
     * Every mode the dispatcher has is on the page — in a stage or on demand —
     * or is a one-hotel check on purpose, and no mode is in two lists.
     */
    public function testEveryDispatcherModeIsAccountedForOnce(): void
    {
        $modes = CronDispatcher::getAvailableModes();
        self::assertNotSame([], $modes);

        $listed = [...array_merge(...array_values(CronPlan::STAGES)), ...CronPlan::ON_DEMAND, ...CronPlan::DIAGNOSTIC];
        self::assertSame(array_values(array_unique($listed)), $listed, 'a mode is in two lists');
        $sortedListed = $listed;
        sort($sortedListed);
        self::assertSame(array_keys($modes), $sortedListed, 'the plan and the dispatcher list different modes');

        $plan = self::plan();
        $shown = [...self::flatten($plan->stages($modes, [], [], self::NOW)), ...array_column($plan->onDemandRows($modes, [], self::NOW), 'mode')];
        foreach (CronPlan::DIAGNOSTIC as $mode) {
            self::assertNotContains($mode, $shown, "{$mode} runs from Check one hotel, not as a job");
        }
        self::assertCount(count($modes) - count(CronPlan::DIAGNOSTIC), $shown);
    }

    /** Sphinx's endpoint takes the mode as cron_mode (CS-Cart keeps mode for dispatch); its CLI script as mode. */
    public function testCommandsUseSphinxsEndpoint(): void
    {
        $plan = self::plan('/var/www/html/');

        self::assertSame('http://shop.example/index.php?dispatch=sphinx_cron.run&access_key=' . self::KEY . '&cron_mode=hotels', $plan->url('hotels'));
        self::assertSame('php /var/www/html/app/addons/sphinx_holidays/cron.php access_key=' . self::KEY . ' mode=hotels', $plan->cli('hotels'));
        self::assertSame('php app/addons/sphinx_holidays/cron.php access_key=' . self::KEY . ' mode=cleanup', self::plan('')->cli('cleanup'));
        self::assertSame('0 2 * * 0  ' . $plan->cli('destinations'), self::rows()['destinations']['crontab_line']);
    }

    /** The dashboard told admins to add &limit=100 to geocoding by hand; every command now carries it. */
    public function testGeocodingsLimitRidesInEveryCommand(): void
    {
        $plan = self::plan();

        self::assertSame(['limit' => '100'], CronPlan::jobParams('geocode_hotels'));
        self::assertStringEndsWith('&cron_mode=geocode_hotels&limit=100', $plan->url('geocode_hotels'));
        self::assertStringEndsWith(' mode=geocode_hotels limit=100', $plan->cli('geocode_hotels'));
        self::assertSame('*/15 * * * *  ' . $plan->cli('geocode_hotels'), self::rows()['geocode_hotels']['crontab_line']);
        self::assertStringContainsString('mode=geocode_hotels limit=100', $plan->crontab(self::allModes(), 'cli'));
        self::assertStringContainsString('&cron_mode=geocode_hotels&limit=100', $plan->crontab(self::allModes(), 'url'));
        self::assertSame([], CronPlan::jobParams('hotels'));
    }

    public function testTheCrontabIsCliLinesInRunningOrder(): void
    {
        $text = self::plan()->crontab(self::allModes(), 'cli', '2026-11-08');
        $lines = explode("\n", $text);

        self::assertSame('# Sphinx Holidays: scheduled jobs, in the order they run', $lines[0]);
        self::assertSame('# generated 2026-11-08', $lines[1]);
        self::assertSame('# 1. Reference data', $lines[2]);
        self::assertSame('0 2 * * 0  php /var/www/html/app/addons/sphinx_holidays/cron.php access_key=' . self::KEY . ' mode=destinations', $lines[3]);

        $commands = array_values(array_filter($lines, static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')));
        self::assertCount(17, $commands);
        foreach ($commands as $line) {
            self::assertMatchesRegularExpression('/^\S+ \S+ \S+ \S+ \S+  php \//', $line);
        }
        self::assertStringNotContainsString('mode=full', $text, 'full repeats the staged jobs; scheduling both runs them twice');
        self::assertStringNotContainsString('diagnose_', $text);
    }

    public function testNoKeyMeansNoCommands(): void
    {
        self::assertFalse((new CronPlan('http://shop.example', '  '))->hasKey());
        self::assertTrue(self::plan()->hasKey());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function menus(): iterable
    {
        yield 'destinations: batched, resumes' => ['destinations', ['status', 'force_full', 'reset']];
        yield 'hotels: full re-read' => ['hotels', ['force_full']];
        yield 'circuits: full re-read' => ['circuits', ['force_full']];
        yield 'package_routes: nothing more' => ['package_routes', []];
        yield 'add_products: resumes, retries the skipped' => ['add_products', ['status', 'reset', 'retry_skipped']];
        yield 'discover_boards: resumes' => ['discover_boards', ['status', 'reset']];
        yield 'assign_boards: resumes' => ['assign_boards', ['status', 'reset']];
        yield 'geocode_hotels: every hotel again' => ['geocode_hotels', ['status', 'force']];
        yield 'sync_images: queue every hotel again' => ['sync_images', ['requeue']];
        yield 'process_image_queue: retry the failed' => ['process_image_queue', ['retry_failed']];
        yield 'cleanup: nothing more' => ['cleanup', []];
        yield 'full: nothing more' => ['full', []];
    }

    /** @param list<string> $actions */
    #[DataProvider('menus')]
    public function testEachJobsMenuOffersWhatItsCommandAnswers(string $mode, array $actions): void
    {
        self::assertSame($actions, CronPlan::offeredActions($mode));
    }

    /**
     * Each menu action sends a flag the job's own command reads, with the
     * value it expects: a menu item can never be a no-op.
     */
    public function testEveryOfferedActionsFlagIsReadByItsCommand(): void
    {
        $sources = [];
        foreach ((array) glob(dirname(__DIR__, 3) . '/src/Cron/Commands/*Command.php') as $file) {
            $class = 'Tygh\\Addons\\SphinxHolidays\\Cron\\Commands\\' . basename((string) $file, '.php');
            if ($class === AbstractSyncCommand::class) {
                continue;
            }
            /** @var class-string<AbstractSyncCommand> $class */
            foreach ($class::getModes() as $mode) {
                $sources[$mode] = (string) file_get_contents((string) $file);
            }
        }

        $checked = 0;
        foreach (array_keys(self::allModes()) as $mode) {
            foreach (CronPlan::offeredActions($mode) as $action) {
                self::assertArrayHasKey($action, CronPlan::ACTION_PARAMS, "{$mode}: {$action} sends nothing");
                foreach (CronPlan::ACTION_PARAMS[$action] as $flag => $value) {
                    self::assertStringContainsString("\$params['{$flag}']", $sources[$mode] ?? '', "{$mode} does not read {$flag}");
                    if ($value === 'Y') {
                        self::assertMatchesRegularExpression("/\\\$params\\['{$flag}'\\][^;]*=== 'Y'/", $sources[$mode] ?? '', "{$mode} reads {$flag} as something other than Y");
                    }
                    $checked++;
                }
            }
        }
        self::assertGreaterThanOrEqual(14, $checked);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, array<string, string>}> */
    public static function runs(): iterable
    {
        yield 'a plain run' => ['hotels', '', [], []];
        yield 'an offered action' => ['hotels', 'force_full', [], ['full' => '1']];
        yield 'an action the menu does not offer' => ['hotels', 'reset', [], []];
        yield 'an action nobody offers' => ['cleanup', 'drop_everything', [], []];
        yield 'the job\'s own parameters' => ['geocode_hotels', '', [], ['limit' => '100']];
        yield 'own parameters and an action' => ['geocode_hotels', 'force', [], ['limit' => '100', 'force' => '1']];
        yield 'check status' => ['destinations', 'status', [], ['status' => '1']];
        yield 'reset progress' => ['assign_boards', 'reset', [], ['reset' => '1']];
        yield 'retry skipped hotels' => ['add_products', 'retry_skipped', [], ['retry_skipped' => '1']];
        yield 'queue every hotel\'s images again' => ['sync_images', 'requeue', [], ['force' => 'Y']];
        yield 'retry failed downloads' => ['process_image_queue', 'retry_failed', [], ['reset_failed' => 'Y']];
        yield 'the request\'s other fields stay behind' => ['hotels', '', ['country' => 'TR', 'full' => '1', 'reset' => '1', 'access_key' => 'x', 'destination_ids' => '1,2'], []];
        yield 'scope fields only for Queue images' => ['hotels', '', ['scope' => 'country', 'country' => 'TR', 'limit' => '5', 'force' => 'Y'], []];
        yield 'hotel fields only for the checks' => ['add_products', '', ['hotel' => '89812', 'attach' => 'Y', 'apply' => 'Y'], []];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $expected
     */
    #[DataProvider('runs')]
    public function testARunPassesOnlyWhatTheDashboardOffers(string $mode, string $action, array $request, array $expected): void
    {
        self::assertSame($expected, CronPlan::runParams($mode, $action, $request));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>}> */
    public static function scopes(): iterable
    {
        yield 'every country you sell' => [['scope' => 'all'], []];
        yield 'only the picked destinations' => [['scope' => 'strict'], ['whitelist' => 'strict']];
        yield 'one country' => [['scope' => 'country', 'country' => 'tr '], ['country' => 'TR']];
        yield 'a country that is not a code' => [['scope' => 'country', 'country' => 'TUR'], []];
        yield 'a list of countries' => [['scope' => 'country', 'country' => 'TR,GR'], []];
        yield 'one destination' => [['scope' => 'destination', 'scope_id' => '1234'], ['destination_id' => '1234']];
        yield 'one region' => [['scope' => 'region', 'scope_id' => ' 5678 '], ['region_id' => '5678']];
        yield 'an ID that is not a number' => [['scope' => 'destination', 'scope_id' => '12 OR 1=1'], []];
        yield 'a zero ID' => [['scope' => 'region', 'scope_id' => '0'], []];
        yield 'a negative ID' => [['scope' => 'region', 'scope_id' => '-5'], []];
        yield 'an ID for the country scope' => [['scope' => 'country', 'country' => 'GR', 'scope_id' => '1234'], ['country' => 'GR']];
        yield 'an unknown scope' => [['scope' => 'everything', 'scope_id' => '1234'], []];
        yield 'hotels that have images too' => [['scope' => 'all', 'force' => 'Y'], ['force' => 'Y']];
        yield 'force is Y or nothing' => [['scope' => 'all', 'force' => '1'], []];
        yield 'a limit' => [['scope' => 'strict', 'limit' => '50'], ['whitelist' => 'strict', 'limit' => '50']];
        yield 'no limit' => [['scope' => 'all', 'limit' => ''], []];
        yield 'a limit that is not a number' => [['scope' => 'all', 'limit' => '1e9'], []];
        yield 'an array where text belongs' => [['scope' => ['strict'], 'country' => ['TR'], 'limit' => ['5']], []];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $expected
     */
    #[DataProvider('scopes')]
    public function testQueueImagesForAScope(array $request, array $expected): void
    {
        self::assertSame($expected, CronPlan::runParams('sync_images', '', ['job' => 'sync_images', ...$request]));
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<string, string>}> */
    public static function checks(): iterable
    {
        yield 'an ID' => ['diagnose_seo', ['hotel' => '89812'], ['hotel_id' => '89812']];
        yield 'an ID with spaces' => ['diagnose_product_features', ['hotel' => ' 89812 '], ['hotel_id' => '89812']];
        yield 'a name, for Search API' => ['diagnose_search', ['hotel' => 'kazbek dubrovnik'], ['hotel_name' => 'kazbek dubrovnik']];
        yield 'a name in any script' => ['diagnose_search', ['hotel' => "Hôtel Ștefan cel Mare, Iași"], ['hotel_name' => "Hôtel Ștefan cel Mare, Iași"]];
        yield 'a name loses markup and symbols' => ['diagnose_search', ['hotel' => '<b>Kazbek</b>; DROP "x"'], ['hotel_name' => 'b Kazbek b DROP x']];
        yield 'a name for a check that needs an ID' => ['diagnose_images', ['hotel' => 'kazbek dubrovnik'], []];
        yield 'nothing' => ['diagnose_search', ['hotel' => '   '], []];
        yield 'attach, for Images' => ['diagnose_images', ['hotel' => '7', 'attach' => 'Y'], ['hotel_id' => '7', 'attach' => 'Y']];
        yield 'attach is for Images only' => ['diagnose_seo', ['hotel' => '7', 'attach' => 'Y'], ['hotel_id' => '7']];
        yield 'apply, for SEO fields' => ['diagnose_seo', ['hotel' => '7', 'apply' => 'Y'], ['hotel_id' => '7', 'apply' => 'Y']];
        yield 'apply is for SEO fields only' => ['diagnose_images', ['hotel' => '7', 'apply' => 'Y'], ['hotel_id' => '7']];
        yield 'a box that is not ticked' => ['diagnose_images', ['hotel' => '7', 'attach' => ''], ['hotel_id' => '7']];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $expected
     */
    #[DataProvider('checks')]
    public function testCheckOneHotel(string $mode, array $request, array $expected): void
    {
        self::assertSame($expected, CronPlan::runParams($mode, '', $request));
    }

    public function testALongNameIsCutToAHundredCharacters(): void
    {
        $params = CronPlan::runParams('diagnose_search', '', ['hotel' => str_repeat('Ș', 150)]);

        self::assertSame(str_repeat('Ș', 100), $params['hotel_name']);
    }

    public function testRowsSayWhichMenuItemsAJobHas(): void
    {
        $rows = self::rows();

        self::assertTrue($rows['destinations']['batched']);
        self::assertTrue($rows['destinations']['has_status']);
        self::assertTrue($rows['hotels']['force_full']);
        self::assertFalse($rows['hotels']['resettable']);
        self::assertFalse($rows['hotels']['has_status']);
        self::assertTrue($rows['add_products']['resettable']);
        self::assertFalse($rows['add_products']['force_full']);
        self::assertTrue($rows['geocode_hotels']['redo_all']);
        self::assertSame([['action' => 'requeue', 'label' => 'sphinx_holidays.jobs_requeue_images', 'confirm' => 'sphinx_holidays.jobs_requeue_images_confirm']], $rows['sync_images']['extras']);
        self::assertSame([], $rows['cleanup']['extras']);

        $onDemand = self::plan()->onDemandRows(self::allModes(), [], self::NOW);
        self::assertSame(CronPlan::ON_DEMAND, array_column($onDemand, 'mode'));
        self::assertSame('travel_core.jobs_sched_on_demand', $onDemand[0]['schedule_key']);
        self::assertSame('', $onDemand[0]['crontab_line']);
    }

    /** Sphinx hands Travel Core's shared plan its job list and its own names. */
    public function testTheRowsNameSphinxsOwnLabels(): void
    {
        self::assertInstanceOf(CronJobPlan::class, self::plan());

        $stage = self::plan()->stages(self::allModes(), [], [], self::NOW)[0];
        self::assertSame('sphinx_holidays.jobs_stage_reference', $stage['title_key']);
        self::assertSame('sphinx_holidays.jobs_job_destinations', $stage['jobs'][0]['name_key']);
        self::assertSame('sphinx_holidays.jobs_job_destinations_desc', $stage['jobs'][0]['desc_key']);
    }

    /**
     * The card prints these keys from the rows, so LangVarsTest (which reads
     * __() calls) cannot see them: each must be in lang_keys.php, in English
     * and Romanian, or the page shows a raw "_sphinx_holidays.jobs_…".
     */
    public function testEveryLabelThePlanPrintsIsTranslated(): void
    {
        /** @var array<string, array<string, string>> $langs */
        $langs = require dirname(__DIR__, 3) . '/lang_keys.php';
        $plan = self::plan();

        $keys = [];
        foreach ($plan->stages(self::allModes(), [], [], self::NOW) as $stage) {
            $keys[] = $stage['title_key'];
            foreach ($stage['jobs'] as $job) {
                $keys[] = (string) $job['name_key'];
                $keys[] = (string) $job['desc_key'];
                foreach ((array) $job['extras'] as $extra) {
                    /** @var array{action: string, label: string, confirm?: string} $extra */
                    $keys[] = $extra['label'];
                    $keys[] = $extra['confirm'] ?? $extra['label'];
                }
            }
        }
        foreach ($plan->onDemandRows(self::allModes(), [], self::NOW) as $job) {
            $keys[] = (string) $job['name_key'];
            $keys[] = (string) $job['desc_key'];
        }

        self::assertCount(57, array_unique($keys), '6 stages, 23 jobs with a description each, 3 menu items, 2 of them with a question');
        foreach (array_unique($keys) as $key) {
            self::assertArrayHasKey($key, $langs, "{$key} has no text");
            self::assertNotSame('', trim($langs[$key]['en'] ?? ''), "{$key} has no English text");
            self::assertNotSame('', trim($langs[$key]['ro'] ?? ''), "{$key} has no Romanian text");
        }
    }
}
