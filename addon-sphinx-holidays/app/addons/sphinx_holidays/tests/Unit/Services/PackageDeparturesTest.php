<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\PackageDepartures;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * A hotel's page knows its package modes from the saved routes (board
 * 16.2): a route goes to the hotel's destination or one above it, and its
 * dates and lengths are the bar's choices. Antalya City's hotels are
 * reached by plane only (Rixos Downtown: from Bucharest, 3, 5 or 7 nights).
 */
final class PackageDeparturesTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @return array<string, mixed> */
    private static function route(string $transport, int $from, string $name, array $dates, int $nights): array
    {
        return ['transport_type' => $transport, 'departure_id' => $from, 'departure_name' => $name, 'available_dates' => json_encode($dates), 'duration' => $nights];
    }

    public function testRoutesBecomeCitiesDatesAndNights(): void
    {
        $modes = PackageDepartures::group([
            self::route('flight', 10, 'Bucuresti', ['2027-04-03', '2026-11-01', '2027-04-10'], 7),
            self::route('flight', 10, 'Bucuresti', ['2027-04-03'], 3),
            self::route('flight', 20, 'Cluj-Napoca', ['2027-05-01'], 7),
            self::route('bus', 30, 'Iasi', ['2026-11-09'], 5),
            self::route('train', 40, 'Arad', ['2027-01-01'], 7),       // not a package transport
            self::route('flight', 50, 'Sibiu', ['2026-10-01'], 7),      // nothing ahead
            self::route('flight', 60, 'Oradea', ['2027-13-45x'], 7),    // not a date
        ], '2026-11-08');

        self::assertSame(['bus', 'flight'], array_column($modes, 'transport'), 'the switch\'s order: by bus, then by plane');
        self::assertSame([['id' => 30, 'name' => 'Iasi', 'dates' => [['date' => '2026-11-09', 'nights' => [5]]]]], $modes[0]['departures']);
        self::assertSame(
            [
                ['id' => 10, 'name' => 'Bucuresti', 'dates' => [['date' => '2027-04-03', 'nights' => [3, 7]], ['date' => '2027-04-10', 'nights' => [7]]]],
                ['id' => 20, 'name' => 'Cluj-Napoca', 'dates' => [['date' => '2027-05-01', 'nights' => [7]]]],
            ],
            $modes[1]['departures'],
            'a date before today is left out; one city\'s routes merge, most dates first',
        );
    }

    /** The quote endpoint asks Sphinx only about a departure the hotel's routes have. */
    public function testOnlyASavedDepartureIsAskedAbout(): void
    {
        $modes = PackageDepartures::group([
            self::route('flight', 10, 'Bucuresti', ['2027-04-03'], 7),
            self::route('flight', 20, 'Cluj-Napoca', ['2027-05-01'], 5),
        ], '2026-11-08');

        self::assertTrue(PackageDepartures::offers($modes, 'flight', 10, '2027-04-03', 7));
        self::assertTrue(PackageDepartures::offers($modes, 'flight', 0, '2027-05-01', 5), 'any city');
        self::assertFalse(PackageDepartures::offers($modes, 'flight', 10, '2027-04-03', 5), 'not that length');
        self::assertFalse(PackageDepartures::offers($modes, 'flight', 20, '2027-04-03', 7), 'not from that city');
        self::assertFalse(PackageDepartures::offers($modes, 'bus', 10, '2027-04-03', 7), 'not by bus');
        self::assertFalse(PackageDepartures::offers($modes, 'flight', 10, '2027-04-04', 7), 'not that day');
    }

    /** The page carries the cities; a city's dates come when the bar shows it. */
    public function testACitysDatesAndThePagesCities(): void
    {
        $modes = PackageDepartures::group([
            self::route('flight', 10, 'Bucuresti', ['2027-04-03'], 7),
            self::route('bus', 30, 'Iasi', ['2026-11-09'], 5),
        ], '2026-11-08');

        self::assertSame([['date' => '2027-04-03', 'nights' => [7]]], PackageDepartures::dates($modes, 'flight', 10));
        self::assertSame([], PackageDepartures::dates($modes, 'flight', 30), 'not by plane from there');
        self::assertSame(
            [['transport' => 'bus', 'departures' => [['id' => 30, 'name' => 'Iasi']]], ['transport' => 'flight', 'departures' => [['id' => 10, 'name' => 'Bucuresti']]]],
            PackageDepartures::cities($modes),
        );
    }

    public function testOnlyAccommodationWhenNoRouteReachesTheHotel(): void
    {
        self::assertSame([], PackageDepartures::group([], '2026-11-08'));
        self::assertSame([], PackageDepartures::group([self::route('flight', 10, 'Bucuresti', ['2026-01-01'], 7)], '2026-11-08'));
    }

    public function testRoutesToTheHotelsDestinationOrOneAboveIt(): void
    {
        $asked = [];
        DbStub::$getField = static function (string $query, mixed ...$params) use (&$asked): int {
            $asked[] = [$query, $params];

            return match (true) {
                str_contains($query, 'FROM ?:sphinx_hotels') => 168566,          // Antalya City
                $params === [168566] => 3713,                                    // Antalya (region)
                $params === [3713] => 233,                                       // Turkey
                default => 0,
            };
        };
        $routesAsked = [];
        DbStub::$getArray = static function (string $query, mixed ...$params) use (&$routesAsked): array {
            $routesAsked = [$query, $params];

            return [self::route('flight', 10, 'Bucuresti', ['2027-04-03'], 7)];
        };

        $modes = PackageDepartures::forHotel('3612', '2026-11-08');

        self::assertStringContainsString("WHERE hotel_id = ?s AND sync_status = 'active'", $asked[0][0]);
        self::assertSame(['3612'], $asked[0][1]);
        self::assertStringContainsString('FROM ?:sphinx_package_routes WHERE arrival_id IN (?n)', $routesAsked[0]);
        self::assertSame([[168566, 3713, 233]], $routesAsked[1], 'the city, its region, its country');
        self::assertSame('flight', $modes[0]['transport']);
    }

    public function testAHotelNotStoredHasNoModes(): void
    {
        DbStub::$getField = static fn (): int => 0;
        DbStub::$getArray = static fn (): array => self::fail('no route is read');

        self::assertSame([], PackageDepartures::forHotel('999', '2026-11-08'));
    }

    public function testADestinationLoopStops(): void
    {
        DbStub::$getField = static fn (string $query, mixed ...$params): int => $params === [1] ? 2 : 1;

        self::assertSame([1, 2], PackageDepartures::chain(1));
    }
}
