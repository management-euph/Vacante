<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Providers\SphinxPackageModesProvider;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * A Sphinx hotel's page gets its package modes (board 16.2): the provider is
 * registered with Travel Core, gives the page its cities only (a city's
 * dates and the offers come from two JSON endpoints), and the endpoints ask
 * Sphinx only about a hotel the store sells and a departure its routes have.
 */
final class PackageModesWiringTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testTheProviderIsRegistered(): void
    {
        self::assertStringContainsString(
            "setPackageModesProvider(\n        'sphinx',\n        new \\Tygh\\Addons\\SphinxHolidays\\Providers\\SphinxPackageModesProvider(),",
            self::read('init.php'),
        );
    }

    public function testThePageGetsTheCitiesWithoutTheirDates(): void
    {
        DbStub::$getField = static fn (string $query, mixed ...$params): int => str_contains($query, 'sphinx_hotels') ? 168566 : 0;
        DbStub::$getArray = static fn (): array => [[
            'transport_type' => 'flight', 'departure_id' => 10, 'departure_name' => 'Bucuresti',
            'available_dates' => json_encode([date('Y-m-d', strtotime('+30 days'))]), 'duration' => 7,
        ]];

        self::assertSame(
            [['transport' => 'flight', 'departures' => [['id' => 10, 'name' => 'Bucuresti']]]],
            (new SphinxPackageModesProvider())->packageModes('3612'),
        );
    }

    public function testTheEndpointsAskOnlyAboutWhatTheStoreSells(): void
    {
        $modes = self::read('controllers/frontend/sphinx_booking.php');
        self::assertStringContainsString("'package_quote',", $modes);
        self::assertStringContainsString("'package_dates',", $modes);

        $quote = self::read('controllers/frontend/sphinx_booking/package_quote.php');
        self::assertStringContainsString('&& Container::getCartService()->resolveProductId($hotelId) > 0', $quote);
        self::assertStringContainsString('&& PackageDepartures::offers(PackageDepartures::forHotel($hotelId, date(\'Y-m-d\')), $transport, $departureId, $departureDate, $nights)', $quote);
        self::assertStringContainsString('&& CircuitRoomOffers::validOccupancy($occupancy, ProviderRoomLimit::maxRooms(\'sphinx\'));', $quote);
        self::assertStringContainsString('->forHotel($hotelId, $transport, $departureId, $departureDate, $nights, $occupancy) + $answer;', $quote);
        self::assertStringContainsString("header('Content-Type: application/json; charset=utf-8');", $quote);

        $dates = self::read('controllers/frontend/sphinx_booking/package_dates.php');
        self::assertStringContainsString('Container::getCartService()->resolveProductId($hotelId) > 0', $dates);
        self::assertStringContainsString('PackageDepartures::dates(PackageDepartures::forHotel($hotelId, date(\'Y-m-d\')), $transport, $departureId)', $dates);
    }
}
