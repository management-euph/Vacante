<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitDepartureRows;

/**
 * The rows the circuits sync writes to Travel Core's shared departures table
 * (docs/circuit-page-plan.md §3): the rates' city, else the static list's for
 * that circuit and day, else none; only the circuits on sale.
 */
final class CircuitDepartureRowsTest extends TestCase
{
    /** @return array{code: string, name: string, date: string, price: float, currency: string} */
    private static function departure(string $code, string $name, string $date, float $price): array
    {
        return ['code' => $code, 'name' => $name, 'date' => $date, 'price' => $price, 'currency' => 'EUR'];
    }

    public function testARateKeepsItsCityAndOneWithoutTakesTheStaticListsForThatDay(): void
    {
        $departures = [
            1 => [
                self::departure('202405', 'Bucuresti', '2027-05-04', 1198.0),
                self::departure('', '', '2027-05-11', 1258.0),
                self::departure('', '', '2027-05-18', 1298.0),
                self::departure('197128', '', '2027-05-25', 1298.0),
            ],
            2 => [self::departure('202405', 'Bucuresti', '2027-06-01', 900.0)],
            3 => [self::departure('', '', '2027-07-01', 500.0)],
        ];
        $static = [1 => ['2027-05-11' => 202405], 3 => []];

        self::assertSame([202405, 197128], CircuitDepartureRows::unnamedCities($departures, [1, 3], $static), 'the ones to name from the static destinations');

        $rows = CircuitDepartureRows::build($departures, [1, 3], $static, [202405 => 'Bucuresti', 197128 => 'Timisoara']);

        self::assertSame([
            ['circuit_id' => '1', 'departure_code' => '202405', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-05-04', 'price' => 1198.0, 'currency' => 'EUR'],
            ['circuit_id' => '1', 'departure_code' => '202405', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-05-11', 'price' => 1258.0, 'currency' => 'EUR'],
            ['circuit_id' => '1', 'departure_code' => '', 'departure_name' => '', 'departure_date' => '2027-05-18', 'price' => 1298.0, 'currency' => 'EUR'],
            ['circuit_id' => '1', 'departure_code' => '197128', 'departure_name' => 'Timisoara', 'departure_date' => '2027-05-25', 'price' => 1298.0, 'currency' => 'EUR'],
            ['circuit_id' => '3', 'departure_code' => '', 'departure_name' => '', 'departure_date' => '2027-07-01', 'price' => 500.0, 'currency' => 'EUR'],
        ], $rows, 'circuit 2 is not on sale: none of its rows; a day neither names stays without a city');
    }

    public function testNothingToWriteWithoutDepartures(): void
    {
        self::assertSame([], CircuitDepartureRows::build([], [1, 2], [], []));
        self::assertSame([], CircuitDepartureRows::build([1 => [self::departure('1', 'A', '2027-05-04', 10.0)]], [], [], []));
        self::assertSame([], CircuitDepartureRows::unnamedCities([1 => [self::departure('1', 'A', '2027-05-04', 10.0)]], [1], []), 'already named by the rate');
    }
}
