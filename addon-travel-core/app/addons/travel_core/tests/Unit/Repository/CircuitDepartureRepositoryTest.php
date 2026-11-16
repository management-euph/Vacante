<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The circuits' departures (docs/circuit-page-plan.md §3): each supplier's
 * sync hands over one complete reading; it is written, what it no longer
 * has is removed, and an empty reading (an outage) never empties the table.
 */
#[CoversClass(CircuitDepartureRepository::class)]
final class CircuitDepartureRepositoryTest extends TestCase
{
    private const string NOW = '2026-11-16 03:15:00';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        CircuitDepartureRepository::resetEnsured();
        $this->queries = [];
        // db_query() answers a DELETE with the rows it removed.
        DbStub::$query = function (string $query, mixed ...$params): int {
            $this->queries[] = [$query, array_values($params)];

            return str_starts_with(ltrim($query), 'DELETE') ? 4 : 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        CircuitDepartureRepository::resetEnsured();
    }

    /** @return array{circuit_id: string, departure_code: string, departure_name: string, departure_date: string, price: float, currency: string} */
    private static function row(string $circuit, string $code, string $date, float $price): array
    {
        return ['circuit_id' => $circuit, 'departure_code' => $code, 'departure_name' => 'Bucuresti', 'departure_date' => $date, 'price' => $price, 'currency' => 'EUR'];
    }

    public function testAReadingIsWrittenAndWhatItLacksIsRemoved(): void
    {
        $result = (new CircuitDepartureRepository())->replace('sphinx', [
            self::row('1', '202405', '2027-05-04', 1198.0),
            self::row('1', '202405', '2027-05-04', 1090.5),
            self::row('196|EU', 'ROBCH1', '2027-06-01', 1071.0) + ['availability' => 'on_request', 'seats' => 25],
            self::row('', '202405', '2027-05-11', 900.0),
            self::row('2', '202405', '05/2027', 900.0),
        ], self::NOW);

        self::assertSame(['written' => 2, 'removed' => 4], $result, 'a row without its circuit or a Y-m-d date is left out');
        self::assertCount(3, $this->queries);
        [[$create], [$insert, $insertParams], [$delete, $deleteParams]] = $this->queries;

        self::assertSame(CircuitDepartureRepository::CREATE_SQL, $create, 'the table is there before the first write');
        self::assertStringContainsString('INSERT INTO ?:travel_circuit_departures', $insert);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $insert);
        self::assertStringContainsString('price = VALUES(price)', $insert, 'a stored departure takes the new price');
        self::assertStringContainsString('updated_at = VALUES(updated_at)', $insert);
        self::assertSame(
            ["('sphinx', '1', '202405', '2027-05-04', 'Bucuresti', 1090.5, 'EUR', '', NULL, '" . self::NOW . "'), "
                . "('sphinx', '196|EU', 'ROBCH1', '2027-06-01', 'Bucuresti', 1071, 'EUR', 'on_request', 25, '" . self::NOW . "')"],
            $insertParams,
            'the same departure twice keeps the lowest price; seats and availability only when the supplier says',
        );
        self::assertSame('DELETE FROM ?:travel_circuit_departures WHERE provider = ?s AND updated_at < ?s', $delete);
        self::assertSame(['sphinx', self::NOW], $deleteParams, 'only this supplier, only what this reading did not write');
    }

    /** An outage that looks like "no departures" must never empty the table. */
    public function testAnEmptyReadingWritesAndRemovesNothing(): void
    {
        $repository = new CircuitDepartureRepository();

        self::assertSame(['written' => 0, 'removed' => 0], $repository->replace('sphinx', [], self::NOW));
        self::assertSame(['written' => 0, 'removed' => 0], $repository->replace('sphinx', [self::row('', '1', '2027-05-04', 10.0)], self::NOW), 'nothing storable');
        self::assertSame(['written' => 0, 'removed' => 0], $repository->replace('', [self::row('1', '1', '2027-05-04', 10.0)], self::NOW), 'no supplier');
        self::assertSame([], $this->queries);
    }

    /** A supplier that reads a few circuits at a time (a search by city) clears only those. */
    public function testAPartialReadingRemovesOnlyInItsCircuits(): void
    {
        $result = (new CircuitDepartureRepository())->replace(
            'eurosite',
            [self::row('196|EU', 'ROBCH1', '2027-06-01', 1071.0)],
            self::NOW,
            ['196|EU', '197|EU'],
        );

        self::assertSame(['written' => 1, 'removed' => 4], $result);
        [$delete, $params] = $this->queries[2];
        self::assertSame('DELETE FROM ?:travel_circuit_departures WHERE provider = ?s AND circuit_id IN (?a) AND updated_at < ?s', $delete);
        self::assertSame(['eurosite', ['196|EU', '197|EU'], self::NOW], $params);
    }

    public function testACircuitsDeparturesComeByDateWithTheirTypes(): void
    {
        $asked = [];
        DbStub::$getArray = static function (string $query, mixed ...$params) use (&$asked): array {
            $asked = [$query, array_values($params)];

            return [
                ['departure_code' => '202405', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-05-04', 'price' => '1090.50', 'currency' => 'EUR', 'availability' => '', 'seats' => null],
                ['departure_code' => 'ROBCH1', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-06-01', 'price' => '1071.00', 'currency' => 'EUR', 'availability' => 'on_request', 'seats' => '25'],
            ];
        };

        $departures = (new CircuitDepartureRepository())->forCircuit('sphinx', '1', '2026-11-16');

        self::assertSame([
            ['departure_code' => '202405', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-05-04', 'price' => 1090.5, 'currency' => 'EUR', 'availability' => '', 'seats' => null],
            ['departure_code' => 'ROBCH1', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-06-01', 'price' => 1071.0, 'currency' => 'EUR', 'availability' => 'on_request', 'seats' => 25],
        ], $departures);
        self::assertStringContainsString('WHERE provider = ?s AND circuit_id = ?s AND departure_date >= ?s', $asked[0]);
        self::assertStringContainsString('ORDER BY departure_date, departure_name, departure_code', $asked[0]);
        self::assertSame(['sphinx', '1', '2026-11-16'], $asked[1]);

        self::assertSame([], (new CircuitDepartureRepository())->forCircuit('sphinx', '', '2026-11-16'), 'no circuit asked');
    }
}
