<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Services\HotelRowMapper;

/**
 * Characterization coverage for HotelRowMapper — the raw-API-hotel to
 * sphinx_hotels-row transformation extracted from HotelSyncService. normalize()
 * is exercised against a real SphinxNormalizer; enrichFromHierarchy() against a
 * stubbed DestinationRepository. The tests pin the field mapping, the guards,
 * and the enrichment/fallback rules so the extraction preserves behaviour.
 */
#[CoversClass(HotelRowMapper::class)]
class HotelRowMapperTest extends TestCase
{
    private HotelRowMapper $mapper;
    private DestinationRepository $destRepo;
    private SphinxNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SphinxNormalizer();
        $this->destRepo = $this->createMock(DestinationRepository::class);
        $this->mapper = new HotelRowMapper($this->normalizer, $this->destRepo);
    }

    // ── normalize ────────────────────────────────────────────────────────────

    public function testTheGiataIdIsKeptWhenSphinxHasOne(): void
    {
        $mapped = $this->mapper->normalize(['id' => 'h1', 'name' => 'Hotel', 'external_ids' => ['giata_id' => 123456]]);
        self::assertNotNull($mapped);
        self::assertSame(123456, $mapped['giata_id']);

        foreach ([['external_ids' => ['giata_id' => null]], []] as $extra) {
            $mapped = $this->mapper->normalize(['id' => 'h1', 'name' => 'Hotel'] + $extra);
            self::assertNotNull($mapped);
            self::assertArrayHasKey('giata_id', $mapped);
            self::assertNull($mapped['giata_id'], 'not mapped in GIATA, or no external ids');
        }
    }

    public function testNormalizeMapsAllFields(): void
    {
        $row = $this->mapper->normalize([
            'id' => '3371',
            'name' => 'Test Hotel',
            'type' => 'villa',
            'classification' => 4,
            'destination_id' => 12,
            'destination_name' => 'Crete',
            'region_id' => 5,
            'region_name' => 'Heraklion',
            'country_code' => 'gr',
            'country_name' => 'Greece',
            'latitude' => 35.1,
            'longitude' => 25.2,
            'description' => 'Nice &amp; cosy',
            'images' => [['url' => 'http://img/1.jpg'], ['url' => 'http://img/2.jpg']],
            'facilities' => ['wifi', 'pool'],
            'address' => ['street' => ' Main St ', 'phone' => ' 123 ', 'email' => ' a@b.c ', 'website' => ' x.com '],
            'rating' => 8.6,
            'rating_count' => 240,
        ]);

        $this->assertNotNull($row);
        $this->assertSame('3371', $row['hotel_id']);
        $this->assertSame('Test Hotel', $row['name']);
        $this->assertSame(4, $row['classification']);
        $this->assertSame($this->normalizer->normalizePropertyType('villa') ?? 'hotel', $row['property_type']);
        $this->assertSame(12, $row['destination_id']);
        $this->assertSame('GR', $row['country_code']); // upper-cased
        $this->assertSame(35.1, $row['latitude']);
        $this->assertSame('Nice & cosy', $row['description']); // HTML entities decoded
        $this->assertSame('http://img/1.jpg', $row['image_url']); // first image
        $this->assertSame('[{"url":"http:\/\/img\/1.jpg"},{"url":"http:\/\/img\/2.jpg"}]', $row['images_json']);
        $this->assertSame('["wifi","pool"]', $row['facilities_json']);
        $this->assertSame('N', $row['is_adults_only']);
        $this->assertSame('Main St', $row['address']); // trimmed
        $this->assertSame('123', $row['phone']);
        $this->assertSame(8.6, $row['rating']);
        $this->assertSame(240, $row['rating_count']);
    }

    public function testNormalizeReturnsNullWithoutId(): void
    {
        $this->assertNull($this->mapper->normalize(['name' => 'No ID Hotel']));
    }

    public function testNormalizeReturnsNullWithoutName(): void
    {
        $this->assertNull($this->mapper->normalize(['id' => '5', 'name' => '']));
    }

    public function testNormalizeClampsInvalidClassification(): void
    {
        $high = $this->mapper->normalize(['id' => '1', 'name' => 'H', 'classification' => 9]);
        $neg = $this->mapper->normalize(['id' => '2', 'name' => 'H', 'classification' => -3]);
        $this->assertNotNull($high);
        $this->assertNotNull($neg);
        $this->assertSame(0, $high['classification']);
        $this->assertSame(0, $neg['classification']);
    }

    public function testNormalizeDetectsAdultsOnlyFromName(): void
    {
        foreach (['Sunshine Adults Only', 'Beach Resort (+18)', 'Cove (16+)'] as $name) {
            $row = $this->mapper->normalize(['id' => '1', 'name' => $name]);
            $this->assertNotNull($row);
            $this->assertSame('Y', $row['is_adults_only'], "expected adults-only for: {$name}");
        }
    }

    public function testNormalizeNullsMissingRating(): void
    {
        $row = $this->mapper->normalize(['id' => '1', 'name' => 'H']);
        $this->assertNotNull($row);
        $this->assertNull($row['rating']);
        $this->assertNull($row['rating_count']);
        $this->assertSame('[]', $row['images_json']);
        $this->assertSame('', $row['image_url']);
    }

    // ── enrichFromHierarchy ──────────────────────────────────────────────────

    public function testEnrichReturnsEmptyForNoHotels(): void
    {
        $this->destRepo->expects($this->never())->method('resolveHierarchies');
        $this->assertSame([], $this->mapper->enrichFromHierarchy([], 'GR'));
    }

    public function testEnrichFillsFromHierarchy(): void
    {
        $this->destRepo->method('resolveHierarchies')->with([12])->willReturn([
            12 => [
                'country_code' => 'GR', 'country' => 'Greece',
                'city' => 'Chania', 'region' => 'Crete', 'region_id' => 7,
                'own_id' => 12, 'own_type' => 'destination', 'own_name' => 'Chania',
            ],
        ]);

        $out = $this->mapper->enrichFromHierarchy([[
            'destination_id' => 12,
            'destination_name' => '',
            'region_name' => '',
            'region_id' => 0,
            'country_code' => '',
            'country_name' => '',
        ]], 'XX');

        $this->assertSame('GR', $out[0]['country_code']);
        $this->assertSame('Greece', $out[0]['country_name']);
        $this->assertSame('Chania', $out[0]['destination_name']);
        $this->assertSame('Crete', $out[0]['region_name']);
        $this->assertSame(7, $out[0]['region_id']);
    }

    public function testEnrichDoesNotOverwriteExistingNamesAndFallsBackToContext(): void
    {
        // No hierarchy entry for this destination → existing values kept,
        // and the empty country_code falls back to the sync context.
        $this->destRepo->method('resolveHierarchies')->willReturn([]);

        $out = $this->mapper->enrichFromHierarchy([[
            'destination_id' => 99,
            'destination_name' => 'Existing City',
            'region_name' => 'Existing Region',
            'region_id' => 3,
            'country_code' => '',
            'country_name' => '',
        ]], 'BG');

        $this->assertSame('Existing City', $out[0]['destination_name']);
        $this->assertSame('Existing Region', $out[0]['region_name']);
        $this->assertSame(3, $out[0]['region_id']);
        $this->assertSame('BG', $out[0]['country_code']); // fallback to context
    }

    // ── address.city / address.country (the admin grid's City/Country) ─────

    public function testNormalizeCapturesAddressCityAndCountry(): void
    {
        $row = $this->mapper->normalize([
            'id' => '1',
            'name' => 'H',
            'address' => ['street' => 'Main St', 'city' => ' Antalya ', 'country' => ' Turkey '],
        ]);

        $this->assertNotNull($row);
        // Persistent columns (trimmed) shown as the grid City/Country.
        $this->assertSame('Antalya', $row['address_city']);
        $this->assertSame('Turkey', $row['address_country']);
    }

    public function testNormalizeAddressCityCountryDefaultEmpty(): void
    {
        $row = $this->mapper->normalize(['id' => '1', 'name' => 'H']);

        $this->assertNotNull($row);
        $this->assertSame('', $row['address_city']);
        $this->assertSame('', $row['address_country']);
    }

    public function testEnrichFallsBackToAddressCityWhenTreeNamesNoCity(): void
    {
        // Destination unknown to the tree → city stays '' from the hierarchy,
        // so the persistent address_city fills the tree-derived destination_name
        // used by products/search. address_city itself is NOT stripped.
        $this->destRepo->method('resolveHierarchies')->willReturn([]);

        $out = $this->mapper->enrichFromHierarchy([[
            'destination_id' => 99,
            'destination_name' => '',
            'region_name' => '',
            'region_id' => 0,
            'country_code' => '',
            'country_name' => '',
            'address_city' => 'Side',
        ]], 'TR');

        $this->assertSame('Side', $out[0]['destination_name']);
        $this->assertSame('', $out[0]['region_name'], 'region has no payload fallback — tree only');
        $this->assertSame('Side', $out[0]['address_city'], 'address_city is a persistent column, not stripped');
    }

    /**
     * The hotel's own node is city-level, so rung 1 of the City ladder
     * answers and the API address is never consulted.
     */
    public function testEnrichPrefersTreeCityOverAddressCity(): void
    {
        $this->destRepo->method('resolveHierarchies')->with([12])->willReturn([
            12 => [
                'country_code' => 'GR', 'country' => 'Greece',
                'city' => 'Chania', 'region' => 'Crete', 'region_id' => 7,
                'own_id' => 12, 'own_type' => 'destination', 'own_name' => 'Chania',
            ],
        ]);

        $out = $this->mapper->enrichFromHierarchy([[
            'destination_id' => 12,
            'destination_name' => '',
            'region_name' => '',
            'region_id' => 0,
            'country_code' => '',
            'country_name' => '',
            'address_city' => 'Some Address City',
        ]], 'XX');

        $this->assertSame('Chania', $out[0]['destination_name'], 'tree city wins over address.city for destination_name');
        $this->assertSame('Some Address City', $out[0]['address_city'], 'address_city column is preserved');
        $this->assertSame('ancestor', $out[0]['location_source'], 'region came from the parent node — the weaker rung');
    }
}
