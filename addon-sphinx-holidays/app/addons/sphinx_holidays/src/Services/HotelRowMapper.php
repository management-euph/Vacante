<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Maps raw Sphinx API hotel records into normalized sphinx_hotels rows and
 * enriches them with country/region data from the destination hierarchy.
 *
 * Extracted from HotelSyncService so the raw-API-to-DB-row transformation is a
 * pure, independently testable collaborator. The sync service streams pages of
 * raw hotels through normalize(), then batch-enriches them via
 * enrichFromHierarchy() before upserting. Behaviour is preserved verbatim.
 *
 * enrichFromHierarchy() reads the in-memory parent lookup that the caller
 * preloads via DestinationRepository::loadParentLookup(); the mapper must
 * therefore share the same DestinationRepository instance as the sync service.
 */
class HotelRowMapper
{
    public function __construct(
        private readonly SphinxNormalizer $normalizer,
        private readonly DestinationRepository $destRepo,
    ) {
    }

    /**
     * Normalize a raw API hotel into the DB column format.
     *
     * Sphinx static API returns: {id, destination_id, name, type, classification,
     * latitude, longitude, description, address, images, facilities, external_ids}
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    public function normalize(array $raw): ?array
    {
        $id = TypeCoerce::toString($raw['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $name = html_entity_decode(TypeCoerce::toString($raw['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($name === '') {
            return null;
        }

        $propertyType = $this->normalizer->normalizePropertyType(
            $raw['type'] ?? 'hotel',
        ) ?? 'hotel';

        $classification = TypeCoerce::toInt($raw['classification'] ?? 0);
        if ($classification < 0 || $classification > 5) {
            $classification = 0;
        }

        // Detect adults-only from hotel name (API doesn't provide a dedicated field)
        // Matches: "Adults Only", "Adult Only", "+18", "+16", "(18+)", "(16+)"
        $isAdultsOnly = preg_match('/\badults?\s*only\b|\(\s*\+\s*1[68]\s*\)|\(\s*1[68]\s*\+\s*\)/i', $name) === 1 ? 'Y' : 'N';

        $address = TypeCoerce::toStringMap($raw['address'] ?? []);
        $images = TypeCoerce::toRowList($raw['images'] ?? []);

        return [
            'hotel_id' => $id,
            'name' => $name,
            'classification' => $classification,
            'property_type' => $propertyType,
            'destination_id' => TypeCoerce::toInt($raw['destination_id'] ?? 0),
            'destination_name' => TypeCoerce::toString($raw['destination_name'] ?? ''),
            'region_id' => TypeCoerce::toInt($raw['region_id'] ?? 0),
            'region_name' => TypeCoerce::toString($raw['region_name'] ?? ''),
            'country_code' => strtoupper(TypeCoerce::toString($raw['country_code'] ?? '')),
            'country_name' => TypeCoerce::toString($raw['country_name'] ?? ''),
            'latitude' => TypeCoerce::toFloat($raw['latitude'] ?? 0),
            'longitude' => TypeCoerce::toFloat($raw['longitude'] ?? 0),
            'description' => html_entity_decode(TypeCoerce::toString($raw['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'short_description' => html_entity_decode(TypeCoerce::toString($raw['short_description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'image_url' => TypeCoerce::toString($images[0]['url'] ?? ''),
            'images_json' => $images !== [] ? json_encode($images) : '[]',
            'facilities_json' => !empty($raw['facilities']) ? json_encode($raw['facilities']) : '[]',
            'is_adults_only' => $isAdultsOnly,
            'address' => trim(TypeCoerce::toString($address['street'] ?? '')),
            // Per-hotel city/country straight from the API address — shown as
            // the City/Country columns on the admin hotels grid, and (for
            // address_city) the last-resort fallback for the tree-derived
            // destination_name in enrichFromHierarchy().
            'address_city' => trim(TypeCoerce::toString($address['city'] ?? '')),
            'address_country' => trim(TypeCoerce::toString($address['country'] ?? '')),
            'phone' => trim(TypeCoerce::toString($address['phone'] ?? '')),
            'email' => trim(TypeCoerce::toString($address['email'] ?? '')),
            'website' => trim(TypeCoerce::toString($address['website'] ?? '')),
            'rating' => isset($raw['rating']) ? TypeCoerce::toFloat($raw['rating']) : null,
            'rating_count' => isset($raw['rating_count']) ? TypeCoerce::toInt($raw['rating_count']) : null,
            // The GIATA id, null when Sphinx has none or could not map the
            // hotel: the first step towards "the same hotel at another supplier".
            'giata_id' => max(0, TypeCoerce::toInt(TypeCoerce::toStringMap($raw['external_ids'] ?? [])['giata_id'] ?? 0)) ?: null,
        ];
    }

    /**
     * Enrich a batch of normalized hotels with country/region data from the
     * destination hierarchy.
     *
     * Uses the preloaded parentLookup (in-memory, no DB queries) to resolve
     * country_code and country_name from each hotel's destination_id, then
     * hands City/Region to HotelLocationResolver, which applies the explicit
     * type-gated ladder (see that class). Falls back to the sync context
     * $countryCode when destinations haven't been synced yet.
     *
     * $geoByHotelId carries the STORED reverse-geocoded values (the ladder's
     * last rung). They live in their own columns precisely because the upsert
     * overwrites destination_name/region_name unconditionally — without them
     * a re-sync would wipe a geocoded city every time.
     *
     * @param list<array<string, mixed>> $hotels Normalized hotel rows
     * @param string $countryCode Sync context country code (fallback)
     * @param array<string, array<string, mixed>> $geoByHotelId hotel_id => {geo_city, geo_region}
     * @return list<array<string, mixed>> Hotels with enriched country/region data
     */
    public function enrichFromHierarchy(array $hotels, string $countryCode, array $geoByHotelId = []): array
    {
        if (empty($hotels)) {
            return $hotels;
        }

        // Collect unique destination IDs for batch resolution
        $destIds = [];
        foreach ($hotels as $hotelRow) {
            $di = TypeCoerce::toInt($hotelRow['destination_id'] ?? 0);
            if ($di > 0) {
                $destIds[$di] = $di;
            }
        }
        $hierarchyMap = $destIds !== [] ? $this->destRepo->resolveHierarchies(array_values($destIds)) : [];

        foreach ($hotels as &$hotel) {
            $destId = TypeCoerce::toInt($hotel['destination_id'] ?? 0);
            $hierarchy = $hierarchyMap[$destId] ?? [];

            // Country comes straight off the tree (no ambiguity: only a
            // `country` node can name one).
            if (!empty($hierarchy['country_code'])) {
                $hotel['country_code'] = $hierarchy['country_code'];
            }
            if (!empty($hierarchy['country'])) {
                $hotel['country_name'] = $hierarchy['country'];
            }
            // Fallback: sync context country code (when destinations aren't synced yet)
            if ($hotel['country_code'] === '') {
                $hotel['country_code'] = $countryCode;
            }

            // City/Region: one explicit ladder, shared with the backfill cron.
            $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $stored = $geoByHotelId[$hotelId] ?? [];
            $resolved = HotelLocationResolver::resolve([
                'address_city' => $hotel['address_city'] ?? '',
                'geo_city' => $stored['geo_city'] ?? '',
                'geo_region' => $stored['geo_region'] ?? '',
            ], $hierarchy);

            // Only overwrite with a resolved value; an unresolved ladder must
            // not blank a name the API itself supplied.
            if ($resolved['city'] !== '') {
                $hotel['destination_name'] = $resolved['city'];
            }
            if ($resolved['region'] !== '') {
                $hotel['region_name'] = $resolved['region'];
            }
            if ($resolved['region_id'] > 0) {
                $hotel['region_id'] = $resolved['region_id'];
            }
            $hotel['location_source'] = HotelLocationResolver::rowSource($resolved);
        }
        unset($hotel);

        return $hotels;
    }
}
