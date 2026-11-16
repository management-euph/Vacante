<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\StoredCircuitDates;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Step 4 (docs/circuit-page-plan.md): the circuit bar's dates come from the
 * departures the circuits sync saved, so the page asks Sphinx nothing until
 * the rooms. The controller falls back to the live rates when none is saved.
 */
final class StoredCircuitDatesTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testTheSavedDatesOfTheCityWithTheCommission(): void
    {
        $asked = [];
        DbStub::$getArray = static function (string $q, mixed ...$p) use (&$asked): array {
            $asked = $p;

            return [
                ['departure_code' => '202405', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-05-04', 'price' => '1100', 'currency' => 'EUR', 'availability' => '', 'seats' => null],
                ['departure_code' => '197128', 'departure_name' => 'Timisoara', 'departure_date' => '2027-05-04', 'price' => '900', 'currency' => 'EUR', 'availability' => '', 'seats' => null],
                ['departure_code' => '', 'departure_name' => '', 'departure_date' => '2027-05-11', 'price' => '1200', 'currency' => 'EUR', 'availability' => '', 'seats' => null],
                ['departure_code' => '202405', 'departure_name' => 'Bucuresti', 'departure_date' => '2027-05-11', 'price' => '1150', 'currency' => 'EUR', 'availability' => '', 'seats' => null],
            ];
        };

        $dates = (new StoredCircuitDates(static fn (float $p): float => $p * 1.1))->forDeparture(1, 202405, new \DateTimeImmutable('2026-11-16'));

        self::assertSame([
            ['date' => '2027-05-04', 'price' => 1210.0, 'currency' => 'EUR'],
            ['date' => '2027-05-11', 'price' => 1265.0, 'currency' => 'EUR'],
        ], $dates, 'a departure from another city is left out; one without a city leaves from any; the lowest of the day');
        self::assertSame(['sphinx', '1', '2026-11-16'], $asked);
    }

    public function testNothingSavedMeansAskSphinx(): void
    {
        self::assertSame([], (new StoredCircuitDates(static fn (float $p): float => $p))->forDeparture(1, 0, new \DateTimeImmutable('2026-11-16')));
        self::assertSame([], (new StoredCircuitDates(static fn (float $p): float => $p))->forDeparture(0, 0, new \DateTimeImmutable('2026-11-16')));

        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/circuit_dates.php');
        self::assertStringContainsString('$answer = $stored !== []', $controller);
        self::assertStringContainsString(': (new CircuitDepartureDates(Container::getApi(), $commission))->forDeparture(', $controller);
    }
}
