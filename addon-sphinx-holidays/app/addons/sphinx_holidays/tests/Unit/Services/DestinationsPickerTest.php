<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\DestinationsPicker;

/**
 * The Destination whitelist page: countries › regions › cities, whole
 * regions, figures rolled up to the city shown, new destinations, products
 * outside, and the posted page back as whitelist rows.
 */
final class DestinationsPickerTest extends TestCase
{
    /** @return list<array{destination_id: int, name: string, country_code: string, continent: string}> */
    private static function countries(): array
    {
        return [
            ['destination_id' => 1, 'name' => 'Greece', 'country_code' => 'GR', 'continent' => 'Europe'],
            ['destination_id' => 2, 'name' => 'Turkey', 'country_code' => 'TR', 'continent' => 'Asia'],
            ['destination_id' => 3, 'name' => 'Italy', 'country_code' => 'IT', 'continent' => 'Europe'],
            ['destination_id' => 5, 'name' => 'Andorra', 'country_code' => 'AD', 'continent' => 'Europe'],
        ];
    }

    /** @return list<array{destination_id: int, parent_id: int, name: string, type: string, country_code: string, first_seen_at: string}> */
    private static function tree(): array
    {
        $n = static fn (int $id, int $p, string $name, string $type, string $first = ''): array => [
            'destination_id' => $id, 'parent_id' => $p, 'name' => $name, 'type' => $type, 'country_code' => $id >= 40 && $id < 50 ? 'TR' : 'GR', 'first_seen_at' => $first,
        ];

        return [
            $n(10, 1, 'Crete', 'region'), $n(11, 10, 'Heraklion', 'city'), $n(12, 10, 'Chania', 'city'), $n(111, 11, 'Hersonissos', 'destination'),
            $n(20, 1, 'Rhodes', 'region'), $n(21, 20, 'Lindos', 'city'), $n(22, 20, 'Faliraki', 'city', '2026-09-26 02:00:00'),
            $n(40, 2, 'Antalya', 'region'), $n(41, 40, 'Belek', 'city'),
        ];
    }

    /** @return list<array{destination_id: int, selection_type: string, type: string, country_code: string}> */
    private static function whitelist(): array
    {
        return [
            ['destination_id' => 1, 'selection_type' => 'specific', 'type' => 'country', 'country_code' => 'GR'],
            ['destination_id' => 10, 'selection_type' => 'all', 'type' => 'region', 'country_code' => 'GR'],
            ['destination_id' => 21, 'selection_type' => 'specific', 'type' => 'city', 'country_code' => 'GR'],
            ['destination_id' => 2, 'selection_type' => 'all', 'type' => 'country', 'country_code' => 'TR'],
        ];
    }

    /** @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>} */
    private static function built(): array
    {
        return DestinationsPicker::build(
            self::countries(),
            ['IT' => ['groups' => 3, 'items' => 6], 'AD' => ['groups' => 0, 'items' => 0]],
            ['IT' => ['hotels' => 4, 'live' => 1]],
            self::tree(),
            [11 => ['hotels' => 120, 'live' => 44], 111 => ['hotels' => 30, 'live' => 10], 21 => ['hotels' => 25, 'live' => 9], 41 => ['hotels' => 80, 'live' => 35]],
            [['circuit_id' => 1, 'name' => 'Crete Classic', 'product_id' => 90, 'live' => true, 'destination_ids' => [111, 12]]],
            self::whitelist(),
            '2026-09-20 10:00:00',
        );
    }

    /** @return array<string, mixed> */
    private static function country(array $page, string $cc): array
    {
        foreach ($page['countries'] as $c) {
            if ($c['key'] === $cc) {
                return $c;
            }
        }
        self::fail("{$cc} not listed");
    }

    public function testRegionsAreGroupsAndATickedRegionIsWhole(): void
    {
        $gr = self::country(self::built(), 'GR');

        self::assertSame('specific', $gr['mode']);
        self::assertSame('Europe', $gr['facet']);
        self::assertSame(['10', '20'], array_column($gr['groups'], 'key'));
        self::assertTrue($gr['groups'][0]['whole']);
        self::assertSame([true, true], array_column($gr['groups'][0]['items'], 'selected'), 'a whole region shows every city ticked');
        self::assertFalse($gr['groups'][1]['whole']);
        self::assertSame(['Faliraki', 'Lindos'], array_column($gr['groups'][1]['items'], 'label'));
        self::assertSame([false, true], array_column($gr['groups'][1]['items'], 'selected'));
    }

    /** Hotels and circuits deeper down (a resort under a city) count for the city shown. */
    public function testFiguresRollUpToTheCity(): void
    {
        $crete = self::country(self::built(), 'GR')['groups'][0];

        self::assertSame(['Chania', 'Heraklion'], array_column($crete['items'], 'label'));
        self::assertSame(150, $crete['items'][1]['hotels'], 'Heraklion and its resort Hersonissos');
        self::assertSame(54, $crete['items'][1]['live']);
        self::assertSame([1, 1], array_column($crete['items'], 'circuits'));
    }

    public function testNewCitiesAndTheTotals(): void
    {
        $page = self::built();
        $rhodes = self::country($page, 'GR')['groups'][1];

        self::assertTrue($rhodes['items'][0]['new'], 'Faliraki, first seen after the last Save');
        self::assertSame(1, $page['totals']['new']);
        self::assertSame(2, $page['totals']['countries']);
        self::assertSame(4, $page['totals']['cities'], 'Crete (2), Lindos, Belek');
        self::assertSame(3, $page['totals']['regions']);
        self::assertSame(150 + 25 + 80, $page['totals']['hotels']);
    }

    /** About 200 countries: those not in the whitelist carry SQL totals, and their body loads on open. */
    public function testCountriesNotSoldCarryTheirTotals(): void
    {
        $page = self::built();
        $it = self::country($page, 'IT');

        self::assertTrue($it['lazy']);
        self::assertSame(['total' => 6, 'groups' => 3], array_intersect_key($it['stats'], ['total' => 0, 'groups' => 0]));
        self::assertSame(4, $it['stats']['all']['hotels']);
        self::assertFalse($it['empty']);
        self::assertTrue(self::country($page, 'AD')['empty'], 'nothing synced: folded');
        self::assertSame(['GR', 'TR', 'IT', 'AD'], array_column($page['countries'], 'key'), 'sold first');
    }

    public function testThePostedPageBecomesRows(): void
    {
        $gr = self::country(self::built(), 'GR');
        $rows = DestinationsPicker::rows([
            'GR' => ['mode' => 'specific', 'items' => ['11', '12', '22', '99'], 'groups' => ['10', '77'], 'loaded' => true],
            'TR' => ['mode' => 'all', 'items' => [], 'groups' => [], 'loaded' => false],
            'IT' => ['mode' => 'off', 'items' => [], 'groups' => [], 'loaded' => false],
            'XX' => ['mode' => 'all', 'items' => [], 'groups' => [], 'loaded' => false],
        ], ['GR' => 1, 'TR' => 2, 'IT' => 3], ['GR' => DestinationsPicker::treeOf($gr)], DestinationsPicker::scope(self::whitelist()));

        self::assertSame([
            ['destination_id' => 1, 'selection_type' => 'specific'],
            ['destination_id' => 10, 'selection_type' => 'all'],
            ['destination_id' => 22, 'selection_type' => 'specific'],
            ['destination_id' => 2, 'selection_type' => 'all'],
        ], $rows, 'a city in a whole region needs no row; unknown ids are dropped');
    }

    public function testACountryNeverOpenedKeepsItsRows(): void
    {
        $rows = DestinationsPicker::rows(
            ['GR' => ['mode' => 'specific', 'items' => [], 'groups' => [], 'loaded' => false]],
            ['GR' => 1],
            [],
            DestinationsPicker::scope(self::whitelist()),
        );

        self::assertSame([
            ['destination_id' => 1, 'selection_type' => 'specific'],
            ['destination_id' => 10, 'selection_type' => 'all'],
            ['destination_id' => 21, 'selection_type' => 'specific'],
        ], $rows);
    }

    public function testProductsOutsideAndCircuitsInScope(): void
    {
        $circuits = [
            ['circuit_id' => 1, 'name' => 'Crete Classic', 'product_id' => 90, 'live' => true, 'destination_ids' => [111]],
            ['circuit_id' => 2, 'name' => 'Grand Tour of Portugal', 'product_id' => 91, 'live' => true, 'destination_ids' => [500]],
            ['circuit_id' => 3, 'name' => 'Anywhere', 'product_id' => 92, 'live' => true, 'destination_ids' => []],
        ];
        $out = DestinationsPicker::outside(
            [['product_id' => 7, 'destination_id' => 111, 'place' => 'Hersonissos, Greece'], ['product_id' => 8, 'destination_id' => 600, 'place' => 'Djerba, Tunisia']],
            $circuits,
            [1, 10, 11, 12, 111],
        );

        self::assertSame([8, 91], array_column($out['outside'], 'product_id'), 'a circuit with no destination passes the syncs too');
        self::assertSame(1, $out['circuits_in_scope']);
        self::assertSame([], DestinationsPicker::outside([['product_id' => 8, 'destination_id' => 600, 'place' => '']], [], [])['outside'], 'no whitelist yet: nothing to disable');
    }

    public function testTheCardListsSoldCountries(): void
    {
        $card = DestinationsPicker::card(self::built(), ['outside' => [], 'circuits_in_scope' => 1], 'edit');

        self::assertSame('sphinx-destinations', $card['id']);
        self::assertSame(['Greece', 'Turkey'], array_column($card['rows'], 'label'));
        self::assertSame(175, $card['rows'][0]['cells'][0]['value']);
        self::assertSame(2, $card['rows'][0]['cells'][1]['value'], 'circuits in the sold cities');
        self::assertSame(2, $card['off']);
    }
}
