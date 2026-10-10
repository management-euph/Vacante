<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\DestinationsPicker;

/**
 * The Destination whitelist page: four modes per country, the cities with
 * what selling them means, new and gone cities, products outside, and the
 * posted page back as whitelist rows.
 */
final class DestinationsPickerTest extends TestCase
{
    private const string SAVED = '2026-09-20 10:00:00';

    /** @return array{city_code: string, country_code: string, name: string, is_own: bool, first_seen_at: string, last_synced_at: string, hotels: int, priced: int, instant: int, live: int} */
    private static function city(string $cc, string $code, string $name, bool $own = false, int $hotels = 0, array $extra = []): array
    {
        return $extra + [
            'city_code' => $code, 'country_code' => $cc, 'name' => $name, 'is_own' => $own,
            'first_seen_at' => '', 'last_synced_at' => '2026-09-27 03:00:00',
            'hotels' => $hotels, 'priced' => intdiv($hotels, 2), 'instant' => intdiv($hotels, 4), 'live' => 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function countries(): array
    {
        return [
            ['country_code' => 'RO', 'name' => 'Romania'],
            ['country_code' => 'BG', 'name' => 'Bulgaria'],
            ['country_code' => 'TR', 'name' => 'Turkey'],
            ['country_code' => 'TT', 'name' => ''],
        ];
    }

    /** @return list<array{city_code: string, country_code: string, name: string, is_own: bool, first_seen_at: string, last_synced_at: string, hotels: int, priced: int, instant: int, live: int}> */
    private static function cities(): array
    {
        return [
            self::city('RO', 'ROMM', 'Mamaia', true, 96, ['live' => 3]),
            self::city('RO', 'ROSB', 'Sibiu', false, 22),
            self::city('RO', 'ROOR', 'Oradea', false, 11, ['live' => 2]),
            self::city('RO', 'ROCR', 'Corbu', false, 0, ['first_seen_at' => '2026-09-25 03:00:00']),
            self::city('RO', 'ROSL', 'Sulina', false, 0, ['last_synced_at' => '2026-09-01 03:00:00']),
            self::city('BG', 'BGSB', 'Sunny Beach', true, 206),
            self::city('BG', 'BGSO', 'Sofia', false, 11),
            self::city('TR', 'TRAY', 'Antalya', true),
            self::city('TR', 'TRIS', 'Istanbul', false),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function whitelist(): array
    {
        return [
            ['country_code' => 'RO', 'city_code' => '', 'selection_type' => 'specific'],
            ['country_code' => 'RO', 'city_code' => 'ROMM', 'selection_type' => 'specific'],
            ['country_code' => 'RO', 'city_code' => 'ROSB', 'selection_type' => 'specific'],
            ['country_code' => 'RO', 'city_code' => 'ROSL', 'selection_type' => 'specific'],
            ['country_code' => 'BG', 'city_code' => '', 'selection_type' => 'own'],
        ];
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

    /** @return array<string, mixed> */
    private static function item(array $country, string $code): array
    {
        foreach ($country['groups'][0]['items'] as $i) {
            if ($i['value'] === $code) {
                return $i;
            }
        }
        self::fail("{$code} not listed");
    }

    public function testTheModesAreReadFromTheRows(): void
    {
        $scope = DestinationsPicker::scope(array_merge(self::whitelist(), [
            ['country_code' => 'gr', 'city_code' => 'grth', 'selection_type' => 'specific'],
            ['country_code' => 'AL', 'city_code' => '', 'selection_type' => 'all'],
        ]));

        self::assertSame('specific', $scope['RO']['mode']);
        self::assertSame('own', $scope['BG']['mode']);
        self::assertSame('all', $scope['AL']['mode']);
        self::assertSame(['mode' => 'specific', 'cities' => ['GRTH' => true]], $scope['GR'], 'saved before the page: city rows alone');
        self::assertTrue(DestinationsPicker::allows($scope, 'BG', 'BGSB', true));
        self::assertFalse(DestinationsPicker::allows($scope, 'BG', 'BGSO', false), 'Own cities sells own-offer cities only');
        self::assertTrue(DestinationsPicker::allows($scope, 'AL', 'ANY', false));
        self::assertFalse(DestinationsPicker::allows($scope, 'TR', 'TRAY', true));
    }

    public function testEveryCountryIsListedSoldFirstWithItsFigures(): void
    {
        $page = DestinationsPicker::build(self::countries(), self::cities(), self::whitelist(), self::SAVED, []);

        self::assertSame(['BG', 'RO', 'TR', 'TT'], array_column($page['countries'], 'key'));
        self::assertSame('TT', self::country($page, 'TT')['label'], 'a name missing from the catalog falls back to the code');
        $ro = self::country($page, 'RO');
        self::assertSame(['own' => 1], $ro['flags']);
        self::assertSame(['ROMM', 'ROCR', 'ROOR', 'ROSB', 'ROSL'], array_column($ro['groups'][0]['items'], 'value'), 'own cities first, then A–Z');
        self::assertSame(2, $page['totals']['countries']);
        self::assertSame(3, $page['totals']['cities'], 'Mamaia, Sibiu, and Sunny Beach (own)');
        self::assertSame(96 + 22 + 206, $page['totals']['hotels']);
    }

    /** Hotels are fetched for whitelisted cities only: 0 would mislead. */
    public function testACityNeverSoldSaysItsHotelsAreNotSynced(): void
    {
        $page = DestinationsPicker::build(self::countries(), self::cities(), self::whitelist(), self::SAVED, []);

        self::assertTrue(self::item(self::country($page, 'TR'), 'TRAY')['meta_muted']);
        self::assertSame('eurosite.dest_city_not_synced', self::item(self::country($page, 'TR'), 'TRAY')['meta']);
        self::assertFalse(self::item(self::country($page, 'RO'), 'ROOR')['meta_muted'], 'once whitelisted, its hotels are known');
        self::assertSame('eurosite.dest_country_meta_none', self::country($page, 'TR')['meta']);
    }

    public function testNewAndGoneCitiesAreFlagged(): void
    {
        $page = DestinationsPicker::build(self::countries(), self::cities(), self::whitelist(), self::SAVED, []);
        $ro = self::country($page, 'RO');

        self::assertTrue(self::item($ro, 'ROCR')['new'], 'first seen after the last Save');
        self::assertFalse(self::item($ro, 'ROOR')['new'], 'known before the last Save: a choice, not new');
        self::assertTrue(self::item($ro, 'ROSL')['gone'], 'two days behind its country in the cities sync');
        self::assertTrue(self::item($ro, 'ROSL')['selected'], 'it stays ticked until someone unticks it');
        self::assertSame(1, $page['totals']['new']);
        self::assertSame(1, $page['totals']['gone']);

        $none = DestinationsPicker::build(self::countries(), self::cities(), self::whitelist(), '', []);
        self::assertSame(0, $none['totals']['new'], 'never saved: nothing is new');
    }

    public function testLiveProductsOutsideTheSavedWhitelistAreListed(): void
    {
        $live = static fn (int $id, string $cc, string $city, string $name): array => [
            'product_id' => $id, 'hotel_name' => 'H' . $id, 'country_code' => $cc, 'city_code' => $city, 'city_name' => $name, 'country_name' => '',
        ];
        $page = DestinationsPicker::build(self::countries(), self::cities(), self::whitelist(), self::SAVED, [
            $live(1, 'RO', 'ROMM', 'Mamaia'),
            $live(2, 'RO', 'ROOR', 'Oradea'),
            $live(3, 'BG', 'BGSO', 'Sofia'),
            $live(4, 'BG', 'BGSB', 'Sunny Beach'),
        ]);

        self::assertSame([['product_id' => 2, 'label' => 'Oradea, Romania'], ['product_id' => 3, 'label' => 'Sofia, Bulgaria']], $page['outside']);

        $unsaved = DestinationsPicker::build(self::countries(), self::cities(), [], '', [$live(1, 'RO', 'ROMM', 'Mamaia')]);
        self::assertFalse($unsaved['configured']);
        self::assertSame([], $unsaved['outside'], 'no whitelist yet: nothing to disable');
    }

    public function testThePostedPageBecomesRows(): void
    {
        $known = ['RO' => true, 'BG' => true, 'TR' => true, 'CY' => true];
        $synced = ['RO' => ['ROMM' => true, 'ROSB' => true], 'TR' => ['TRAY' => true]];
        $saved = DestinationsPicker::scope([
            ['country_code' => 'TR', 'city_code' => '', 'selection_type' => 'specific'],
            ['country_code' => 'TR', 'city_code' => 'TRAY', 'selection_type' => 'specific'],
        ]);
        $rows = DestinationsPicker::rows([
            'RO' => ['mode' => 'specific', 'items' => ['romm', 'ROXX', 'ROMM'], 'groups' => [], 'loaded' => true],
            'BG' => ['mode' => 'own', 'items' => [], 'groups' => [], 'loaded' => false],
            'TR' => ['mode' => 'specific', 'items' => [], 'groups' => [], 'loaded' => false],
            'CY' => ['mode' => 'specific', 'items' => ['CY01', 'bad code!'], 'groups' => [], 'loaded' => true],
            'MARS' => ['mode' => 'all', 'items' => [], 'groups' => [], 'loaded' => false],
            'EG' => ['mode' => 'all', 'items' => [], 'groups' => [], 'loaded' => false],
        ], $known, $synced, $saved);

        self::assertSame([
            ['country_code' => 'RO', 'city_code' => '', 'selection_type' => 'specific'],
            ['country_code' => 'RO', 'city_code' => 'ROMM', 'selection_type' => 'specific'],
            ['country_code' => 'BG', 'city_code' => '', 'selection_type' => 'own'],
            ['country_code' => 'TR', 'city_code' => '', 'selection_type' => 'specific'],
            ['country_code' => 'TR', 'city_code' => 'TRAY', 'selection_type' => 'specific'],
            ['country_code' => 'CY', 'city_code' => '', 'selection_type' => 'specific'],
            ['country_code' => 'CY', 'city_code' => 'CY01', 'selection_type' => 'specific'],
        ], $rows, 'unknown cities dropped; a body never opened keeps its ticks; no synced cities: codes from the live list');
    }

    public function testTheModesOfferOwnCitiesOnlyWhereThereAreSome(): void
    {
        $modes = DestinationsPicker::modes();

        self::assertSame(['off', 'all', 'own', 'specific'], array_column($modes, 'value'));
        self::assertSame(['none', 'all', 'flag:own', 'ticked'], array_column($modes, 'sells'));
        self::assertSame(['', '', 'own', ''], array_column($modes, 'requires'));
    }

    public function testTheCardListsSoldCountriesAndWhatNeedsALook(): void
    {
        $card = DestinationsPicker::card(DestinationsPicker::build(self::countries(), self::cities(), self::whitelist(), self::SAVED, []), 'edit');

        self::assertSame('eurosite-destinations', $card['id']);
        self::assertSame(['Bulgaria', 'Romania'], array_column($card['rows'], 'label'));
        self::assertSame(206, $card['rows'][0]['cells'][0]['value'], 'Own cities: Sunny Beach only');
        self::assertSame(2, $card['off']);
        self::assertCount(2, $card['alerts'], 'a new city in Romania, a gone one');
    }
}
