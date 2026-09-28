<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\ViewModels\UnmappedValuesView;

/**
 * The unmapped page turns each raw value into an alias: linked to an
 * existing mapping of a type it may mean, created, or dismissed.
 */
final class UnmappedValuesViewTest extends TestCase
{
    public function testTheFacilityFilterCoversEveryFacilityType(): void
    {
        // The facility scan redirects here with feature_type=facility, which
        // matched no stored row (they are hotel_facility) and showed nothing.
        self::assertSame(FeatureMapper::FACILITY_TYPES, UnmappedValuesView::typesFor('facility'));
        self::assertSame(['stars'], UnmappedValuesView::typesFor('stars'));
        self::assertSame([], UnmappedValuesView::typesFor(''));
    }

    public function testAFacilityValueMayLinkToAnyFacilityType(): void
    {
        self::assertSame(FeatureMapper::FACILITY_TYPES, UnmappedValuesView::linkTargetTypes('hotel_facility'));
        self::assertSame(['board'], UnmappedValuesView::linkTargetTypes('board'));
    }

    public function testLinkOptionsAreGroupedByTypeWithTheCodeShown(): void
    {
        $options = UnmappedValuesView::linkOptions([
            ['map_id' => '3', 'feature_type' => 'stars', 'canonical_code' => '3', 'display_name_en' => '3 Stars'],
            ['map_id' => 9, 'feature_type' => 'hotel_facility', 'canonical_code' => 'wifi', 'display_name_en' => 'wifi'],
            ['map_id' => 0, 'feature_type' => 'stars', 'canonical_code' => 'x', 'display_name_en' => ''],
        ]);

        self::assertSame([
            'stars' => [['map_id' => 3, 'label' => '3 Stars (3)']],
            'hotel_facility' => [['map_id' => 9, 'label' => 'wifi']],
        ], $options);
    }

    public function testSummaryPutsTheBiggestBacklogFirst(): void
    {
        $chips = UnmappedValuesView::summary([
            ['api_source' => 'novoton', 'feature_type' => 'stars', 'values' => '5', 'hotels' => '31'],
            ['api_source' => 'sphinx', 'feature_type' => 'hotel_facility', 'values' => '114', 'hotels' => '114'],
        ], ['sphinx' => 'Sphinx'], ['stars' => 'Star Rating', 'hotel_facility' => 'Hotel Facilities']);

        self::assertSame(['sphinx', 'novoton'], array_column($chips, 'provider'));
        self::assertSame(['Sphinx', 'Novoton'], array_column($chips, 'label'));
        self::assertSame(114, $chips[0]['values']);
    }

    public function testFiltersAreSanitisedAndTheReturnUrlKeepsThem(): void
    {
        $filters = UnmappedValuesView::filters(['api_source' => "Novoton'--", 'feature_type' => 'stars', 'q' => ' pool ', 'page' => '3']);

        self::assertSame(['api_source' => 'novoton', 'feature_type' => 'stars', 'q' => 'pool', 'page' => 3], $filters);
        self::assertSame('travel_feature_mappings.unmapped&api_source=novoton&feature_type=stars&q=pool&page=3', UnmappedValuesView::returnUrl($filters));
        self::assertSame('travel_feature_mappings.unmapped', UnmappedValuesView::returnUrl(UnmappedValuesView::filters([])));
    }

    public function testBuildPurgesResolvedValuesAndOffersLinkTargets(): void
    {
        $repo = $this->createMock(FeatureMapRepositoryInterface::class);
        $repo->expects(self::once())->method('purgeResolvedUnmapped')->with(FeatureMapper::CODE_RESOLVED_TYPES);
        $repo->method('getPaginatedUnmapped')->willReturn(['items' => [
            ['unmapped_id' => 1, 'api_source' => 'sphinx', 'feature_type' => 'hotel_facility', 'api_value' => '119'],
        ], 'total' => 1]);
        $repo->expects(self::once())->method('findMappingsOfTypes')->with(FeatureMapper::FACILITY_TYPES)->willReturn([]);
        $repo->method('getUnmappedSummary')->willReturn([]);

        $vars = UnmappedValuesView::build($repo, UnmappedValuesView::filters(['feature_type' => 'facility']), 25, ['sphinx' => 'Sphinx']);

        self::assertSame(FeatureMapper::FACILITY_TYPES, $vars['unmapped_values'][0]['link_types']);
        self::assertSame(1, $vars['search']['total_items']);
        self::assertSame(['sphinx' => 'Sphinx'], $vars['unmapped_providers']);
    }
}
