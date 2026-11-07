<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\HotelDemand;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * How much customers want each hotel (docs/novoton-availability-gaps-plan.md
 * §7.2): page views from CS-Cart's own count, "notify me" requests waiting
 * and alternatives requests of the last 12 months, written to the records
 * that changed.
 */
final class HotelDemandTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $writes = [];

    /** @var list<string> */
    private array $reads = [];

    protected function setUp(): void
    {
        DbStub::reset();
        DbStub::$query = function (string $query, mixed ...$params): int {
            if (str_starts_with(trim($query), 'UPDATE')) {
                $this->writes[] = [$query, $params];
            }

            return 1;
        };
        DbStub::$getArray = function (string $query, mixed ...$params): array {
            $this->reads[] = db_quote($query, ...$params);

            return str_contains($query, 'FROM ?:travel_alternative_requests')
                ? [['hotel_id' => '476', 'waiting' => '3', 'requests' => '2'], ['hotel_id' => '480', 'waiting' => '0', 'requests' => '0']]
                : [];
        };
        DbStub::$getHashSingleArray = static fn (): array => [15 => '1200', 16 => '40'];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            ['provider' => 'novoton', 'hotel_id' => '476', 'product_id' => '15', 'demand_views' => '0', 'demand_waiting' => '0', 'demand_requests' => '0'],
            // Unchanged since the last review: not rewritten.
            ['provider' => 'novoton', 'hotel_id' => '480', 'product_id' => '16', 'demand_views' => '40', 'demand_waiting' => '0', 'demand_requests' => '0'],
            ['provider' => 'novoton', 'hotel_id' => '490', 'product_id' => '0', 'demand_views' => '7', 'demand_waiting' => '1', 'demand_requests' => '0'],
        ];
    }

    public function testDemandIsWrittenWhereItChanged(): void
    {
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'INFORMATION_SCHEMA') ? '1' : '';

        $changed = (new HotelDemand())->refresh('novoton', self::rows(), new \DateTimeImmutable('2027-02-15 06:00:00'));

        self::assertSame(2, $changed);
        self::assertSame([
            [['demand_views' => 1200, 'demand_waiting' => 3, 'demand_requests' => 2], 'novoton', '476'],
            [['demand_views' => 0, 'demand_waiting' => 0, 'demand_requests' => 0], 'novoton', '490'],
        ], array_column($this->writes, 1), 'a hotel without a product has no views; its request was answered');
        self::assertStringContainsString("created_at >= '2026-02-15 06:00:00'", implode("\n", $this->reads), 'requests of the last 12 months');
        self::assertStringContainsString("WHERE provider = 'novoton'", implode("\n", $this->reads));
    }

    public function testAStoreWithoutCsCartsViewCountCountsNoViews(): void
    {
        DbStub::$getField = static fn (): string => '0';

        (new HotelDemand())->refresh('novoton', self::rows(), new \DateTimeImmutable('2027-02-15 06:00:00'));

        self::assertSame(['demand_views' => 0, 'demand_waiting' => 3, 'demand_requests' => 2], $this->writes[0][1][0]);
    }
}
