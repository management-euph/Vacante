<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SyncLogRepository;
use Tygh\Addons\SphinxHolidays\Services\SyncActivity;

/**
 * The dashboard's Recent sync activity: what each run says, the All /
 * Problems filter and the paging.
 */
final class SyncActivityTest extends TestCase
{
    private const int NOW = 1_790_000_000;

    /** @param array<string, mixed> $log */
    private static function row(array $log): array
    {
        return SyncActivity::row($log + ['sync_type' => 'hotels', 'sync_mode' => 'full', 'started_at' => date('Y-m-d H:i:s', self::NOW - 600)], self::NOW);
    }

    public function testACompletedRunThatSyncedEverythingIsCompleted(): void
    {
        $run = self::row(['status' => 'completed', 'items_total' => 1536, 'items_synced' => 1536, 'duration_ms' => 129_740]);

        self::assertSame('ok', $run['state']);
        self::assertSame(1536, $run['items']);
        self::assertSame(0, $run['not_synced']);
        self::assertSame(129.7, $run['seconds']);
        self::assertSame(['hotels', 'full'], [$run['job'], $run['mode']]);
    }

    /** A run that synced 2 of 3 used to show a green COMPLETED. */
    public function testACompletedRunThatMissedItemsIsPartlyDone(): void
    {
        $run = self::row(['status' => 'completed', 'items_total' => 3, 'items_synced' => 2]);

        self::assertSame('partial', $run['state']);
        self::assertSame(1, $run['not_synced']);
        self::assertNull($run['seconds'], 'no duration recorded');
    }

    public function testAFailedRunShowsItsErrorWithALongOneFolded(): void
    {
        $short = self::row(['status' => 'failed', 'items_total' => 100, 'items_synced' => 40, 'error_message' => ' API down ']);
        self::assertSame('failed', $short['state']);
        self::assertSame(60, $short['not_synced']);
        self::assertSame('API down', $short['error']);
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
        self::assertSame(0, self::row(['status' => 'started', 'items_total' => 10, 'items_synced' => 2])['not_synced'], 'a run in progress has missed nothing yet');
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

                return [['status' => 'completed', 'items_total' => 3, 'items_synced' => 2, 'sync_type' => 'exchange_rates', 'started_at' => '2026-09-27 23:29:00']];
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

    /** The Problems filter in SQL is the rule row() applies. */
    public function testTheRepositorysProblemsAreTheRowsProblems(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/SyncLogRepository.php');

        self::assertStringContainsString("status = 'failed'", $src);
        self::assertStringContainsString("(status = 'completed' AND items_synced < items_total)", $src);
        self::assertStringContainsString("(status = 'started' AND started_at < ?s)", $src);
        self::assertStringContainsString('$now - SyncActivity::STALL_AFTER', $src);
    }
}
