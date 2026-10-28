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
 *
 * Since Novoton's answer (28 Oct 2026) the full pass is monthly, from 05:00,
 * and in between only new packages and the ones offers_update named are
 * asked for.
 */
#[CoversClass(BatchedPriceInfoSyncV2::class)]
final class BatchedPriceInfoSyncTypeTest extends TestCase
{
    /** A Tuesday morning, after Novoton's busiest hour. */
    private const string NOW = '2026-11-10 10:00:00';

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
    private function syncType(?string $lastFull, int $stalePackages, array &$askedTypes, string $now = self::NOW): string
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
        $at = (int) strtotime($now);
        $sync = new class ($this->createStub(StateManagerInterface::class), $this->createStub(SyncLoggerInterface::class), $refusals, $at) extends BatchedPriceInfoSyncV2 {
            public function __construct(StateManagerInterface $state, SyncLoggerInterface $logger, PriceInfoRefusals $refusals, private readonly int $at)
            {
                parent::__construct($state, $logger, $refusals);
            }

            #[\Override]
            protected function now(): int
            {
                return $this->at;
            }
        };
        $method = new \ReflectionMethod($sync, 'determineSyncType');
        $type = $method->invoke($sync, []);
        self::assertIsString($type);

        return $type;
    }

    public function testItFindsItsOwnFullRunAndDoesNotStartAnother(): void
    {
        $asked = [];
        $type = $this->syncType('2026-11-10 09:00:00', 0, $asked);

        self::assertSame(['priceinfo'], $asked, 'it looks up the type its runs are logged under');
        self::assertSame('none', $type, 'a full run an hour ago and nothing to ask about: nothing to do');
    }

    public function testNewOrNamedPackagesGetAnIncrementalRun(): void
    {
        $asked = [];

        self::assertSame('incremental', $this->syncType('2026-11-08 05:20:00', 120, $asked));
    }

    public function testAFullRunOnceAMonthFromFiveInTheMorning(): void
    {
        $asked = [];
        self::assertSame('full', $this->syncType(null, 0, $asked), 'never ran in full');

        $asked = [];
        self::assertSame('none', $this->syncType('2026-11-02 05:20:00', 0, $asked), 'eight days ago: the weekly pass is gone');

        $asked = [];
        self::assertSame('full', $this->syncType('2026-10-10 05:20:00', 0, $asked), 'a month ago');

        $asked = [];
        self::assertSame('none', $this->syncType('2026-10-10 05:20:00', 0, $asked, '2026-11-09 04:55:00'), 'due from 05:00, not before');
    }

    /** Novoton's busiest hour at night (03:00–04:00): not even the first pass starts in it. */
    public function testNoFullRunStartsInNovotonsBusiestHour(): void
    {
        $asked = [];

        self::assertSame('none', $this->syncType(null, 0, $asked, '2026-11-10 03:30:00'));
    }
}
