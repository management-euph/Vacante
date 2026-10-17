<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * The nightly cleanup kept the newest 100 log rows, whatever they were. With
 * cron jobs called by URL (one row per run, ~900 a day) that was the last 2-3
 * hours, so the rows the batched syncs read — "when did my last full run
 * finish?" — were deleted every night and the next tick started a full cycle.
 * prune() keeps days instead of rows, and never deletes a sync's last
 * finished (or last full) run.
 */
#[CoversClass(SyncLogRepository::class)]
final class SyncLogPruneTest extends TestCase
{
    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param list<int> $lastRuns ids the keep-query returns
     *
     * @return array{keepQuery: string, deleteQuery: string, deleteParams: list<mixed>, deleted: int}
     */
    private function prune(array $lastRuns, int $days): array
    {
        $seen = ['keepQuery' => '', 'deleteQuery' => '', 'deleteParams' => []];
        DbStub::$getFields = static function (string $query) use ($lastRuns, &$seen): array {
            $seen['keepQuery'] = $query;

            return $lastRuns;
        };
        DbStub::$query = static function (string $query, mixed ...$params) use (&$seen): int {
            $seen['deleteQuery'] = $query;
            $seen['deleteParams'] = array_values($params);

            return 7;
        };

        $deleted = (new SyncLogRepository())->prune($days);

        return $seen + ['deleted' => $deleted];
    }

    public function testKeepsDaysAndEachSyncsLastFinishedRun(): void
    {
        $run = $this->prune([41, 97, 120], 14);

        self::assertStringContainsString("status = 'completed' GROUP BY sync_type", $run['keepQuery'], 'the last finished run of every sync type');
        self::assertStringContainsString('"sync_type":"full"', $run['keepQuery'], 'and the last full run of every sync type');
        self::assertStringContainsString('INTERVAL ?i DAY', $run['deleteQuery']);
        self::assertStringContainsString('log_id NOT IN (?n)', $run['deleteQuery']);
        self::assertSame([14, [41, 97, 120]], $run['deleteParams']);
        self::assertSame(7, $run['deleted']);
    }

    public function testWithNothingToKeepItDeletesByAgeOnly(): void
    {
        $run = $this->prune([], 14);

        self::assertStringNotContainsString('NOT IN', $run['deleteQuery']);
        self::assertSame([14], $run['deleteParams']);
    }

    public function testTheCleanupKeepsTwoWeeks(): void
    {
        self::assertSame(14, CleanupCommand::LOG_KEEP_DAYS);
        self::assertSame([1, [5]], $this->prune([5], 0)['deleteParams'], 'never "everything older than now"');
    }
}
