<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedPriceInfoSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\StateManagerInterface;
use Tygh\Addons\NovotonHolidays\Helpers\PriceInfoRefusals;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * The price sync looked up its last full run under the type
 * 'sync_priceinfo' while logging its runs as 'priceinfo'. It never found
 * one, so every cycle was full and a new one began at the next cron tick:
 * each package's priceinfo was downloaded many times a day instead of once.
 */
#[CoversClass(BatchedPriceInfoSyncV2::class)]
final class BatchedPriceInfoSyncTypeTest extends TestCase
{
    protected function setUp(): void
    {
        DestinationScope::setCurrent(DestinationScope::fromRows([]));
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        DestinationScope::setCurrent(null);
    }

    /**
     * @param string|null $lastFull the last completed full 'priceinfo' run, null for none
     * @param list<string> $askedTypes collects the sync types the lookup asked for
     */
    private function syncType(?string $lastFull, int $stalePackages, array &$askedTypes): string
    {
        DbStub::$getField = static function (string $query, mixed ...$params) use ($lastFull, &$askedTypes): mixed {
            if (str_contains($query, 'novoton_sync_log')) {
                $askedTypes[] = is_string($params[0] ?? null) ? $params[0] : '(literal in SQL)';

                return ($params[0] ?? null) === 'priceinfo' ? $lastFull : null;
            }

            return null;
        };
        // The stale packages an incremental run would ask about.
        $rows = $stalePackages > 0
            ? array_map(static fn (int $i): array => ['hotel_id' => '476', 'package_id' => (string) $i], range(1, $stalePackages))
            : [];
        DbStub::$getArray = static fn (string $query): array => str_contains($query, 'novoton_hotel_packages') ? $rows : [];

        $refusals = new PriceInfoRefusals(static fn (): string => '', static function (): void {});
        $sync = new BatchedPriceInfoSyncV2($this->createStub(StateManagerInterface::class), $this->createStub(SyncLoggerInterface::class), $refusals);
        $method = new \ReflectionMethod($sync, 'determineSyncType');
        $type = $method->invoke($sync, []);
        self::assertIsString($type);

        return $type;
    }

    public function testItFindsItsOwnFullRunAndDoesNotStartAnother(): void
    {
        $asked = [];
        $type = $this->syncType(date('Y-m-d H:i:s', time() - 3600), 0, $asked);

        self::assertSame(['priceinfo'], $asked, 'it looks up the type its runs are logged under');
        self::assertSame('none', $type, 'a full run an hour ago and nothing stale: nothing to do');
    }

    public function testStalePackagesGetAnIncrementalRun(): void
    {
        $asked = [];

        self::assertSame('incremental', $this->syncType(date('Y-m-d H:i:s', time() - 2 * 86400), 120, $asked));
    }

    public function testAFullRunOnlyWhenNoneIsRecentEnough(): void
    {
        $asked = [];
        self::assertSame('full', $this->syncType(null, 0, $asked), 'never ran in full');

        $asked = [];
        self::assertSame('full', $this->syncType(date('Y-m-d H:i:s', time() - 8 * 86400), 0, $asked), 'older than the 7-day interval');
    }
}
