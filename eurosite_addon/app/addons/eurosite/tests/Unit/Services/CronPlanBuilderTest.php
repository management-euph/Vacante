<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * The dashboard's scheduling rules, tested away from Smarty and the database.
 *
 * What these pin is everything the old page got wrong or could not say:
 * the order jobs really run in, whether a catalog is stale, whether a command
 * can work at all, and what a pasteable crontab looks like.
 */
#[CoversClass(CronPlanBuilder::class)]
final class CronPlanBuilderTest extends TestCase
{
    private const KEY = 'abc123';

    /** Every mode the dispatcher registers, in the alphabetical order it hands them over. */
    private const MODES = [
        'cities' => 'Sync city catalogs',
        'cleanup' => 'Trim the sync log',
        'countries' => 'Sync the country catalog',
        'full' => 'Run every static-data sync in order',
        'hotels' => 'Sync own hotels + rooms',
        'own_cities' => 'Sync cities with own offers',
        'product_info' => 'Warm the product-details cache',
        'room_types' => 'Sync the room-type catalog',
        'tags' => 'Sync the offer-tag catalog',
    ];

    private function builder(string $key = self::KEY): CronPlanBuilder
    {
        return new CronPlanBuilder('https://shop.example.ro', $key);
    }

    /**
     * @return list<string>
     */
    private function modesOf(CronPlanBuilder $plan): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['mode'],
            $plan->rows(self::MODES, [], []),
        );
    }

    // ── Ordering ─────────────────────────────────────────────────────────

    /**
     * The dispatcher hands modes over alphabetically, which puts `cleanup`
     * second — reading as though housekeeping runs before anything is synced.
     * Rows follow FullSyncCommand's real sequence instead.
     */
    public function testRowsFollowThePipelineOrderNotTheAlphabet(): void
    {
        self::assertSame(
            ['countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags', 'product_info', 'cleanup'],
            $this->modesOf($this->builder()),
        );
    }

    public function testFullIsNeverARowBecauseItIsNotACatalog(): void
    {
        self::assertNotContains('full', $this->modesOf($this->builder()));
    }

    public function testAnUnknownModeStillGetsARowAtTheEnd(): void
    {
        $modes = self::MODES + ['experiments' => 'Something new'];
        $rows = $this->builder()->rows($modes, [], []);
        $modeNames = array_map(static fn (array $r): string => (string) $r['mode'], $rows);

        self::assertContains('experiments', $modeNames, 'a newly registered command must not vanish');
        self::assertSame('experiments', end($modeNames));

        $row = $rows[array_search('experiments', $modeNames, true)];
        self::assertSame('0 4 * * *', $row['schedule_cron'], 'unknown modes take the fallback slot');
    }

    // ── Commands ─────────────────────────────────────────────────────────

    public function testUrlAndCliCarryTheKeyAndTheMode(): void
    {
        $plan = $this->builder();

        self::assertSame(
            'https://shop.example.ro/index.php?dispatch=eurosite_cron.run&access_key=abc123&cron_mode=hotels',
            $plan->url('hotels'),
        );
        self::assertSame(
            'php app/addons/eurosite/cron.php access_key=abc123 mode=hotels',
            $plan->cli('hotels'),
        );
    }

    public function testTheBaseUrlIsJoinedWithExactlyOneSlash(): void
    {
        $withSlash = new CronPlanBuilder('https://shop.example.ro/', self::KEY);

        self::assertStringStartsWith('https://shop.example.ro/index.php?', $withSlash->url('cities'));
        self::assertStringNotContainsString('ro//index.php', $withSlash->url('cities'));
    }

    public function testAKeyWithUrlSpecialCharactersIsEncodedInTheUrlButNotTheCliCommand(): void
    {
        $plan = new CronPlanBuilder('https://shop.example.ro', 'a b&c=d');

        self::assertStringContainsString('access_key=a%20b%26c%3Dd&cron_mode=cities', $plan->url('cities'));
        // The shell command takes the raw value: percent-encoding it there
        // would send a different key than the one stored.
        self::assertStringContainsString('access_key=a b&c=d mode=cities', $plan->cli('cities'));
    }

    /**
     * REGRESSION: the live store rendered `access_key=&cron_mode=cities` nine
     * times over. Every one of those 403s, because eurosite_cron.php refuses
     * outright when no key is stored — so the page must not offer commands.
     */
    public function testHasKeyIsFalseForAnEmptyOrBlankKey(): void
    {
        self::assertFalse($this->builder('')->hasKey());
        self::assertFalse($this->builder('   ')->hasKey());
        self::assertTrue($this->builder()->hasKey());
    }

    // ── Schedules ────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function scheduleWordings(): array
    {
        return [
            'weekly sunday'  => ['0 2 * * 0', 'Sun 02:00'],
            'weekly with minutes' => ['30 1 * * 0', 'Sun 01:30'],
            'weekly friday'  => ['45 3 * * 5', 'Fri 03:45'],
            'daily'          => ['0 3 * * *', 'Daily 03:00'],
            'daily midnight' => ['0 0 * * *', 'Daily 00:00'],
            // Shapes this class never generates are handed back untouched
            // rather than mistranslated.
            'step hours'     => ['0 */4 * * *', '0 */4 * * *'],
            'day of month'   => ['0 1 15 * *', '0 1 15 * *'],
            'range of days'  => ['0 1 * * 1-5', '0 1 * * 1-5'],
            'not five fields' => ['0 1 * *', '0 1 * *'],
            'empty'          => ['', ''],
        ];
    }

    #[DataProvider('scheduleWordings')]
    public function testHumanScheduleReadsTheCronExpression(string $cron, string $expected): void
    {
        self::assertSame($expected, CronPlanBuilder::humanSchedule($cron));
    }

    public function testEveryRowCarriesBothTheWordingAndTheRawExpression(): void
    {
        foreach ($this->builder()->rows(self::MODES, [], []) as $row) {
            self::assertNotSame('', $row['schedule_cron'], $row['mode'] . ' has no slot');
            self::assertNotSame('', $row['schedule_human'], $row['mode'] . ' has no wording');
            // A crontab line must be a runnable command: the CLI one, never a bare URL.
            self::assertSame(
                $row['schedule_cron'] . '  ' . $row['cli'],
                $row['crontab_line'],
                'the per-row crontab line must be the slot plus the CLI command',
            );
            self::assertStringNotContainsString('curl', $row['crontab_line']);
        }
    }

    // ── Health ───────────────────────────────────────────────────────────

    private const NOW = 1_758_000_000; // fixed clock, so "6h ago" is not flaky

    /**
     * @param array<string, mixed> $last
     *
     * @return array<string, mixed>
     */
    private function rowFor(string $mode, array $last): array
    {
        $rows = $this->builder()->rows(self::MODES, [], [$mode => $last], self::NOW);
        foreach ($rows as $row) {
            if ($row['mode'] === $mode) {
                return $row;
            }
        }

        self::fail("no row for {$mode}");
    }

    private static function at(int $secondsAgo): string
    {
        return date('Y-m-d H:i:s', self::NOW - $secondsAgo);
    }

    public function testAJobThatNeverRanSaysSo(): void
    {
        // No log row at all...
        $rows = $this->builder()->rows(self::MODES, [], [], self::NOW);
        foreach ($rows as $row) {
            self::assertSame('never', $row['state'], $row['mode'] . ' has never run');
            self::assertSame('never run', $row['state_label']);
        }

        // ...and an empty row, which the log can hand back, reads the same.
        $empty = $this->rowFor('hotels', []);
        self::assertSame('never', $empty['state']);
        self::assertSame('never run', $empty['state_label']);
    }

    public function testARecentCompletedRunIsOk(): void
    {
        $row = $this->rowFor('hotels', ['status' => 'completed', 'started_at' => self::at(6 * 3600)]);

        self::assertSame('ok', $row['state']);
        self::assertSame('6h ago', $row['state_label']);
    }

    /**
     * Staleness is measured against the job's OWN interval: 3 days is fine for
     * a weekly catalog and badly overdue for a daily one. A single global
     * threshold would either cry wolf weekly or never fire.
     */
    public function testOverdueIsRelativeToTheJobsOwnInterval(): void
    {
        $threeDays = ['status' => 'completed', 'started_at' => self::at(3 * 86400)];

        self::assertSame('ok', $this->rowFor('cities', $threeDays)['state'], 'weekly job, 3 days old');
        self::assertSame('overdue', $this->rowFor('hotels', $threeDays)['state'], 'daily job, 3 days old');

        // The threshold is twice the interval, so a weekly job tips over at
        // 14 days: 14 exactly is still fine, 15 is not.
        $exactly = ['status' => 'completed', 'started_at' => self::at(14 * 86400)];
        self::assertSame('ok', $this->rowFor('cities', $exactly)['state'], 'weekly job, exactly 2 intervals');

        $past = ['status' => 'completed', 'started_at' => self::at(15 * 86400)];
        self::assertSame('overdue', $this->rowFor('cities', $past)['state'], 'weekly job, past 2 intervals');
        self::assertSame('15d ago', $this->rowFor('cities', $past)['state_label']);
    }

    public function testAFailedRunOutranksItsAge(): void
    {
        $row = $this->rowFor('cities', ['status' => 'failed', 'started_at' => self::at(600)]);

        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('failed', $row['state_label']);
    }

    public function testAnUnparseableTimestampDoesNotBecomeAFalseAge(): void
    {
        $row = $this->rowFor('cities', ['status' => 'completed', 'started_at' => 'not a date']);

        self::assertSame('ok', $row['state']);
        self::assertSame('completed', $row['state_label'], 'no timestamp means no "ago"');
    }

    public function testCountsAreMappedOntoTheirRowsIncludingTheCacheAlias(): void
    {
        $counts = ['countries' => 86, 'cities' => 1277, 'cache' => 42];
        $rows = $this->builder()->rows(self::MODES, $counts, []);
        $byMode = [];
        foreach ($rows as $row) {
            $byMode[(string) $row['mode']] = $row['count'];
        }

        self::assertSame(86, $byMode['countries']);
        self::assertSame(1277, $byMode['cities']);
        // product_info syncs the cache, which is counted under 'cache'.
        self::assertSame(42, $byMode['product_info']);
        self::assertNull($byMode['cleanup'], 'a job with no catalog shows no row count');
    }

    // ── Crontab ──────────────────────────────────────────────────────────

    public function testTheNightlyPlanSchedulesThePipelineOnceAndNotItsSteps(): void
    {
        $modes = $this->builder()->plannedModes('full', self::MODES);

        self::assertSame(['full', 'product_info', 'cleanup'], $modes);
        foreach (CronPlanBuilder::PIPELINE as $step) {
            self::assertNotContains($step, $modes, "{$step} runs inside full — scheduling it twice doubles the API calls");
        }
    }

    public function testThePerCatalogPlanSchedulesEveryJobIndividually(): void
    {
        self::assertSame(
            ['countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags', 'product_info', 'cleanup'],
            $this->builder()->plannedModes('per', self::MODES),
        );
    }

    public function testTheCrontabIsPasteableWithOneCommandPerLine(): void
    {
        $text = $this->builder()->crontab('full', 'cli', self::MODES, '21 Sep 2026');
        $lines = explode("\n", $text);

        self::assertSame('# Eurosite Touring — nightly full pipeline', $lines[0]);
        self::assertSame('# generated 21 Sep 2026', $lines[1]);
        self::assertCount(5, $lines, 'two comments plus full, product_info, cleanup');

        foreach (array_slice($lines, 2) as $line) {
            self::assertMatchesRegularExpression(
                '/^[\d*\/ ,-]+\s{2}php app\/addons\/eurosite\/cron\.php access_key=abc123 mode=[a-z_]+$/',
                $line,
            );
        }
        self::assertStringContainsString('mode=full', $lines[2]);
    }

    /**
     * The URL form is the plain address, for a cron service or the browser:
     * no curl wrapper. It is not a crontab, so every schedule sits in a
     * comment and the only uncommented lines are the URLs themselves.
     */
    public function testTheUrlFormListsPlainUrlsUnderTheirSlot(): void
    {
        $text = $this->builder()->crontab('full', 'url', self::MODES, '21 Sep 2026');
        $lines = explode("\n", $text);

        self::assertStringNotContainsString('curl', $text);
        self::assertStringNotContainsString('/dev/null', $text);
        self::assertSame('# URLs for a cron service: add each one at the time shown', $lines[2]);
        self::assertSame('# 0 1 * * * (' . CronPlanBuilder::humanSchedule('0 1 * * *') . ')', $lines[3]);
        self::assertSame($this->builder()->url('full'), $lines[4]);
        foreach ($lines as $line) {
            self::assertTrue(
                str_starts_with($line, '#') || str_starts_with($line, 'https://shop.example.ro/index.php?'),
                "not a comment and not a plain URL: {$line}",
            );
        }
    }

    public function testCliLinesAreAbsoluteWhenTheStoreRootIsKnown(): void
    {
        $plan = new CronPlanBuilder('https://shop.example.ro', self::KEY, '/home/shop/public_html/');

        self::assertSame(
            'php /home/shop/public_html/app/addons/eurosite/cron.php access_key=' . self::KEY . ' mode=hotels',
            $plan->cli('hotels'),
        );
    }

    public function testTheCliFormatUsesTheAddonsCronScript(): void
    {
        $text = $this->builder()->crontab('per', 'cli', self::MODES);

        self::assertStringNotContainsString('curl', $text);
        self::assertStringContainsString('0 1 * * 0  php app/addons/eurosite/cron.php access_key=abc123 mode=countries', $text);
        self::assertStringContainsString('# Eurosite Touring — per-catalog schedule', $text);
        self::assertStringNotContainsString('# generated', $text, 'no date was supplied');
    }

    /**
     * The masking in the browser works by replacing the key string in the
     * text, so the key must appear verbatim in every generated command.
     */
    public function testEveryGeneratedLineContainsTheKeyVerbatimSoTheUiCanMaskIt(): void
    {
        foreach (['full', 'per'] as $plan) {
            foreach (['url', 'cli'] as $format) {
                $text = $this->builder()->crontab($plan, $format, self::MODES);
                foreach (explode("\n", $text) as $line) {
                    if (str_starts_with($line, '#')) {
                        continue;
                    }
                    self::assertStringContainsString(self::KEY, $line, "{$plan}/{$format}: {$line}");
                }
            }
        }
    }
}
