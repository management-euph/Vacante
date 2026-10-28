<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\StateManagerInterface;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLoggerInterface;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Novoton's answer (28 Oct 2026) on hotel details: every 3–7 days, every 3
 * while they load the new season (February to May), 7 otherwise; the full
 * pass starts at 05:00. In between, only the hotels offers_update named, and
 * no API call to find them.
 */
#[CoversClass(BatchedHotelInfoSyncV2::class)]
final class BatchedHotelInfoSyncTypeTest extends TestCase
{
    /** @var list<string> */
    private array $marked = [];

    protected function setUp(): void
    {
        DestinationScope::setCurrent(DestinationScope::fromRows([]));
        $this->marked = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        DestinationScope::setCurrent(null);
    }

    private function syncType(?string $lastFull, string $now): string
    {
        DbStub::$getField = static fn (string $query): ?string => str_contains($query, 'novoton_sync_log') ? $lastFull : null;
        DbStub::$getFields = function (string $query): array {
            if (str_contains($query, 'hotelinfo_due_at')) {
                return $this->marked; // ChangedHotelDetector
            }
            if (str_contains($query, 'WHERE hotel_id IN (?a)')) {
                return $this->marked; // all of them in the destinations we sell
            }

            return [];
        };

        $at = (int) strtotime($now);
        $sync = new class ($this->createStub(StateManagerInterface::class), $this->createStub(SyncLoggerInterface::class), $at) extends BatchedHotelInfoSyncV2 {
            public function __construct(StateManagerInterface $state, SyncLoggerInterface $logger, private readonly int $at)
            {
                parent::__construct($state, $logger);
            }

            #[\Override]
            protected function now(): int
            {
                return $this->at;
            }
        };
        $type = (new \ReflectionMethod($sync, 'determineSyncType'))->invoke($sync, ['countries' => ['GREECE']]);
        self::assertIsString($type);

        return $type;
    }

    public function testEveryThreeDaysInFebruaryToMay(): void
    {
        self::assertSame('full', $this->syncType('2027-03-01 05:20:00', '2027-03-04 05:05:00'));
        self::assertSame('none', $this->syncType('2027-03-01 05:20:00', '2027-03-03 23:00:00'));
    }

    public function testEverySevenDaysOtherwise(): void
    {
        self::assertSame('none', $this->syncType('2026-11-01 05:20:00', '2026-11-04 05:05:00'), 'three days is the peak interval only');
        self::assertSame('full', $this->syncType('2026-11-01 05:20:00', '2026-11-08 05:05:00'));
    }

    public function testInBetweenOnlyTheHotelsOffersUpdateNamed(): void
    {
        $this->marked = ['476', '5120'];

        self::assertSame('incremental', $this->syncType('2026-11-01 05:20:00', '2026-11-03 17:05:00'));
    }

    /** On a new store every hotel is never-fetched: they wait for the first full pass, outside Novoton's busiest hour. */
    public function testTheFirstPassWaitsOutNovotonsBusiestHour(): void
    {
        $this->marked = ['476'];

        self::assertSame('none', $this->syncType(null, '2026-11-03 03:10:00'));
        self::assertSame('full', $this->syncType(null, '2026-11-03 04:10:00'));
    }
}
