<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\CronHealth;

/**
 * Every verdict the Tools page shows for an add-on, one rule per test.
 */
#[CoversClass(CronHealth::class)]
final class CronHealthTest extends TestCase
{
    private const int NOW = 10_000_000;

    /** @return array{started: int, finished: int, ok: bool, via: string} */
    private static function rec(int $agoStarted, int $agoFinished, bool $ok, string $via = 'cli'): array
    {
        return ['started' => self::NOW - $agoStarted, 'finished' => self::NOW - $agoFinished, 'ok' => $ok, 'via' => $via];
    }

    public function testNothingRecordedIsNeverNotOk(): void
    {
        self::assertSame(CronHealth::NEVER, CronHealth::assess([], 0, self::NOW)['state']);
    }

    public function testRecentSuccessfulScheduledRunsAreOk(): void
    {
        $h = CronHealth::assess(['hotels' => self::rec(3700, 3600, true)], 0, self::NOW);

        self::assertSame(CronHealth::OK, $h['state']);
        self::assertSame(['mode' => 'hotels', 'at' => self::NOW - 3600, 'ok' => true], $h['last']);
    }

    /** The botched-rotation diagnosis: refused with a wrong key after the last good run. */
    public function testAWrongKeyAfterTheLastGoodRunIsRefused(): void
    {
        $h = CronHealth::assess(['hotels' => self::rec(90_000, 89_000, true)], self::NOW - 600, self::NOW);

        self::assertSame(CronHealth::REFUSED, $h['state']);
    }

    /** A refusal followed by a good scheduled run is history: the crontab was fixed. */
    public function testAWrongKeyBeforeTheLastGoodRunIsNotRefused(): void
    {
        $h = CronHealth::assess(['hotels' => self::rec(700, 600, true)], self::NOW - 5000, self::NOW);

        self::assertSame(CronHealth::OK, $h['state']);
    }

    /** Pressing "Sync now" in the admin must not hide a crontab that is refused. */
    public function testAnAdminRunDoesNotClearARefusal(): void
    {
        $h = CronHealth::assess(['hotels' => self::rec(120, 60, true, 'admin')], self::NOW - 600, self::NOW);

        self::assertSame(CronHealth::REFUSED, $h['state']);
    }

    public function testAFailedLatestRunIsFailedAndNamed(): void
    {
        $h = CronHealth::assess([
            'hotels' => self::rec(700, 600, false),
            'circuits' => self::rec(700, 600, true),
        ], 0, self::NOW);

        self::assertSame(CronHealth::FAILED, $h['state']);
        self::assertSame(['hotels'], $h['failed']);
    }

    public function testAnOldFailureIsNoLongerCurrent(): void
    {
        $old = CronHealth::RELEVANT_FOR + 100;
        $h = CronHealth::assess([
            'hotels' => self::rec($old + 10, $old, false),
            'circuits' => self::rec(700, 600, true),
        ], 0, self::NOW);

        self::assertSame(CronHealth::OK, $h['state']);
    }

    public function testARunThatNeverFinishedIsStalledOnceItIsTooOld(): void
    {
        $open = ['started' => self::NOW - CronHealth::STALL_AFTER - 1, 'finished' => self::NOW - 90_000, 'ok' => true, 'via' => 'cli'];

        $h = CronHealth::assess(['full' => $open], 0, self::NOW);
        self::assertSame(CronHealth::STALLED, $h['state']);
        self::assertSame(['full'], $h['stalled']);
    }

    public function testAnOpenRecentRunIsRunningNotStalled(): void
    {
        $open = ['started' => self::NOW - 60, 'finished' => self::NOW - 90_000, 'ok' => true, 'via' => 'cli'];

        $h = CronHealth::assess(['full' => $open], 0, self::NOW);
        self::assertSame(CronHealth::OK, $h['state']);
        self::assertSame(['full'], $h['running']);
    }

    public function testNoScheduledRunForTwoDaysIsQuiet(): void
    {
        $long = CronHealth::QUIET_AFTER + 100;
        $h = CronHealth::assess(['hotels' => self::rec($long + 50, $long, true)], 0, self::NOW);

        self::assertSame(CronHealth::QUIET, $h['state']);
    }

    /** Only admin clicks for two days still means the crontab is not running. */
    public function testAdminRunsAloneAreQuiet(): void
    {
        $h = CronHealth::assess(['hotels' => self::rec(120, 60, true, 'admin')], 0, self::NOW);

        self::assertSame(CronHealth::QUIET, $h['state']);
    }

    public function testRefusedOutranksFailed(): void
    {
        $h = CronHealth::assess(['hotels' => self::rec(700, 600, false)], self::NOW - 60, self::NOW);

        self::assertSame(CronHealth::REFUSED, $h['state']);
    }

    public function testLastActivityIsTheMostRecentFinishedRun(): void
    {
        $h = CronHealth::assess([
            'hotels' => self::rec(9000, 8000, true),
            'circuits' => self::rec(700, 600, false),
        ], 0, self::NOW);

        self::assertSame('circuits', $h['last']['mode'] ?? null);
        self::assertFalse($h['last']['ok'] ?? true);
    }

    public function testOnlyActionableStatesNeedAttention(): void
    {
        foreach ([CronHealth::REFUSED, CronHealth::FAILED, CronHealth::STALLED, CronHealth::QUIET] as $s) {
            self::assertTrue(CronHealth::needsAttention($s), $s);
        }
        self::assertFalse(CronHealth::needsAttention(CronHealth::OK));
        self::assertFalse(CronHealth::needsAttention(CronHealth::NEVER), 'nothing recorded yet is not a fault');
    }

    public function testListsNameTheJobsBehindEachVerdict(): void
    {
        self::assertSame(
            ['failed_list' => 'hotels, circuits', 'stalled_list' => 'offers', 'running_list' => ''],
            CronHealth::lists(['failed' => ['hotels', 'circuits'], 'stalled' => ['offers'], 'running' => []]),
        );
    }
}
