<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

/**
 * The destination picker shared by Novoton, Eurosite and Sphinx: what each
 * mode sells, a country's figures and badge, and reading the form back.
 */
final class DestinationPickerTest extends TestCase
{
    /** @return list<array{value: string, label: string, hint: string, sells: string, badge: string}> */
    private static function modes(): array
    {
        return DestinationPicker::modes([
            ['value' => 'off', 'label' => 'Not sold', 'hint' => '', 'sells' => 'none', 'badge' => 'NOT SOLD'],
            ['value' => 'all', 'label' => 'All cities', 'hint' => '', 'sells' => 'all', 'badge' => 'ALL'],
            ['value' => 'own', 'label' => 'Own cities', 'hint' => '', 'sells' => 'flag:own', 'badge' => 'OWN ([sold])'],
            ['value' => 'specific', 'label' => 'Only selected', 'hint' => '', 'sells' => 'ticked', 'badge' => '[sold] of [total]'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function item(string $value, bool $selected, int $hotels, array $extra = []): array
    {
        return ['value' => $value, 'label' => ucfirst(strtolower($value)), 'selected' => $selected, 'hotels' => $hotels, 'live' => 1] + $extra;
    }

    public function testEachRuleSellsWhatItSays(): void
    {
        $own = self::item('MAMAIA', false, 10, ['flags' => ['own' => true]]);
        $plain = self::item('SIBIU', true, 5);
        $gone = self::item('OLD', true, 0, ['gone' => true]);

        self::assertFalse(DestinationPicker::sold('none', $plain));
        self::assertTrue(DestinationPicker::sold('all', $own));
        self::assertTrue(DestinationPicker::sold('flag:own', $own));
        self::assertFalse(DestinationPicker::sold('flag:own', $plain));
        self::assertTrue(DestinationPicker::sold('ticked', $plain));
        self::assertFalse(DestinationPicker::sold('ticked', $own), 'not ticked');
        self::assertTrue(DestinationPicker::sold('ticked', $own, true), 'in a ticked (whole) group');
        self::assertFalse(DestinationPicker::sold('all', $gone), 'gone from the feed: never sold');
    }

    public function testFinishCountsTheCountryAndItsBadge(): void
    {
        $country = DestinationPicker::finish([
            'key' => 'RO', 'label' => 'Romania', 'code' => 'RO', 'mode' => 'specific',
            'groups' => [[
                'key' => '',
                'items' => [
                    self::item('MAMAIA', true, 96, ['flags' => ['own' => true], 'priced' => 70]),
                    self::item('SIBIU', false, 22),
                    self::item('CORBU', false, 0, ['new' => true]),
                    self::item('SULINA', true, 0, ['gone' => true]),
                ],
            ]],
        ], self::modes());

        self::assertSame('1 of 3', $country['badge'], 'gone items are neither sold nor counted');
        self::assertSame('specific', $country['badge_class']);
        self::assertSame(1, $country['new']);
        self::assertFalse($country['empty']);
        self::assertSame('romania ro', $country['search']);
        $attrs = $country['attrs'];
        self::assertIsArray($attrs);
        self::assertSame(3, $attrs['total']);
        self::assertSame(1, $attrs['saved-sold']);
        self::assertSame(96, $attrs['saved-hotels']);
        self::assertSame(70, $attrs['saved-priced']);
        self::assertSame(3, $attrs['all-sold']);
        self::assertSame(1, $attrs['own-sold'], 'every flag mode gets its figures, for a body not loaded yet');
    }

    public function testAWholeGroupSellsEveryCityInIt(): void
    {
        $country = DestinationPicker::finish([
            'key' => 'GR', 'label' => 'Greece', 'mode' => 'specific',
            'groups' => [
                ['key' => '10', 'label' => 'Crete', 'whole' => true, 'items' => [self::item('11', false, 5), self::item('12', false, 7)]],
                ['key' => '20', 'label' => 'Rhodes', 'items' => [self::item('21', true, 3), self::item('22', false, 1)]],
                ['key' => '30', 'label' => 'Kos', 'items' => [self::item('31', false, 1)]],
            ],
        ], DestinationPicker::modes([
            ['value' => 'specific', 'sells' => 'ticked', 'badge' => '[groups_sold] of [groups] regions'],
        ]));

        self::assertSame('2 of 3 regions', $country['badge']);
        self::assertIsArray($country['attrs']);
        self::assertSame(3, $country['attrs']['saved-sold']);
        self::assertSame(15, $country['attrs']['saved-hotels']);
    }

    public function testACountryWithNothingListedIsEmpty(): void
    {
        $country = DestinationPicker::finish(['key' => 'CY', 'label' => 'Cyprus', 'mode' => 'off', 'groups' => []], self::modes());

        self::assertTrue($country['empty']);
        self::assertSame('NOT SOLD', $country['badge']);
        self::assertSame('off', $country['badge_class']);
    }

    public function testALazyCountryKeepsTheFiguresItCameWith(): void
    {
        $country = DestinationPicker::finish([
            'key' => 'TR', 'label' => 'Turkey', 'mode' => 'all', 'lazy' => true,
            'stats' => ['total' => 12, 'groups' => 0, 'saved' => ['sold' => 12, 'hotels' => 300], 'all' => ['sold' => 12, 'hotels' => 300]],
        ], self::modes());

        self::assertSame('ALL', $country['badge']);
        self::assertIsArray($country['attrs']);
        self::assertSame(12, $country['attrs']['total']);
        self::assertSame(300, $country['attrs']['all-hotels']);
        self::assertSame([], $country['groups'] ?? [], 'its body loads when it opens');
    }

    /** The script sends one JSON field: a Sphinx whitelist can outgrow max_input_vars as separate fields. */
    public function testReadPostTakesTheJsonField(): void
    {
        $picked = DestinationPicker::readPost([
            'dest_json' => json_encode([
                'RO' => ['mode' => 'specific', 'loaded' => true, 'items' => ['ROMM', ' ROSB ', 'ROMM', '', ['x']], 'groups' => []],
                'TR' => ['mode' => 'all', 'loaded' => false, 'items' => [], 'groups' => []],
                '' => ['mode' => 'all'],
                'BAD' => 'all',
            ]),
            'dest' => ['XX' => ['mode' => 'all']],
        ]);

        self::assertSame([
            'RO' => ['mode' => 'specific', 'items' => ['ROMM', 'ROSB'], 'groups' => [], 'loaded' => true],
            'TR' => ['mode' => 'all', 'items' => [], 'groups' => [], 'loaded' => false],
        ], $picked, 'the fields are ignored once the JSON is there');
    }

    public function testReadPostWorksWithoutTheScript(): void
    {
        $picked = DestinationPicker::readPost(['dest' => [
            'GR' => ['mode' => 'specific', 'loaded' => '1', 'items' => ['11'], 'groups' => ['10', '10']],
            'EG' => ['mode' => 'off'],
        ]]);

        self::assertSame(['mode' => 'specific', 'items' => ['11'], 'groups' => ['10'], 'loaded' => true], $picked['GR']);
        self::assertFalse($picked['EG']['loaded'], 'no body on the page: nothing was ticked there');
    }

    public function testProductIdsAcceptOneFieldOrAList(): void
    {
        self::assertSame([3, 5], DestinationPicker::productIds('3, 5,x,0,3'));
        self::assertSame([7], DestinationPicker::productIds(['7', 'y']));
        self::assertSame([], DestinationPicker::productIds(null));
    }

    public function testOutsideProductsAreGroupedByPlace(): void
    {
        $out = DestinationPicker::outside([
            ['product_id' => 1, 'label' => 'Oradea, Romania'],
            ['product_id' => 2, 'label' => 'Oradea, Romania'],
            ['product_id' => 9, 'label' => 'Lisbon, Portugal'],
        ]);

        self::assertSame(3, $out['n']);
        self::assertSame('1,2,9', $out['ids']);
        self::assertSame([['label' => 'Oradea, Romania', 'n' => 2], ['label' => 'Lisbon, Portugal', 'n' => 1]], $out['groups']);
    }
}
