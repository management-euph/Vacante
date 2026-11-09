<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Repository\PackageRouteRepository;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;

/**
 * The package routes' table: one transport's answer replaces that transport's
 * routes, and only those.
 */
final class PackageRouteRepositoryTest extends TestCase
{
    private const string NOW = '2026-11-08 03:50:00';

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    #[\Override]
    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $sql, mixed ...$params): int {
            $this->queries[] = ['sql' => $sql, 'params' => array_values($params)];

            return 1;
        };
    }

    #[\Override]
    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testATransportsRoutesAreWrittenThenTheGoneOnesDropped(): void
    {
        $written = (new PackageRouteRepository())->replaceTransport('plane', [
            self::route('TRALN', 'ROBCH1', ['2026-06-05', '2026-06-12', '2026-06-19']),
            self::route('TRALN', 'ROCLJNPC', ['2026-06-07']),
            // Another transport's route, and one without a date: not written.
            ['transport' => 'bus'] + self::route('GRTHS', 'ROBCH1', ['2026-06-05']),
            self::route('TRSID', 'ROBCH1', []),
        ], self::NOW);

        self::assertSame(2, $written);
        self::assertCount(2, $this->queries, 'one INSERT for the rows, one DELETE');

        [$insert, $delete] = $this->queries;
        self::assertStringStartsWith('INSERT INTO ?:eurosite_package_routes', $insert['sql']);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $insert['sql']);
        self::assertSame(2, substr_count($insert['sql'], '(?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?i, ?s)'));
        self::assertSame([
            'plane', 'TRALN', 'ROBCH1', 'TR', 'Turkey', 'Alanya', 'TCANT', 'Antalya', 'RO', 'Romania', 'Bucharest',
            '["2026-06-05","2026-06-12","2026-06-19"]', '2026-06-05', '2026-06-19', 3, self::NOW,
        ], array_slice($insert['params'], 0, 16));
        self::assertSame(['plane', 'TRALN', 'ROCLJNPC'], array_slice($insert['params'], 16, 3));
        self::assertSame(['["2026-06-07"]', '2026-06-07', '2026-06-07', 1], array_slice($insert['params'], 27, 4));
        self::assertCount(32, $insert['params']);

        // Only this transport's rows that this answer did not write.
        self::assertSame(
            'DELETE FROM ?:eurosite_package_routes WHERE transport = ?s AND (last_synced_at IS NULL OR last_synced_at < ?s)',
            $delete['sql'],
        );
        self::assertSame(['plane', self::NOW], $delete['params']);
    }

    /** No routes in the answer: the transport has none any more. */
    public function testAnEmptyAnswerLeavesTheTransportWithoutRoutes(): void
    {
        self::assertSame(0, (new PackageRouteRepository())->replaceTransport('bus', [], self::NOW));

        self::assertCount(1, $this->queries);
        self::assertStringStartsWith('DELETE FROM ?:eurosite_package_routes', $this->queries[0]['sql']);
        self::assertSame(['bus', self::NOW], $this->queries[0]['params']);
    }

    public function testALongAnswerIsWrittenInChunks(): void
    {
        $routes = [];
        for ($i = 0; $i < 450; $i++) {
            $routes[] = self::route('C' . $i, 'ROBCH1', ['2026-06-05']);
        }

        self::assertSame(450, (new PackageRouteRepository())->replaceTransport('plane', $routes, self::NOW));
        self::assertSame(
            [200, 200, 50],
            array_map(static fn (array $q): int => intdiv(count($q['params']), 16), array_slice($this->queries, 0, 3)),
        );
        self::assertStringStartsWith('DELETE', $this->queries[3]['sql']);
    }

    public function testItCountsTheRoutes(): void
    {
        DbStub::$getField = static fn (string $sql): string => $sql === 'SELECT COUNT(*) FROM ?:eurosite_package_routes' ? '7' : '0';

        self::assertSame(7, (new PackageRouteRepository())->count());
    }

    /**
     * @param list<string> $dates
     *
     * @return array{transport: string, country_code: string, country_name: string, city_code: string, city_name: string, zone_code: string, zone_name: string, dep_country_code: string, dep_country_name: string, dep_city_code: string, dep_city_name: string, dates: list<string>}
     */
    private static function route(string $city, string $from, array $dates): array
    {
        return [
            'transport' => 'plane',
            'country_code' => 'TR',
            'country_name' => 'Turkey',
            'city_code' => $city,
            'city_name' => 'Alanya',
            'zone_code' => 'TCANT',
            'zone_name' => 'Antalya',
            'dep_country_code' => 'RO',
            'dep_country_name' => 'Romania',
            'dep_city_code' => $from,
            'dep_city_name' => 'Bucharest',
            'dates' => $dates,
        ];
    }
}
