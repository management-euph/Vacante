<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\Commands\AbstractSyncCommand;
use Tygh\Addons\Eurosite\Cron\Commands\FullSyncCommand;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Cron\CronRunLog;

/**
 * Eurosite's answer (28 Oct 2026): catalogs once a month, hotel lists weekly.
 * The nightly `full` line runs a step only when it is due; `force=1` (and a
 * run from the dashboard) runs every step.
 */
final class FullSyncDueStepsTest extends TestCase
{
    /** @var list<string> */
    public static array $ran = [];

    /** @var array<string, string> */
    private array $runLog = [];

    protected function setUp(): void
    {
        DbStub::reset();
        self::$ran = [];
        // The weekly hotel lists finished well an hour ago; tags never ran.
        $this->runLog = ['travel_cron_run.eurosite.hotels' => (string) json_encode(['started' => time() - 3700, 'finished' => time() - 3600, 'ok' => true])];
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

    /** @return list<string> */
    private function fullRun(array $params): array
    {
        $lines = [];
        $full = new FullSyncCommand(['hotels' => DueHotelsStep::class, 'tags' => DueTagsStep::class]);
        $full->setOutputCallback(static function (string $message) use (&$lines): void {
            $lines[] = $message;
        });
        $full->execute($params);

        return $lines;
    }

    public function testAStepThatIsNotDueIsSkipped(): void
    {
        $lines = $this->fullRun([]);

        self::assertSame(['tags'], self::$ran);
        self::assertNotEmpty(preg_grep('/^\[INFO\] hotels is not due yet \(last finished .+\), skipped\.$/', $lines));
    }

    public function testForceRunsEveryStep(): void
    {
        $this->fullRun(['force' => '1']);

        self::assertSame(['hotels', 'tags'], self::$ran);
    }

    public function testTheDashboardForcesIt(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/eurosite.php');

        self::assertStringContainsString("\$dispatcher->dispatch(\$syncType, \$syncType === 'full' ? ['force' => '1'] : [])", $controller);
    }
}

abstract class DueStep extends AbstractSyncCommand
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
        FullSyncDueStepsTest::$ran[] = static::NAME;

        return ['success' => true, 'stats' => ['total' => 1, 'synced' => 1]];
    }
}

final class DueHotelsStep extends DueStep
{
    public const string NAME = 'hotels';
}

final class DueTagsStep extends DueStep
{
    public const string NAME = 'tags';
}
