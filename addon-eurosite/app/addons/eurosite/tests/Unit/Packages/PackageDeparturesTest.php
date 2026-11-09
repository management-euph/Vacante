<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\PackageDepartures;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;

/**
 * A Eurosite hotel's package modes, from the package routes to its city: per
 * transport the departure cities, their dates ahead and the stays offered.
 */
final class PackageDeparturesTest extends TestCase
{
    private const string TODAY = '2026-11-09';

    #[\Override]
    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @return list<array<string, mixed>> */
    private static function routes(): array
    {
        return [
            ['transport' => 'plane', 'dep_city_code' => 'ROCLJNPC', 'dep_country_code' => 'RO', 'dep_city_name' => 'Cluj Napoca', 'dates_json' => '["2027-05-18","2027-05-11"]'],
            ['transport' => 'plane', 'dep_city_code' => 'robch1', 'dep_country_code' => 'ro', 'dep_city_name' => 'Bucharest', 'dates_json' => '["2026-11-01","2026-11-14","2026-11-21","2026-11-28"]'],
            ['transport' => 'bus', 'dep_city_code' => 'ROBCH1', 'dep_country_code' => 'RO', 'dep_city_name' => 'Bucharest', 'dates_json' => '["2027-06-12"]'],
            // A transport the page has no mode for, a city with every date gone, no code: left out.
            ['transport' => 'search', 'dep_city_code' => 'ROTMS', 'dep_country_code' => 'RO', 'dep_city_name' => 'Timisoara', 'dates_json' => '["2027-06-12"]'],
            ['transport' => 'plane', 'dep_city_code' => 'ROTMS', 'dep_country_code' => 'RO', 'dep_city_name' => 'Timisoara', 'dates_json' => '["2026-09-13"]'],
            ['transport' => 'plane', 'dep_city_code' => '', 'dep_city_name' => 'Nowhere', 'dates_json' => '["2027-06-12"]'],
        ];
    }

    public function testTheCoachThenThePlaneEachCityWithItsDatesAheadAndTheStaysOffered(): void
    {
        $modes = PackageDepartures::group(self::routes(), self::TODAY, [7, 10, 14]);

        self::assertSame(['bus', 'flight'], array_column($modes, 'transport'));
        $bucharest = PackageDepartures::departureId('ROBCH1');
        self::assertSame([[
            'id' => $bucharest,
            'code' => 'ROBCH1',
            'country_code' => 'RO',
            'name' => 'Bucharest',
            'dates' => [['date' => '2027-06-12', 'nights' => [7, 10, 14]]],
        ]], $modes[0]['departures']);

        // The city with the most dates first; the dates ahead only, in order; codes as Eurosite's (upper case).
        $plane = $modes[1]['departures'];
        self::assertSame(['Bucharest', 'Cluj Napoca'], array_column($plane, 'name'));
        self::assertSame(['ROBCH1', 'RO'], [$plane[0]['code'], $plane[0]['country_code']]);
        self::assertSame(['2026-11-14', '2026-11-21', '2026-11-28'], array_column($plane[0]['dates'], 'date'));
        self::assertSame(['2027-05-11', '2027-05-18'], array_column($plane[1]['dates'], 'date'));
    }

    /** The bar knows a city by a positive number; the same code is always the same number. */
    public function testADepartureCityHasAStableNumber(): void
    {
        $id = PackageDepartures::departureId('ROBCH1');

        self::assertGreaterThan(0, $id);
        self::assertSame($id, PackageDepartures::departureId(' robch1 '));
        self::assertNotSame($id, PackageDepartures::departureId('ROCLJNPC'));
        self::assertSame(crc32('ROBCH1'), $id);
    }

    public function testNoStaysOfferedIsNoMode(): void
    {
        self::assertSame([], PackageDepartures::group(self::routes(), self::TODAY, []));
        self::assertSame([], PackageDepartures::group([], self::TODAY, [7]));
    }

    public function testThePageGetsTheCitiesWithoutTheirDates(): void
    {
        $modes = PackageDepartures::group(self::routes(), self::TODAY, [7]);

        self::assertSame([
            ['transport' => 'bus', 'departures' => [['id' => PackageDepartures::departureId('ROBCH1'), 'name' => 'Bucharest']]],
            ['transport' => 'flight', 'departures' => [
                ['id' => PackageDepartures::departureId('ROBCH1'), 'name' => 'Bucharest'],
                ['id' => PackageDepartures::departureId('ROCLJNPC'), 'name' => 'Cluj Napoca'],
            ]],
        ], PackageDepartures::cities($modes));

        // A city without a name shows its code.
        $nameless = PackageDepartures::group([['transport' => 'bus', 'dep_city_code' => 'ROIAS', 'dates_json' => '["2027-06-12"]']], self::TODAY, [7]);
        self::assertSame('ROIAS', PackageDepartures::cities($nameless)[0]['departures'][0]['name']);
    }

    public function testACitysDatesAndWhetherADepartureIsOffered(): void
    {
        $modes = PackageDepartures::group(self::routes(), self::TODAY, [7, 10, 14]);
        $cluj = PackageDepartures::departureId('ROCLJNPC');

        self::assertSame(['2027-05-11', '2027-05-18'], array_column(PackageDepartures::dates($modes, 'flight', $cluj), 'date'));
        self::assertSame([], PackageDepartures::dates($modes, 'bus', $cluj), 'Cluj has no coach');
        self::assertSame([], PackageDepartures::dates($modes, 'flight', 12345));

        self::assertSame('ROCLJNPC', PackageDepartures::offered($modes, 'flight', $cluj, '2027-05-11', 10)['code'] ?? '');
        self::assertNull(PackageDepartures::offered($modes, 'flight', $cluj, '2027-05-11', 8), 'a stay not offered');
        self::assertNull(PackageDepartures::offered($modes, 'flight', $cluj, '2027-05-12', 7), 'no departure that day');
        self::assertNull(PackageDepartures::offered($modes, 'bus', $cluj, '2027-05-11', 7));
    }

    /** Only a hotel the store sells: listed, with a product. Its city's routes. */
    public function testAHotelIsAskedByItsCityAndOnlyWhenSold(): void
    {
        $asked = [];
        DbStub::$getField = static function (string $sql, mixed ...$params) use (&$asked): string {
            $asked[] = [$sql, $params];

            return $params[0] === 'TR0012' ? 'TRALN' : '';
        };
        DbStub::$getArray = static function (string $sql, mixed ...$params) use (&$asked): array {
            $asked[] = [$sql, $params];

            return self::routes();
        };

        self::assertSame([], PackageDepartures::forHotel('XX0001', self::TODAY, [7]));
        self::assertCount(1, $asked, 'no routes asked for a hotel not sold');
        self::assertStringContainsString("sync_status = 'active' AND product_id > 0", $asked[0][0]);

        $modes = PackageDepartures::forHotel('TR0012', self::TODAY, [7]);
        self::assertSame(['bus', 'flight'], array_column($modes, 'transport'));
        self::assertStringContainsString('FROM ?:eurosite_package_routes WHERE city_code = ?s', $asked[2][0]);
        self::assertSame(['TRALN'], $asked[2][1]);
    }
}
