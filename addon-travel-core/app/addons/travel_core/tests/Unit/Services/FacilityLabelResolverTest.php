<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\FacilityLabelResolver;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * Localized amenity labels for the booking sidebar.
 *
 * Field failure: a Romanian storefront listed "Air conditioning/Heating" and
 * "Free umbrella and sunbed" on novoton hotels. Sphinx had it right all along —
 * it resolves each provider facility id through ?:travel_api_alias into
 * ?:travel_feature_map, whose seed carries hand-written Romanian for all 129
 * canonical facility codes. Novoton read its own novoton_facilities
 * .facility_name_ro, which its sync fills with a verbatim copy of the ENGLISH
 * name. Both providers come through here now.
 */
final class FacilityLabelResolverTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
        // FeatureMapper memoises resolved aliases for the request; each test
        // configures its own mapping table.
        FeatureMapper::clearCache();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        FeatureMapper::clearCache();
    }

    /** Pretend ?:travel_api_alias maps id 23 to a mapping row with both names. */
    private static function mapOneFacility(string $en, string $ro): void
    {
        DbStub::$getRow = static function (string $sql, ...$params) use ($en, $ro): array {
            foreach ($params as $param) {
                if ((string) $param === '23') {
                    return ['map_id' => 7, 'display_name_en' => $en, 'display_name_ro' => $ro];
                }
            }

            return [];
        };
    }

    public function testRomanianStorefrontGetsTheRomanianColumn(): void
    {
        self::mapOneFacility('Air conditioning/Heating', 'Aer condiționat/Încălzire');

        self::assertSame(
            ['Aer condiționat/Încălzire'],
            FacilityLabelResolver::labels('novoton', [['id' => 23, 'name' => 'Air conditioning/Heating']], 'ro'),
        );
    }

    public function testEnglishStorefrontGetsTheEnglishColumn(): void
    {
        self::mapOneFacility('Air conditioning/Heating', 'Aer condiționat/Încălzire');

        self::assertSame(
            ['Air conditioning/Heating'],
            FacilityLabelResolver::labels('novoton', [['id' => 23, 'name' => 'raw provider text']], 'en'),
        );
    }

    /** A mapping row with a blank RO still beats a blank chip. */
    public function testBlankTranslationFallsBackToEnglish(): void
    {
        self::mapOneFacility('Sauna', '');

        self::assertSame(
            ['Sauna'],
            FacilityLabelResolver::labels('novoton', [['id' => 23, 'name' => 'SAUNA']], 'ro'),
        );
    }

    /**
     * Unmapped facilities keep the provider's own label. Dropping them — which
     * is what novoton's empty RO column used to do — is the worst outcome.
     */
    public function testUnmappedFacilitiesKeepTheProviderLabel(): void
    {
        self::assertSame(
            ['Own aquapark'],
            FacilityLabelResolver::labels('novoton', [['id' => 999, 'name' => 'Own aquapark']], 'ro'),
        );
    }

    public function testDuplicatesCollapseAndTheListIsCapped(): void
    {
        $facilities = [
            ['id' => 1, 'name' => 'Pool'],
            ['id' => 2, 'name' => 'Pool'],
            ['id' => 3, 'name' => 'Spa'],
            ['id' => 4, 'name' => 'Bar'],
            ['id' => 5, 'name' => ''],
        ];

        self::assertSame(['Pool', 'Spa'], FacilityLabelResolver::labels('sphinx', $facilities, 'en', 2));
        // A facility without a name is kept, not dropped (user ruling).
        self::assertSame(['Pool', 'Spa', 'Bar', 'Facility #5'], FacilityLabelResolver::labels('sphinx', $facilities, 'en'));
    }

    /** Regional spellings pick the Romanian column too. */
    public function testColumnChoiceIgnoresRegionSuffixes(): void
    {
        self::assertSame('display_name_ro', FacilityLabelResolver::column('ro-RO'));
        self::assertSame('display_name_ro', FacilityLabelResolver::column('RO'));
        self::assertSame('display_name_en', FacilityLabelResolver::column('en-US'));
        self::assertSame('display_name_en', FacilityLabelResolver::column('de'));
    }

    /**
     * "✓ 27" on the booking sidebar: an unmapped novoton facility got an
     * auto-created mapping row named after its code, which beat the real
     * provider name. A code-only label now yields to the provider's name,
     * and a facility nobody named keeps its chip as "Facility #27".
     */
    public function testCodeOnlyMappingNameYieldsToTheProviderName(): void
    {
        DbStub::$getRow = static function (string $sql, ...$params): array {
            foreach ($params as $param) {
                if ((string) $param === '27') {
                    return ['map_id' => 9, 'display_name_en' => '27', 'display_name_ro' => '27'];
                }
            }

            return [];
        };

        self::assertSame(
            ['Outdoor pool'],
            FacilityLabelResolver::labels('novoton', [['id' => 27, 'name' => 'Outdoor pool']], 'ro'),
        );
        // No real name anywhere: the chip stays (facilities are never
        // dropped), named after its code instead of a bare number.
        self::assertSame(['Facility #27'], FacilityLabelResolver::labels('novoton', [['id' => 27, 'name' => '']], 'en'));
        self::assertSame(
            ['Facilitate #27'],
            FacilityLabelResolver::labels('novoton', [['id' => 27, 'name' => '']], 'ro', 6, 'Facilitate #[code]'),
        );
    }
}
