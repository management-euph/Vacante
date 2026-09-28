<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\ViewModels\FeatureMappingsView;

/**
 * The Feature Mappings pages show, per provider, whether its values reach a
 * feature: FeatureMapper resolves a value only through an alias of that
 * provider, so a provider with none for "stars" gives no hotel a rating.
 */
final class FeatureMappingsViewTest extends TestCase
{
    private const array COUNTS = [
        'novoton' => ['board' => 10, 'room_type' => 20, 'property_type' => 5, 'hotel_facility' => 80],
        'sphinx' => ['board' => 9, 'room_type' => 4, 'stars' => 15, 'property_type' => 3],
    ];

    /** @return array<string, string> */
    private static function providers(): array
    {
        return FeatureMappingsView::providers(
            ['sphinx' => 'Sphinx', 'eurosite' => 'Eurosite', 'novoton' => 'Novoton'],
            self::COUNTS,
        );
    }

    public function testProvidersMergeRegisteredAndAliasSourcesByName(): void
    {
        $providers = FeatureMappingsView::providers(['sphinx' => 'Sphinx Travel'], ['novoton' => ['board' => 1], '' => []]);

        self::assertSame(['novoton' => 'Novoton', 'sphinx' => 'Sphinx Travel'], $providers);
    }

    public function testStarCoverageFlagsTheProviderWithoutStarAliases(): void
    {
        $states = array_column(FeatureMappingsView::coverage(self::providers(), self::COUNTS, 'stars'), 'state', 'provider');

        self::assertSame(['eurosite' => 'unused', 'novoton' => 'missing', 'sphinx' => 'ok'], $states);
    }

    public function testMissingListsOnlyTheProvidersThatNeverReachTheType(): void
    {
        $missing = FeatureMappingsView::missing(FeatureMappingsView::coverage(self::providers(), self::COUNTS, 'stars'));

        self::assertSame(['novoton'], array_column($missing, 'provider'));
    }

    public function testATypeOutsideTheCheckedOnesIsNeverMissing(): void
    {
        $states = array_column(FeatureMappingsView::coverage(self::providers(), self::COUNTS, 'hotel_facility'), 'state', 'provider');

        self::assertSame(['eurosite' => 'unused', 'novoton' => 'ok', 'sphinx' => 'unused'], $states);
    }

    public function testTheDerivedTravelGroupHasNoChips(): void
    {
        self::assertSame([], FeatureMappingsView::coverage(self::providers(), self::COUNTS, 'travel_group'));
    }

    public function testAliasesAreGroupedPerMappingAndProvider(): void
    {
        $grouped = FeatureMappingsView::groupAliases([
            ['map_id' => '7', 'api_source' => 'sphinx', 'api_value' => '3'],
            ['map_id' => 7, 'api_source' => 'sphinx', 'api_value' => '3 stars'],
            ['map_id' => 7, 'api_source' => 'novoton', 'api_value' => '3'],
            ['map_id' => 8, 'api_source' => '', 'api_value' => 'x'],
        ]);

        self::assertSame([7 => ['sphinx' => ['3', '3 stars'], 'novoton' => ['3']]], $grouped);
    }

    public function testProviderFilterParsesHasAndMissing(): void
    {
        self::assertSame(['provider' => 'novoton', 'negate' => false], FeatureMappingsView::parseProviderFilter('novoton'));
        self::assertSame(['provider' => 'novoton', 'negate' => true], FeatureMappingsView::parseProviderFilter('!Novoton'));
        self::assertNull(FeatureMappingsView::parseProviderFilter('!'));
        self::assertNull(FeatureMappingsView::parseProviderFilter("'; --"));
    }

    public function testEditCardsPutTheMissingProviderFirst(): void
    {
        $cards = FeatureMappingsView::aliasCards(self::providers(), self::COUNTS, 'stars', [
            ['alias_id' => 1, 'api_source' => 'sphinx', 'api_value' => '3', 'match_type' => 'exact'],
        ]);

        self::assertSame(['novoton', 'sphinx', 'eurosite'], array_column($cards, 'provider'));
        self::assertSame(['missing', 'ok', 'unused'], array_column($cards, 'state'));
        self::assertCount(1, $cards[1]['aliases']);
    }

    public function testAProviderWithOtherCodesOfTheTypeIsMissingOnThisOne(): void
    {
        // Novoton maps hotel facilities, just not this one.
        $cards = FeatureMappingsView::aliasCards(self::providers(), self::COUNTS, 'hotel_facility', []);

        self::assertSame('missing', array_column($cards, 'state', 'provider')['novoton']);
    }

    public function testAnAliasOfAnUnregisteredSourceGetsItsOwnCard(): void
    {
        $cards = FeatureMappingsView::aliasCards([], [], 'board', [
            ['alias_id' => 1, 'api_source' => 'legacy', 'api_value' => 'HB', 'match_type' => 'exact'],
        ]);

        self::assertSame([['provider' => 'legacy', 'label' => 'Legacy']], array_map(
            static fn (array $c): array => ['provider' => $c['provider'], 'label' => $c['label']],
            $cards,
        ));
    }

    public function testCreatedByKeys(): void
    {
        self::assertSame('travel_core.fm_created_by_seed', FeatureMappingsView::createdByKey('seed'));
        self::assertSame('travel_core.fm_created_by_auto', FeatureMappingsView::createdByKey('auto'));
        self::assertSame('travel_core.fm_created_by_manual', FeatureMappingsView::createdByKey('manual'));
        self::assertSame('travel_core.fm_created_by_seed', FeatureMappingsView::createdByKey(''));
    }
}
