<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\CronDispatcher;
use Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder;

/**
 * The dashboard's scheduled-jobs table: one row per job, in the order the
 * jobs depend on each other, each with a schedule, its last run and its
 * URL / CLI commands.
 */
final class CronPlanBuilderTest extends TestCase
{
    private const string KEY = 'k3y-for-tests';

    private const int NOW = 1_790_000_000;

    private static function builder(string $dirRoot = '/var/www/html'): CronPlanBuilder
    {
        return new CronPlanBuilder('http://shop.example/', self::KEY, $dirRoot);
    }

    /** @return array<string, string> every staged and on-demand mode, as the dispatcher lists them */
    private static function allModes(): array
    {
        $modes = [];
        foreach ([...array_merge(...array_values(CronPlanBuilder::STAGES)), ...CronPlanBuilder::ON_DEMAND, ...CronPlanBuilder::DIAGNOSTIC] as $mode) {
            $modes[$mode] = 'desc ' . $mode;
        }

        return $modes;
    }

    /** @return list<string> */
    private static function flatten(array $stages): array
    {
        $out = [];
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $out[] = $job['mode'];
            }
        }

        return $out;
    }

    public function testJobsComeInTheOrderTheyRun(): void
    {
        $stages = self::builder()->stages(self::allModes(), [], [], self::NOW);

        self::assertSame(['reference', 'hotels', 'prices', 'products', 'upkeep'], array_column($stages, 'stage'));
        self::assertSame([1, 2, 3, 4, 5], array_column($stages, 'number'));
        self::assertSame([
            'resort_list', 'list_facilities',
            'hotel_list', 'hotel_info_batched', 'hotel_facilities_batched', 'geocode_addresses',
            'sync_priceinfo_batched', 'compute_prices', 'recompute_calendar_prices', 'room_price',
            'add_hotels_as_products', 'reassign_features', 'backfill_images', 'offers_update',
            'resinfo', 'cleanup',
        ], self::flatten($stages));
    }

    /** The prerequisites the order stands on, stated in the commands themselves. */
    public function testEachJobComesAfterWhatItNeeds(): void
    {
        $order = array_flip(self::flatten(self::builder()->stages(self::allModes(), [], [], self::NOW)));

        foreach ([
            ['hotel_list', 'hotel_info_batched'],
            ['hotel_list', 'sync_priceinfo_batched'],
            ['list_facilities', 'hotel_facilities_batched'],
            ['sync_priceinfo_batched', 'compute_prices'],
            ['room_price', 'add_hotels_as_products'],
            ['add_hotels_as_products', 'offers_update'],
            ['add_hotels_as_products', 'reassign_features'],
            ['add_hotels_as_products', 'backfill_images'],
        ] as [$first, $then]) {
            self::assertLessThan($order[$then], $order[$first], "{$first} must come before {$then}");
        }
    }

    /** Daily and weekly slots follow the list order, so the times read in the order the jobs run. */
    public function testDailyTimesFollowTheListOrder(): void
    {
        $last = -1;
        foreach (self::flatten(self::builder()->stages(self::allModes(), [], [], self::NOW)) as $mode) {
            [$minute, $hour, $dom] = explode(' ', CronPlanBuilder::cron($mode));
            if (!ctype_digit($minute) || !ctype_digit($hour)) {
                continue; // repeats through the day
            }
            $at = (int) $hour * 60 + (int) $minute;
            self::assertGreaterThan($last, $at, "{$mode} ({$dom}) runs before the job listed above it");
            $last = $at;
        }
    }

    public function testEveryScheduledJobHasASlot(): void
    {
        foreach (array_merge(...array_values(CronPlanBuilder::STAGES)) as $mode) {
            self::assertNotSame('', CronPlanBuilder::cron($mode), $mode);
            self::assertNotSame('novoton_holidays.dash_sched_raw', CronPlanBuilder::scheduleWords(CronPlanBuilder::cron($mode))['key'], "{$mode} has no words for its schedule");
        }
    }

    /**
     * Every mode the dispatcher has is on the page — in a stage, on demand,
     * or a diagnostic on purpose. A new command lands on demand until someone
     * schedules it.
     */
    public function testEveryDispatcherModeIsAccountedFor(): void
    {
        $modes = CronDispatcher::getAvailableModes();
        self::assertNotSame([], $modes);

        $b = self::builder();
        $shown = [...self::flatten($b->stages($modes, [], [], self::NOW)), ...array_column($b->onDemandRows($modes, [], self::NOW), 'mode')];

        foreach (array_keys($modes) as $mode) {
            if (in_array($mode, CronPlanBuilder::DIAGNOSTIC, true)) {
                self::assertNotContains($mode, $shown);
                continue;
            }
            self::assertContains($mode, $shown, "mode {$mode} is on neither list");
        }
    }

    public function testAnUnknownModeIsShownOnDemandAndAMissingOneIsSkipped(): void
    {
        $modes = self::allModes();
        unset($modes['geocode_addresses']);
        $modes['brand_new_job'] = 'A new command';

        $b = self::builder();
        self::assertNotContains('geocode_addresses', self::flatten($b->stages($modes, [], [], self::NOW)));
        $onDemand = array_column($b->onDemandRows($modes, [], self::NOW), 'mode');
        self::assertSame([...CronPlanBuilder::ON_DEMAND, 'brand_new_job'], $onDemand);
        $rows = $b->onDemandRows($modes, [], self::NOW);
        self::assertTrue($rows[0]['labelled']);
        self::assertFalse(end($rows)['labelled'], 'a new command has no labels yet: its code and description are shown');
        self::assertSame('A new command', end($rows)['description']);
        self::assertStringNotContainsString('geocode_addresses', $b->crontab($modes, 'cli'));
    }

    public function testAStageWithNoJobsIsDroppedAndTheOthersRenumber(): void
    {
        $modes = self::allModes();
        unset($modes['resort_list'], $modes['list_facilities']);

        $stages = self::builder()->stages($modes, [], [], self::NOW);
        self::assertSame('hotels', $stages[0]['stage']);
        self::assertSame(1, $stages[0]['number']);
    }

    public function testCommandsCarryTheRealKeyAndAnAbsolutePath(): void
    {
        $b = self::builder('/var/www/html/');

        self::assertSame('http://shop.example/index.php?dispatch=novoton_cron.run&access_key=' . self::KEY . '&mode=hotel_list', $b->url('hotel_list'));
        self::assertSame('php /var/www/html/app/addons/novoton_holidays/cron.php access_key=' . self::KEY . ' mode=hotel_list', $b->cli('hotel_list'));
        self::assertSame('php app/addons/novoton_holidays/cron.php access_key=' . self::KEY . ' mode=cleanup', self::builder('')->cli('cleanup'));

        $row = $b->stages(self::allModes(), [], [], self::NOW)[0]['jobs'][0];
        self::assertSame('0 1 * * 0  ' . $b->cli('resort_list'), $row['crontab_line']);
    }

    public function testNoKeyMeansNoCommands(): void
    {
        self::assertFalse((new CronPlanBuilder('http://shop.example', '  '))->hasKey());
        self::assertTrue(self::builder()->hasKey());
    }

    public function testTheCrontabIsCliLinesInRunningOrder(): void
    {
        $text = self::builder()->crontab(self::allModes(), 'cli', '2026-09-24');
        $lines = explode("\n", $text);

        self::assertSame('# Novoton Holidays: scheduled jobs, in the order they run', $lines[0]);
        self::assertSame('# generated 2026-09-24', $lines[1]);
        self::assertSame('# 1. Reference data', $lines[2]);
        self::assertSame('0 1 * * 0  php /var/www/html/app/addons/novoton_holidays/cron.php access_key=' . self::KEY . ' mode=resort_list', $lines[3]);

        $commands = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '#')));
        self::assertCount(16, $commands);
        foreach ($commands as $line) {
            self::assertMatchesRegularExpression('/^\S+ \S+ \S+ \S+ \S+  php \//', $line);
        }
        self::assertStringNotContainsString('mode=full', $text, 'full repeats the staged jobs; scheduling both runs them twice');
        self::assertStringNotContainsString('diagnose_', $text);
    }

    /** The URL form is for a cron service: nothing in it runs if pasted into a crontab. */
    public function testTheUrlFormPutsEachTimeAboveItsUrl(): void
    {
        $lines = explode("\n", self::builder()->crontab(self::allModes(), 'url'));

        self::assertContains('# URLs for a cron service: add each one at the time shown', $lines);
        $i = array_search('http://shop.example/index.php?dispatch=novoton_cron.run&access_key=' . self::KEY . '&mode=room_price', $lines, true);
        self::assertIsInt($i);
        self::assertSame('# 0 4 * * *', $lines[$i - 1]);
        foreach ($lines as $line) {
            self::assertTrue(str_starts_with($line, '#') || str_starts_with($line, 'http'), $line);
        }
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function schedules(): iterable
    {
        yield 'every 5 min' => ['*/5 * * * *', 'novoton_holidays.dash_sched_every_minutes', ['[n]' => '5']];
        yield 'hourly' => ['45 * * * *', 'novoton_holidays.dash_sched_hourly', ['[minute]' => '45']];
        yield 'every 2 hours' => ['15 */2 * * *', 'novoton_holidays.dash_sched_every_hours', ['[n]' => '2', '[minute]' => '15']];
        yield 'daily' => ['30 3 * * *', 'novoton_holidays.dash_sched_daily', ['[time]' => '03:30']];
        yield 'sunday' => ['15 1 * * 0', 'novoton_holidays.dash_sched_weekly_sun', ['[time]' => '01:15']];
        yield 'every 3 days' => ['0 2 */3 * *', 'novoton_holidays.dash_sched_every_days', ['[n]' => '3', '[time]' => '02:00']];
        yield 'anything else' => ['0 0 1 1 *', 'novoton_holidays.dash_sched_raw', ['[cron]' => '0 0 1 1 *']];
        yield 'not cron' => ['nonsense', 'novoton_holidays.dash_sched_raw', ['[cron]' => 'nonsense']];
    }

    /** @param array<string, string> $params */
    #[DataProvider('schedules')]
    public function testSchedulesReadAsWords(string $cron, string $key, array $params): void
    {
        self::assertSame(['key' => $key, 'params' => $params], CronPlanBuilder::scheduleWords($cron));
    }

    public function testHealthFromTheRunLog(): void
    {
        $n = self::NOW;
        self::assertSame('never', CronPlanBuilder::health('room_price', [], null, $n)['state']);
        self::assertSame('ok', CronPlanBuilder::health('room_price', ['started' => $n - 3600, 'finished' => $n - 3500, 'ok' => true], null, $n)['state']);
        self::assertSame('running', CronPlanBuilder::health('room_price', ['started' => $n - 60, 'finished' => $n - 90000, 'ok' => true], null, $n)['state']);
        self::assertSame('stalled', CronPlanBuilder::health('room_price', ['started' => $n - CronPlanBuilder::STALL_AFTER - 1, 'finished' => 0], null, $n)['state']);

        $failed = CronPlanBuilder::health('room_price', ['started' => $n - 100, 'finished' => $n - 50, 'ok' => false, 'error' => 'API down'], null, $n);
        self::assertSame(['state' => 'failed', 'at' => $n - 50, 'error' => 'API down'], $failed);
    }

    /** "Late" is each job's own interval: 3 hours is late for a 5-minute job and fine for a daily one. */
    public function testLateIsMeasuredAgainstTheJobsOwnInterval(): void
    {
        $record = ['started' => self::NOW - 3 * 3600 - 10, 'finished' => self::NOW - 3 * 3600, 'ok' => true];

        self::assertSame('late', CronPlanBuilder::health('hotel_info_batched', $record, null, self::NOW)['state']);
        self::assertSame('ok', CronPlanBuilder::health('room_price', $record, null, self::NOW)['state']);
        self::assertSame('late', CronPlanBuilder::health('room_price', ['started' => self::NOW - 49 * 3600 - 5, 'finished' => self::NOW - 49 * 3600, 'ok' => true], null, self::NOW)['state']);
        // On-demand jobs have no interval, so they are never late.
        self::assertSame('ok', CronPlanBuilder::health('full', ['started' => 1, 'finished' => 2, 'ok' => true], null, self::NOW)['state']);
    }

    /** Before the run log saw a job, its last sync_log row speaks for it; after, the run log wins. */
    public function testTheSyncLogIsOnlyAFallback(): void
    {
        $legacy = ['at' => self::NOW - 600, 'ok' => false];

        self::assertSame(['state' => 'failed', 'at' => self::NOW - 600, 'error' => ''], CronPlanBuilder::health('room_price', [], $legacy, self::NOW));
        self::assertSame('ok', CronPlanBuilder::health('room_price', [], ['at' => self::NOW - 600, 'ok' => true], self::NOW)['state']);
        self::assertSame('never', CronPlanBuilder::health('room_price', [], ['at' => 0, 'ok' => true], self::NOW)['state']);
        self::assertSame('ok', CronPlanBuilder::health('room_price', ['started' => self::NOW - 20, 'finished' => self::NOW - 10, 'ok' => true], $legacy, self::NOW)['state']);

        self::assertSame(['hotel_info_batched', 'hotelinfo'], CronPlanBuilder::legacyLogTypes('hotel_info_batched'));
        self::assertSame(['room_price'], CronPlanBuilder::legacyLogTypes('room_price'));
    }

    public function testRowsSayWhichExtraActionsAJobHas(): void
    {
        $rows = [];
        foreach (self::builder()->stages(self::allModes(), [], [], self::NOW) as $stage) {
            foreach ($stage['jobs'] as $job) {
                $rows[$job['mode']] = $job;
            }
        }

        self::assertTrue($rows['hotel_info_batched']['recommended']);
        self::assertTrue($rows['hotel_info_batched']['batched']);
        self::assertTrue($rows['geocode_addresses']['has_status']);
        self::assertFalse($rows['geocode_addresses']['batched']);
        self::assertFalse($rows['cleanup']['has_status']);
        self::assertFalse($rows['cleanup']['recommended']);

        $onDemand = self::builder()->onDemandRows(self::allModes(), [], self::NOW);
        self::assertSame('novoton_holidays.dash_sched_on_demand', $onDemand[0]['schedule_key']);
        self::assertSame('', $onDemand[0]['crontab_line']);
    }
}
