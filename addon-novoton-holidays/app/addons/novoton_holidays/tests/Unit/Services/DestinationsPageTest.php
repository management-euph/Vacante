<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\DestinationsPage;

/**
 * The Destinations page: what each country and resort shows, what the first
 * Save keeps, and what a Save accepts.
 */
final class DestinationsPageTest extends TestCase
{
    private const array KNOWN = ['ALBANIA', 'BULGARIA', 'GREECE'];

    /** @return array{country: string, resort: string, hotels: int, priced: int, products: int, live: int, first_seen: string, last_seen: string} */
    private static function row(string $country, string $resort, int $hotels, string $firstSeen = '2026-01-01 00:00:00', string $lastSeen = '2026-09-20 03:00:00', int $live = 0): array
    {
        return [
            'country' => $country, 'resort' => $resort, 'hotels' => $hotels, 'priced' => (int) floor($hotels / 2),
            'products' => $live, 'live' => $live, 'first_seen' => $firstSeen, 'last_seen' => $lastSeen,
        ];
    }

    /** @return list<array{country: string, resort: string, hotels: int, priced: int, products: int, live: int, first_seen: string, last_seen: string}> */
    private static function catalog(): array
    {
        return [
            self::row('BULGARIA', 'SUNNY BEACH', 206, live: 40),
            self::row('BULGARIA', 'BANSKO', 138, live: 4),
            self::row('BULGARIA', 'KITEN', 6, '2026-09-26 03:00:00'),
            self::row('BULGARIA', 'HISTORICAL PARK', 1, lastSeen: '2026-08-01 03:00:00'),
            self::row('BULGARIA', 'GIFT VOUCHER', 1),
            self::row('ALBANIA', 'DURRES', 17),
        ];
    }

    /** @param array<string, mixed> $page */
    private static function country(array $page, string $country): array
    {
        foreach ($page['countries'] as $c) {
            if ($c['country'] === $country) {
                return $c;
            }
        }
        self::fail("{$country} not listed");
    }

    /** @param array<string, mixed> $country */
    private static function resort(array $country, string $name): array
    {
        foreach ($country['resorts'] as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }
        self::fail("{$name} not listed");
    }

    /** Before any Save: the older settings, shown as a whitelist, so the first Save changes nothing. */
    public function testUnconfiguredShowsTodaysSettingsAsAWhitelist(): void
    {
        $page = DestinationsPage::build(DestinationScope::fromRows([]), self::catalog(), self::KNOWN, ['BULGARIA', 'ALBANIA'], ['BANSKO'], ['GIFT VOUCHER'], []);

        self::assertFalse($page['configured']);
        $bg = self::country($page, 'BULGARIA');
        self::assertSame(DestinationScope::MODE_SPECIFIC, $bg['mode']);
        self::assertFalse(self::resort($bg, 'BANSKO')['selected']);
        self::assertTrue(self::resort($bg, 'SUNNY BEACH')['selected']);
        self::assertTrue(self::resort($bg, 'KITEN')['selected'], 'a blacklist sold new resorts, so the snapshot ticks them');
        self::assertSame(DestinationScope::MODE_ALL, self::country($page, 'ALBANIA')['mode']);
        self::assertSame(DestinationScope::MODE_OFF, self::country($page, 'GREECE')['mode']);
        self::assertSame([], $page['outside'], 'nothing is outside a whitelist that does not exist');
    }

    public function testHiddenResortsAreNeverListed(): void
    {
        $page = DestinationsPage::build(DestinationScope::fromRows([]), self::catalog(), self::KNOWN, ['BULGARIA'], [], ['GIFT VOUCHER'], []);
        $names = array_column(self::country($page, 'BULGARIA')['resorts'], 'name');

        self::assertNotContains('GIFT VOUCHER', $names);
    }

    public function testEveryKnownCountryIsListedSoItCanBeSoldBeforeItHasHotels(): void
    {
        $page = DestinationsPage::build(DestinationScope::fromRows([]), [], self::KNOWN, [], [], [], []);

        self::assertSame(['Albania', 'Bulgaria', 'Greece'], array_column($page['countries'], 'label'));
    }

    public function testNewAndGoneResortsAreFlagged(): void
    {
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific', 'reviewed_at' => '2026-09-25 12:00:00'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'HISTORICAL PARK', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'OLD NAME', 'selection_type' => 'specific'],
        ]);
        $page = DestinationsPage::build($scope, self::catalog(), self::KNOWN, [], [], ['GIFT VOUCHER'], []);
        $bg = self::country($page, 'BULGARIA');

        self::assertTrue(self::resort($bg, 'KITEN')['new'], 'first seen after the last Save');
        self::assertFalse(self::resort($bg, 'BANSKO')['new'], 'known before the last Save: a choice, not new');
        self::assertTrue(self::resort($bg, 'HISTORICAL PARK')['gone'], 'missing from the latest hotel_list');
        $old = self::resort($bg, 'OLD NAME');
        self::assertTrue($old['gone'] && $old['selected'], 'a whitelisted name no hotel carries stays listed, ticked');
        self::assertSame(1, $page['totals']['new']);
        self::assertSame(2, $page['totals']['gone']);
        self::assertSame(1, $bg['sold'], 'gone resorts are not counted as sold');
        self::assertIsArray($bg['resorts'], 'the resort list');
        self::assertSame(3, $bg['resort_count'], 'its own key (the page once printed "Array resorts"); gone resorts are not counted');
    }

    public function testLiveProductsOutsideTheSavedWhitelistAreListed(): void
    {
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
        ]);
        $live = [
            ['product_id' => 11, 'hotel_name' => 'Sol Nessebar', 'country' => 'BULGARIA', 'resort' => 'SUNNY BEACH'],
            ['product_id' => 12, 'hotel_name' => 'Kempinski Grand Arena', 'country' => 'BULGARIA', 'resort' => 'BANSKO'],
            ['product_id' => 13, 'hotel_name' => 'Hotel Durres', 'country' => 'ALBANIA', 'resort' => 'DURRES'],
        ];
        $page = DestinationsPage::build($scope, self::catalog(), self::KNOWN, [], [], [], $live);

        self::assertSame([12, 13], array_column($page['outside'], 'product_id'));
        self::assertSame(2, $page['totals']['outside']);
    }

    public function testTotalsCountWhatIsSold(): void
    {
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
            ['country' => 'ALBANIA', 'resort' => '', 'selection_type' => 'all'],
        ]);
        $page = DestinationsPage::build($scope, self::catalog(), self::KNOWN, [], [], ['GIFT VOUCHER'], []);

        self::assertSame(2, $page['totals']['countries']);
        self::assertSame(2, $page['totals']['resorts']);
        self::assertSame(206 + 17, $page['totals']['hotels']);
        self::assertSame(['BULGARIA', 'ALBANIA', 'GREECE'], array_column($page['countries'], 'country'), 'sold first, biggest first');
    }

    public function testTheFormBecomesWhitelistRows(): void
    {
        $rows = DestinationsPage::rowsFromPost([
            'BULGARIA' => ['mode' => 'specific', 'items' => ['SUNNY BEACH', ' BANSKO ', 'bansko', '', 'GIFT VOUCHER', ['x']]],
            'ALBANIA' => ['mode' => 'all', 'items' => ['DURRES']],
            'GREECE' => ['mode' => 'off', 'items' => ['THASSOS']],
            'MARS' => ['mode' => 'all'],
            'TURKEY' => 'all',
        ], self::KNOWN, ['GIFT VOUCHER']);

        self::assertSame([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'BANSKO', 'selection_type' => 'specific'],
            ['country' => 'ALBANIA', 'resort' => '', 'selection_type' => 'all'],
        ], $rows);
    }

    /** Saving nothing would read back as "not configured", so the controller refuses it. */
    public function testTheControllerRefusesAWhitelistWithNoCountry(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_destinations.php');

        self::assertStringContainsString("if (\$sold === []) {", $src);
        self::assertStringContainsString('novoton_holidays.dest_none_sold', $src);
        self::assertLessThan(strpos($src, '$repo->replaceAll('), strpos($src, "if (\$sold === []) {"));
    }

    /** Disabling touches only products still outside the saved whitelist AND confirmed on the page. */
    public function testDisablingIsLimitedToConfirmedOutsideProducts(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_destinations.php');

        self::assertStringContainsString('array_intersect($outside, $confirmed)', $src);
        $repo = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/DestinationWhitelistRepository.php');
        self::assertStringContainsString("UPDATE ?:products SET status = 'D' WHERE product_id IN (?n) AND status = 'A'", $repo);
        self::assertStringNotContainsString('DELETE FROM ?:products', $repo);
    }
}
