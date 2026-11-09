<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Repository\SyncLogRepository;
use Tygh\Addons\Eurosite\Services\SyncActivity;

/**
 * The dashboard's Recent sync activity: what each run says, the All /
 * Problems filter and the paging.
 */
#[CoversClass(SyncActivity::class)]
final class SyncActivityTest extends TestCase
{
    private const int NOW = 1_790_000_000;

    /**
     * @param array<string, mixed> $log
     *
     * @return array{at: string, job: string, synced: int, total: int, failed: int, state: string, error: string, error_full: string, seconds: float|null}
     */
    private static function row(array $log): array
    {
        return SyncActivity::row($log + ['sync_type' => 'hotels', 'started_at' => date('Y-m-d H:i:s', self::NOW - 600)], self::NOW);
    }

    public function testACompletedRunIsCompleted(): void
    {
        $run = self::row(['status' => 'completed', 'items_total' => 1536, 'items_synced' => 1536, 'items_failed' => 0, 'duration_ms' => 129_740]);

        self::assertSame('ok', $run['state']);
        self::assertSame([1536, 1536, 0], [$run['synced'], $run['total'], $run['failed']]);
        self::assertSame(129.7, $run['seconds']);
        self::assertSame('hotels', $run['job']);
    }

    /**
     * Skipping is normal: Hotel details skips the hotels whose details are
     * fresh, and the log does not count skipped items apart. A run that
     * fetched 12 of 900 and failed none is Completed, not Partly done.
     */
    public function testSkippedItemsAreNoProblem(): void
    {
        $run = self::row(['sync_type' => 'product_info', 'status' => 'completed', 'items_total' => 900, 'items_synced' => 12, 'items_failed' => 0]);

        self::assertSame('ok', $run['state']);
        self::assertSame([12, 900, 0], [$run['synced'], $run['total'], $run['failed']]);
    }

    public function testACompletedRunWithFailedItemsIsPartlyDone(): void
    {
        $run = self::row(['status' => 'completed', 'items_total' => 50, 'items_synced' => 47, 'items_failed' => 3]);

        self::assertSame('partial', $run['state']);
        self::assertSame(3, $run['failed']);
        self::assertNull($run['seconds'], 'no duration recorded');
    }

    public function testAFailedRunShowsItsErrorWithALongOneFolded(): void
    {
        $short = self::row(['status' => 'failed', 'items_total' => 100, 'items_synced' => 0, 'items_failed' => 100, 'error_message' => ' -1000 Authentication failed ']);
        self::assertSame('failed', $short['state']);
        self::assertSame('-1000 Authentication failed', $short['error']);
        self::assertSame('', $short['error_full'], 'a short error has nothing to fold');

        $text = str_repeat('Ș', SyncActivity::ERROR_SHOWN + 40);
        $long = self::row(['status' => 'failed', 'error_message' => $text]);
        self::assertSame(SyncActivity::ERROR_SHOWN, mb_strlen($long['error']), 'its start, then an ellipsis');
        self::assertStringEndsWith('…', $long['error']);
        self::assertSame($text, $long['error_full']);
    }

    public function testAStartedRunIsRunningUntilItIsTooOldThenNeverFinished(): void
    {
        self::assertSame('running', self::row(['status' => 'started', 'started_at' => date('Y-m-d H:i:s', self::NOW - 60)])['state']);
        self::assertSame('stalled', self::row(['status' => 'started', 'started_at' => date('Y-m-d H:i:s', self::NOW - SyncActivity::STALL_AFTER - 60)])['state']);
        self::assertSame('other', self::row(['status' => 'odd'])['state']);
        self::assertSame(5, self::row(['status' => 'completed', 'items_total' => 0, 'items_synced' => 5])['total'], 'never fewer than it synced');
    }

    public function testTheRequestIsReadSafely(): void
    {
        self::assertSame(['page' => 1, 'per_page' => 10, 'problems' => false], SyncActivity::request([]));
        self::assertSame(['page' => 3, 'per_page' => 25, 'problems' => true], SyncActivity::request(['page' => '3', 'items_per_page' => '25', 'activity' => 'problems']));
        self::assertSame(['page' => 1, 'per_page' => 10, 'problems' => false], SyncActivity::request(['page' => '-2', 'items_per_page' => '5000', 'activity' => ['problems']]));
    }

    public function testThePageCountsBothFiltersAndStaysInRange(): void
    {
        $repo = new class () extends SyncLogRepository {
            /** @var list<array{int, int, bool}> */
            public array $asked = [];

            #[\Override]
            public function findPage(int $page, int $perPage, bool $problems, int $now): array
            {
                $this->asked[] = [$page, $perPage, $problems];

                return [['status' => 'completed', 'items_total' => 30, 'items_synced' => 28, 'items_failed' => 2, 'sync_type' => 'update_products', 'started_at' => '2026-11-08 06:00:00']];
            }

            #[\Override]
            public function count(bool $problems, int $now): int
            {
                return $problems ? 4 : 23;
            }
        };

        $page = SyncActivity::page($repo, ['page' => '9', 'activity' => 'problems'], self::NOW);

        self::assertSame([[1, 10, true]], $repo->asked, 'page 9 of a 4-row filter is page 1');
        self::assertSame(['all' => 23, 'problems' => 4], $page['counts']);
        self::assertSame(['page' => 1, 'items_per_page' => 10, 'total_items' => 4, 'activity' => 'problems'], $page['search']);
        self::assertTrue($page['problems']);
        self::assertSame('partial', $page['rows'][0]['state']);
    }

    /** A log that could not be read: the card still renders, with no run and page 1. */
    public function testNoneIsAnEmptyFirstPage(): void
    {
        self::assertSame(
            ['rows' => [], 'problems' => false, 'counts' => ['all' => 0, 'problems' => 0], 'search' => ['page' => 1, 'items_per_page' => 10, 'total_items' => 0, 'activity' => '']],
            SyncActivity::none(),
        );
    }

    /** The Problems filter in SQL is the rule row() applies: failed items, not total - synced. */
    public function testTheRepositorysProblemsAreTheRowsProblems(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/SyncLogRepository.php');

        self::assertStringContainsString("status = 'failed'", $src);
        self::assertStringContainsString("(status = 'completed' AND items_failed > 0)", $src);
        self::assertStringContainsString("(status = 'started' AND started_at < ?s)", $src);
        self::assertStringContainsString('$now - SyncActivity::STALL_AFTER', $src);
        self::assertStringNotContainsString('items_synced < items_total', $src);
    }
}
