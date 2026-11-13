<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\HotelListingRepository;
use Tygh\Addons\NovotonHolidays\ViewModels\HotelSearchFilters;

/**
 * Novoton → Hotels: the filters in the shape CS-Cart's filter bar reads,
 * so the page gets the Products page's own bar.
 */
final class HotelSearchFiltersTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function build(array $params): array
    {
        $search = (new HotelListingRepository())->normalize($params);

        return HotelSearchFilters::build($search, ['Bulgaria', 'Greece'], static fn (string $key, array $p = []): string => $key . ($p === [] ? '' : json_encode($p)));
    }

    public function testTheBarHasTheFormAndDispatchCoreNeeds(): void
    {
        $filters = self::build([]);

        self::assertSame(HotelSearchFilters::FORM_ID, $filters['form_id']);
        self::assertSame('novoton_hotels.manage', $filters['dispatch']);
        self::assertSame(1000, $filters['data']['q']['priority'], 'the widest input becomes the search field');
    }

    public function testStarsAreCheckboxesEachItsOwnParameter(): void
    {
        $stars = self::build(['stars_4' => 'Y', 'stars_5' => 'Y'])['data']['stars'];

        self::assertSame('checkbox', $stars['type']);
        self::assertSame(['stars_5', 'stars_4', 'stars_3', 'stars_2', 'stars_1', 'stars_none'], array_keys($stars['nested_data']));
        self::assertTrue($stars['nested_data']['stars_5']['is_checked']);
        self::assertTrue($stars['nested_data']['stars_4']['is_checked']);
        self::assertFalse($stars['nested_data']['stars_3']['is_checked']);
        self::assertSame('Y', $stars['nested_data']['stars_1']['value']);
    }

    public function testARadioChecksTheValueInForceOrAll(): void
    {
        $country = self::build(['country' => 'Greece'])['data']['country'];

        self::assertSame('radio', $country['type']);
        self::assertFalse($country['nested_data']['country_all']['value'], 'the first choice is "all"');
        self::assertFalse($country['nested_data']['country_all']['is_checked']);
        self::assertTrue($country['nested_data']['country_1']['is_checked']);

        $ages = self::build([])['data']['not_synced_days']['nested_data'];
        self::assertTrue($ages['not_synced_days_0']['is_checked'], 'no age filter: "any time"');
        self::assertSame('7', $ages['not_synced_days_1']['value']);
    }

    public function testTheLessUsedFiltersWaitInAddFilter(): void
    {
        $data = self::build([])['data'];

        foreach (['country', 'stars', 'city', 'not_synced_days'] as $id) {
            self::assertSame('primary', $data[$id]['category'], $id);
        }
        foreach (['product', 'sync_status', 'property_type'] as $id) {
            self::assertSame('secondary', $data[$id]['category'], $id);
        }
    }
}
