<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\Commands\AbstractSyncCommand;
use Tygh\Addons\Eurosite\Cron\Commands\FullSyncCommand;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Helpers\CronRunLock;

/**
 * `full` runs each step under that step's own run lock and logs it as its own
 * run (docs/novoton-availability-gaps-plan.md §4.5, rule 3). It used to run
 * them bare: a `full` still running at 03:00 overlapped the scheduled
 * `hotels`, and Travel Core could not see that the hotel list was being
 * rewritten.
 */
final class FullSyncLocksTest extends TestCase
{
    /** @var list<string> */
    public static array $ran = [];

    /** @var array<string, string> storage key => JSON */
    private array $runLog = [];

    protected function setUp(): void
    {
        DbStub::reset();
        self::$ran = [];
        $this->runLog = [];
        CronRunLog::useStorage(
            fn (string $key): ?string => $this->runLog[$key] ?? null,
            function (string $key, string $value): void {
                $this->runLog[$key] = $value;
            },
        );
    }

    protected function tearDown(): void
    {
        CronRunLog::useStorage(null, null);
        DbStub::reset();
    }

    public function testAStepAlreadyRunningIsSkippedAndTheOthersAreLoggedAsTheirOwnRuns(): void
    {
        $lines = [];
        $full = new FullSyncCommand(['hotels' => FakeHotelsStep::class, 'tags' => FakeTagsStep::class]);
        $full->setOutputCallback(static function (string $message) use (&$lines): void {
            $lines[] = $message;
        });

        // The scheduled `hotels` holds its lock.
        $hotels = new CronRunLock(CronDispatcher::lockPath('hotels'));
        self::assertTrue($hotels->acquire());
        try {
            $result = $full->execute();
        } finally {
            $hotels->release();
        }

        self::assertSame(['tags'], self::$ran, 'hotels was not run a second time at once');
        self::assertContains('[INFO] hotels is already running, skipped.', $lines);
        self::assertTrue($result['success']);
        self::assertArrayHasKey('travel_cron_run.eurosite.tags', $this->runLog, 'tags is logged as its own run');
        self::assertArrayNotHasKey('travel_cron_run.eurosite.hotels', $this->runLog);
        self::assertFileDoesNotExist(CronDispatcher::lockPath('tags'), 'the step lock is released');
    }

    public function testAStatusRequestTakesNoLocks(): void
    {
        $full = new FullSyncCommand(['tags' => FakeTagsStep::class]);
        $tags = new CronRunLock(CronDispatcher::lockPath('tags'));
        self::assertTrue($tags->acquire());
        try {
            $full->execute(['status' => 1]);
        } finally {
            $tags->release();
        }

        self::assertSame(['tags'], self::$ran);
        self::assertSame([], $this->runLog, 'no run is logged');
    }
}

/** A step that only notes it ran. */
abstract class FakeFullStep extends AbstractSyncCommand
{
    public const string NAME = '';

    #[\Override]
    public static function getDescription(): string
    {
        return 'a step';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        $this->output('working');
        FullSyncLocksTest::$ran[] = static::NAME;

        return ['success' => true, 'stats' => ['total' => 1, 'synced' => 1]];
    }
}

final class FakeHotelsStep extends FakeFullStep
{
    public const string NAME = 'hotels';
}

final class FakeTagsStep extends FakeFullStep
{
    public const string NAME = 'tags';
}
