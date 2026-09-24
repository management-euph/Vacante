<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Cron\CronHealth;
use Tygh\Addons\TravelCore\Cron\CronOverview;
use Tygh\Addons\TravelCore\Cron\CronRunLog;

/**
 * The data behind Travel Core -> Tools: Travel Core's job and each provider's row.
 */
#[CoversClass(CronOverview::class)]
final class CronOverviewTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
        CronRunLog::useStorage(
            fn (string $k): ?string => $this->store[$k] ?? null,
            function (string $k, string $v): void {
                $this->store[$k] = $v;
            },
            static fn (): int => 5000,
        );
    }

    protected function tearDown(): void
    {
        CronRunLog::useStorage(null, null);
    }

    public function testCoreCommandsCarryTheKeyInBothForms(): void
    {
        $c = CronOverview::coreCommands('K3Y', 'https://shop.example.ro/', '/var/www/');

        self::assertSame(
            'https://shop.example.ro/index.php?dispatch=travel_cron.run&access_key=K3Y&cron_mode=exchange_rates',
            $c['url'],
        );
        self::assertSame('php /var/www/app/addons/travel_core/cron.php access_key=K3Y mode=exchange_rates', $c['cli']);
    }

    /** Job counts come from the dispatcher — the list its cron accepts — not a dashboard's hand-picked list. */
    public function testAProviderRowCountsItsDispatcherModesAndReadsItsRunLog(): void
    {
        CronRunLog::record('fake_addon', 'one', static fn (): array => ['success' => false, 'error' => 'boom']);

        $rows = CronOverview::providerRows([
            'fake' => ['name' => 'fake', 'label' => 'Fake', 'addon' => 'fake_addon', 'dispatcher' => OverviewFakeDispatcher::class, 'dashboard' => 'fake.manage', 'anchor' => 'fake-cron'],
        ], 6000);

        self::assertCount(1, $rows);
        self::assertSame('fake_addon', $rows[0]['addon']);
        self::assertSame(3, $rows[0]['jobs']);
        self::assertSame('fake.manage', $rows[0]['dashboard']);
        self::assertSame('fake-cron', $rows[0]['anchor']);
        self::assertSame(CronHealth::FAILED, $rows[0]['health']['state']);
        self::assertSame(['one'], $rows[0]['health']['failed']);
    }

    /** A dispatcher that cannot list its jobs is a row with none, not a broken page. */
    public function testABrokenDispatcherGivesAnEmptyRowNotAnError(): void
    {
        $rows = CronOverview::providerRows([
            'bad' => ['name' => 'bad', 'label' => 'Bad', 'addon' => 'bad_addon', 'dispatcher' => OverviewThrowingDispatcher::class, 'dashboard' => 'bad.manage', 'anchor' => ''],
        ], 6000);

        self::assertSame(0, $rows[0]['jobs']);
        self::assertSame(CronHealth::NEVER, $rows[0]['health']['state']);
    }

    public function testCoreHealthReadsTravelCoresOwnRecords(): void
    {
        self::assertSame(CronHealth::NEVER, CronOverview::coreHealth(6000)['state']);

        CronRunLog::record('travel_core', 'exchange_rates', static fn (): array => ['success' => true]);
        self::assertSame(CronHealth::OK, CronOverview::coreHealth(6000)['state']);
        self::assertTrue(CronOverview::coreJobRecord()['ok']);
    }
}

/** @internal */
final class OverviewFakeDispatcher implements CronDispatcherInterface
{
    public function hasMode(string $mode): bool
    {
        return true;
    }

    public function dispatch(string $mode, array $params = []): array
    {
        return ['success' => true];
    }

    public static function getAvailableModes(): array
    {
        return ['one' => '1', 'two' => '2', 'three' => '3'];
    }
}

/** @internal */
final class OverviewThrowingDispatcher implements CronDispatcherInterface
{
    public function hasMode(string $mode): bool
    {
        return false;
    }

    public function dispatch(string $mode, array $params = []): array
    {
        return [];
    }

    public static function getAvailableModes(): array
    {
        throw new \RuntimeException('cannot list');
    }
}
