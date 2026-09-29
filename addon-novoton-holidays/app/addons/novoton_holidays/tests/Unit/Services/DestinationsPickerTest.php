<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\DestinationsPage;
use Tygh\Addons\NovotonHolidays\Services\DestinationsPicker;

/**
 * Novoton's Destinations page and dashboard card in Travel Core's shared
 * destination picker.
 */
final class DestinationsPickerTest extends TestCase
{
    /** @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, hotel_name: string, country: string, resort: string}>} */
    private static function page(): array
    {
        $row = static fn (string $c, string $r, int $h, int $live = 0): array => [
            'country' => $c, 'resort' => $r, 'hotels' => $h, 'priced' => 1, 'products' => $live, 'live' => $live,
            'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-09-20 03:00:00',
        ];
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
            ['country' => 'ALBANIA', 'resort' => '', 'selection_type' => 'all'],
        ]);

        return DestinationsPage::build(
            $scope,
            [$row('BULGARIA', 'SUNNY BEACH', 206, 3), $row('BULGARIA', 'BANSKO', 138, 2), $row('ALBANIA', 'DURRES', 17)],
            ['ALBANIA', 'BULGARIA', 'CYPRUS'],
            [],
            [],
            [],
            [['product_id' => 7, 'hotel_name' => 'Kempinski', 'country' => 'BULGARIA', 'resort' => 'BANSKO']],
        );
    }

    /** @return array<string, mixed> */
    private static function country(array $dest, string $key): array
    {
        foreach ($dest['countries'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        self::fail("{$key} not listed");
    }

    public function testThePageHasNovotonsModesAndResorts(): void
    {
        $dest = DestinationsPicker::page(self::page(), ['search' => 'Search'], 'save', 'disable');

        self::assertSame(['off', 'all', 'specific'], array_column($dest['modes'], 'value'));
        self::assertSame(['none', 'all', 'ticked'], array_column($dest['modes'], 'sells'));
        self::assertSame('save', $dest['save_url']);
        self::assertSame('disable', $dest['outside_url']);

        $bg = self::country($dest, 'BULGARIA');
        self::assertSame('specific', $bg['mode']);
        $items = $bg['groups'][0]['items'];
        self::assertSame(['BANSKO', 'SUNNY BEACH'], array_column($items, 'value'), 'posted back as the stored name');
        self::assertSame(['Bansko', 'Sunny Beach'], array_column($items, 'label'));
        self::assertSame([false, true], array_column($items, 'selected'));
        self::assertSame(2, $items[0]['live']);
        self::assertSame([], self::country($dest, 'CYPRUS')['groups'], 'no hotels: one message, not two');
    }

    /** The old page printed "Array resorts · 1121 hotels": the resort list and count shared a key. */
    public function testTheCountryLineCountsResorts(): void
    {
        $bg = self::country(DestinationsPicker::page(self::page(), [], 'save', 'disable'), 'BULGARIA');

        self::assertIsString($bg['meta']);
        self::assertStringNotContainsString('Array', $bg['meta']);
    }

    public function testOutsideProductsAreGroupedByResort(): void
    {
        $dest = DestinationsPicker::page(self::page(), [], 'save', 'disable');

        self::assertSame(['n' => 1, 'ids' => '7', 'groups' => [['label' => 'Bansko, Bulgaria', 'n' => 1]]], $dest['outside']);
    }

    public function testNothingIsDisabledBeforeAWhitelistExists(): void
    {
        $page = self::page();
        $page['configured'] = false;

        self::assertSame('', DestinationsPicker::page($page, [], 'save', 'disable')['outside_url']);
    }

    public function testTheCardListsSoldCountries(): void
    {
        $card = DestinationsPicker::card(self::page(), 'edit');

        self::assertSame('novoton-destinations', $card['id']);
        self::assertSame(['Bulgaria', 'Albania'], array_column($card['rows'], 'label'));
        self::assertSame(['specific', 'all'], array_column($card['rows'], 'badge_class'));
        self::assertSame(206, $card['rows'][0]['cells'][0]['value']);
        self::assertSame(3, $card['rows'][0]['cells'][2]['value']);
        self::assertSame(1, $card['off']);
    }
}
