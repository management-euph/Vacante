<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\CronJobPlan;
use Tygh\Addons\TravelCore\Tests\Support\SampleJobPlan;

/**
 * The scheduled-jobs table every provider's dashboard shows (Novoton's
 * CronPlanBuilder lists its jobs; the rows, the schedule words, the "late"
 * rule and the crontab text are this shared class): one row per job, in the
 * order the jobs depend on each other, each with its schedule, its last run
 * and its URL / CLI commands.
 */
final class CronJobPlanTest extends TestCase
{
    private const string KEY = 'k3y-for-tests';

    private const int NOW = 1_790_000_000;

    private static function plan(string $dirRoot = '/var/www/html'): SampleJobPlan
    {
        return new SampleJobPlan('http://shop.example/', self::KEY, $dirRoot);
    }

    /** @return array<string, string> every staged, on-demand and diagnostic mode, as a dispatcher lists them */
    private static function allModes(): array
    {
        $modes = [];
        foreach ([...array_merge(...array_values(SampleJobPlan::STAGES)), ...SampleJobPlan::ON_DEMAND, ...SampleJobPlan::DIAGNOSTIC] as $mode) {
            $modes[$mode] = 'desc ' . $mode;
        }

        return $modes;
    }

    /**
     * @param list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}> $stages
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rowsByMode(array $stages): array
    {
        $rows = [];
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $rows[(string) $job['mode']] = $job;
            }
        }

        return $rows;
    }

    public function testCommandsNameTheProvidersEndpointAndCarryTheRealKey(): void
    {
        $plan = new SampleJobPlan('https://shop.example/', 'a b&c');

        self::assertSame('https://shop.example/index.php?dispatch=sample_cron.run&access_key=a%20b%26c&cron_mode=hotels', $plan->url('hotels'));
        self::assertSame('php /var/www/html/app/addons/sample_holidays/cron.php access_key=' . self::KEY . ' mode=hotels', self::plan('/var/www/html/')->cli('hotels'));
        self::assertSame('php app/addons/sample_holidays/cron.php access_key=' . self::KEY . ' mode=cleanup', self::plan('')->cli('cleanup'));
    }

    public function testNoKeyMeansNoCommands(): void
    {
        self::assertFalse((new SampleJobPlan('http://shop.example', '  '))->hasKey());
        self::assertTrue(self::plan()->hasKey());
    }

    public function testStagesKeepTheirOrderAndNameTheProvidersLabels(): void
    {
        $stages = self::plan()->stages(self::allModes(), [], [], self::NOW);

        self::assertSame(['reference', 'catalogue', 'upkeep'], array_column($stages, 'stage'));
        self::assertSame([1, 2, 3], array_column($stages, 'number'));
        self::assertSame('sample_holidays.jobs_stage_catalogue', $stages[1]['title_key']);
        self::assertSame(['hotels', 'circuits', 'geocode'], array_column($stages[1]['jobs'], 'mode'));

        $hotels = $stages[1]['jobs'][0];
        self::assertTrue($hotels['labelled']);
        self::assertSame('sample_holidays.jobs_job_hotels', $hotels['name_key']);
        self::assertSame('sample_holidays.jobs_job_hotels_desc', $hotels['desc_key']);
        self::assertSame('desc hotels', $hotels['description']);
    }

    public function testAStageWithNoJobsIsDroppedAndTheOthersRenumber(): void
    {
        $modes = self::allModes();
        unset($modes['destinations']);

        $stages = self::plan()->stages($modes, [], [], self::NOW);
        self::assertSame('catalogue', $stages[0]['stage']);
        self::assertSame(1, $stages[0]['number']);
    }

    /** A mode the plan does not know is shown on demand, never lost; diagnostics are on neither list. */
    public function testAnUnknownModeIsShownOnDemandAndADiagnosticNowhere(): void
    {
        $modes = self::allModes();
        unset($modes['geocode']);
        $modes['brand_new_job'] = 'A new command';
        $modes['another_job'] = 'Another one';

        $plan = self::plan();
        $staged = array_keys(self::rowsByMode($plan->stages($modes, [], [], self::NOW)));
        $onDemand = $plan->onDemandRows($modes, [], self::NOW);

        self::assertNotContains('geocode', $staged);
        self::assertSame(['full', 'reassign_features', 'another_job', 'brand_new_job'], array_column($onDemand, 'mode'));
        self::assertNotContains('diagnose_search', [...$staged, ...array_column($onDemand, 'mode')]);
        self::assertTrue($onDemand[0]['labelled']);
        self::assertFalse($onDemand[3]['labelled'], 'a new command has no labels yet: its code and description are shown');
        self::assertSame('A new command', $onDemand[3]['description']);
        self::assertStringNotContainsString('geocode', $plan->crontab($modes, 'cli'));
    }

    public function testTheCrontabIsCliLinesInRunningOrder(): void
    {
        $text = self::plan()->crontab(self::allModes(), 'cli', '2026-11-08');
        $lines = explode("\n", $text);

        self::assertSame('# Sample Holidays: scheduled jobs, in the order they run', $lines[0]);
        self::assertSame('# generated 2026-11-08', $lines[1]);
        self::assertSame('# 1. Reference data', $lines[2]);
        self::assertSame('0 2 * * 0  php /var/www/html/app/addons/sample_holidays/cron.php access_key=' . self::KEY . ' mode=destinations', $lines[3]);
        self::assertSame('# 2. Catalogue', $lines[4]);

        $commands = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '#')));
        self::assertCount(6, $commands);
        foreach ($commands as $line) {
            self::assertMatchesRegularExpression('/^\S+ \S+ \S+ \S+ \S+  php \//', $line);
        }
        self::assertStringNotContainsString('mode=full', $text, 'on-demand jobs have no slot');
        self::assertStringNotContainsString('diagnose_', $text);
    }

    /** The URL form is for a cron service: nothing in it runs if pasted into a crontab. */
    public function testTheUrlFormPutsEachTimeAboveItsUrl(): void
    {
        $lines = explode("\n", self::plan()->crontab(self::allModes(), 'url'));

        self::assertContains('# URLs for a cron service: add each one at the time shown', $lines);
        $i = array_search('http://shop.example/index.php?dispatch=sample_cron.run&access_key=' . self::KEY . '&cron_mode=circuits', $lines, true);
        self::assertIsInt($i);
        self::assertSame('# 15 3 * * *', $lines[$i - 1]);
        foreach ($lines as $line) {
            self::assertTrue(str_starts_with($line, '#') || str_starts_with($line, 'http'), $line);
        }
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function schedules(): iterable
    {
        yield 'every 5 min' => ['*/5 * * * *', 'travel_core.jobs_sched_every_minutes', ['[n]' => '5']];
        yield 'hourly' => ['45 * * * *', 'travel_core.jobs_sched_hourly', ['[minute]' => '45']];
        yield 'every 2 hours' => ['15 */2 * * *', 'travel_core.jobs_sched_every_hours', ['[n]' => '2', '[minute]' => '15']];
        yield 'daily' => ['30 3 * * *', 'travel_core.jobs_sched_daily', ['[time]' => '03:30']];
        yield 'twice a day' => ['0 5,17 * * *', 'travel_core.jobs_sched_twice_daily', ['[time]' => '05:00', '[time2]' => '17:00']];
        yield 'sunday' => ['15 1 * * 0', 'travel_core.jobs_sched_weekly_sun', ['[time]' => '01:15']];
        yield 'monday' => ['0 4 * * 1', 'travel_core.jobs_sched_weekly_mon', ['[time]' => '04:00']];
        yield 'every 3 days' => ['0 2 */3 * *', 'travel_core.jobs_sched_every_days', ['[n]' => '3', '[time]' => '02:00']];
        yield 'day 7 is not a weekday' => ['0 6 * * 7', 'travel_core.jobs_sched_raw', ['[cron]' => '0 6 * * 7']];
        yield 'anything else' => ['0 0 1 1 *', 'travel_core.jobs_sched_raw', ['[cron]' => '0 0 1 1 *']];
        yield 'not cron' => ['nonsense', 'travel_core.jobs_sched_raw', ['[cron]' => 'nonsense']];
    }

    /** @param array<string, string> $params */
    #[DataProvider('schedules')]
    public function testSchedulesReadAsWords(string $cron, string $key, array $params): void
    {
        self::assertSame(['key' => $key, 'params' => $params], CronJobPlan::scheduleWords($cron));
    }

    /** Every word a schedule can turn into is a label in both languages. */
    public function testEveryScheduleWordIsALabel(): void
    {
        /** @var array<string, array<string, string>> $lang */
        $lang = require dirname(__DIR__, 3) . '/lang_keys.php';

        $keys = ['travel_core.jobs_sched_on_demand'];
        foreach (self::schedules() as [$cron]) {
            $keys[] = CronJobPlan::scheduleWords($cron)['key'];
        }
        foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            $keys[] = 'travel_core.jobs_sched_weekly_' . $day;
        }
        foreach (array_unique($keys) as $key) {
            self::assertArrayHasKey($key, $lang);
            self::assertNotSame('', $lang[$key]['en'] ?? '', $key);
            self::assertNotSame('', $lang[$key]['ro'] ?? '', $key);
        }
    }

    public function testHealthFromTheRunLog(): void
    {
        $n = self::NOW;
        self::assertSame('never', SampleJobPlan::health('hotels', [], null, $n)['state']);
        self::assertSame('ok', SampleJobPlan::health('hotels', ['started' => $n - 3600, 'finished' => $n - 3500, 'ok' => true], null, $n)['state']);
        self::assertSame('running', SampleJobPlan::health('hotels', ['started' => $n - 60, 'finished' => $n - 90000, 'ok' => true], null, $n)['state']);
        self::assertSame('stalled', SampleJobPlan::health('hotels', ['started' => $n - CronJobPlan::STALL_AFTER - 1, 'finished' => 0], null, $n)['state']);

        $failed = SampleJobPlan::health('hotels', ['started' => $n - 100, 'finished' => $n - 50, 'ok' => false, 'error' => 'API down'], null, $n);
        self::assertSame(['state' => 'failed', 'at' => $n - 50, 'error' => 'API down'], $failed);
    }

    /** "Late" is each job's own interval: 3 hours is late for a 15-minute job and fine for a daily one. */
    public function testLateIsMeasuredAgainstTheJobsOwnInterval(): void
    {
        $record = ['started' => self::NOW - 3 * 3600 - 10, 'finished' => self::NOW - 3 * 3600, 'ok' => true];

        self::assertSame('late', SampleJobPlan::health('order_status', $record, null, self::NOW)['state']);
        self::assertSame('ok', SampleJobPlan::health('hotels', $record, null, self::NOW)['state']);
        self::assertSame('late', SampleJobPlan::health('hotels', ['started' => self::NOW - 49 * 3600 - 5, 'finished' => self::NOW - 49 * 3600, 'ok' => true], null, self::NOW)['state']);
        // On-demand jobs have no interval, so they are never late.
        self::assertSame('ok', SampleJobPlan::health('full', ['started' => 1, 'finished' => 2, 'ok' => true], null, self::NOW)['state']);
    }

    /** Before the run log saw a job, its last sync_log row speaks for it; after, the run log wins. */
    public function testTheSyncLogIsOnlyAFallback(): void
    {
        $legacy = ['at' => self::NOW - 600, 'ok' => false];

        self::assertSame(['state' => 'failed', 'at' => self::NOW - 600, 'error' => ''], SampleJobPlan::health('hotels', [], $legacy, self::NOW));
        self::assertSame('ok', SampleJobPlan::health('hotels', [], ['at' => self::NOW - 600, 'ok' => true], self::NOW)['state']);
        self::assertSame('never', SampleJobPlan::health('hotels', [], ['at' => 0, 'ok' => true], self::NOW)['state']);
        self::assertSame('ok', SampleJobPlan::health('hotels', ['started' => self::NOW - 20, 'finished' => self::NOW - 10, 'ok' => true], $legacy, self::NOW)['state']);

        self::assertSame(['hotels', 'hotel_sync'], SampleJobPlan::legacyLogTypes('hotels'));
        self::assertSame(['cleanup'], SampleJobPlan::legacyLogTypes('cleanup'));
    }

    /** The rows feed the ⋯ menu: which extra actions a job answers, and its real commands. */
    public function testRowsSayWhichExtraActionsAJobHas(): void
    {
        $plan = self::plan();
        $rows = self::rowsByMode($plan->stages(self::allModes(), ['hotels' => ['started' => self::NOW - 20, 'finished' => self::NOW - 10, 'ok' => true]], [], self::NOW));

        self::assertTrue($rows['hotels']['recommended']);
        self::assertTrue($rows['destinations']['batched']);
        self::assertTrue($rows['destinations']['has_status'], 'a batched job answers status=1 too');
        self::assertFalse($rows['destinations']['redo_all'], 'a batched job has "Force full sync" instead');
        self::assertTrue($rows['geocode']['has_status']);
        self::assertTrue($rows['geocode']['redo_all']);
        self::assertFalse($rows['geocode']['batched']);
        self::assertFalse($rows['cleanup']['has_status']);
        self::assertFalse($rows['cleanup']['recommended']);

        self::assertSame('ok', $rows['hotels']['state']);
        self::assertSame(self::NOW - 10, $rows['hotels']['at']);
        self::assertSame('travel_core.jobs_sched_daily', $rows['hotels']['schedule_key']);
        self::assertSame(['[time]' => '03:00'], $rows['hotels']['schedule_params']);
        self::assertSame('0 3 * * *  ' . $plan->cli('hotels'), $rows['hotels']['crontab_line']);
        self::assertSame($plan->url('hotels'), $rows['hotels']['url']);
        self::assertSame($plan->cli('hotels'), $rows['hotels']['cli']);

        $onDemand = $plan->onDemandRows(self::allModes(), [], self::NOW);
        self::assertSame('travel_core.jobs_sched_on_demand', $onDemand[0]['schedule_key']);
        self::assertSame('', $onDemand[0]['crontab_line'], 'a job with no slot has no crontab line');
        self::assertSame('', SampleJobPlan::cron('full'));
    }
}
