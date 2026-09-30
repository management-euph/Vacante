<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Contracts\HotelRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_hotels table operations.
 *
 * Provides type-safe read/write access with batch upsert support.
 * Implements the provider-neutral travel_core contract so cross-provider
 * tooling can depend on the abstraction; everything beyond the contract
 * (sync, boards, skip/gate, SEO batches) is sphinx-specific surface.
 */
class HotelRepository implements HotelRepositoryInterface
{
    use RowNarrowingTrait;
    use HotelListingColumnsTrait;

    private const string STATUS_ACTIVE = 'active';
    private const string STATUS_INACTIVE = 'inactive';
    private const string STATUS_ERROR = 'error';

    private const array VALID_STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ERROR];

    /** Read-only aggregate / reporting reads, delegated from this repository. */
    private readonly HotelStatsRepository $stats;

    /** Name/filter search reads, delegated from this repository. */
    private readonly HotelSearchRepository $searchRepo;

    /** Product-pipeline reads (unlinked / missing images / boards / SEO). */
    private readonly HotelLinkingRepository $linkingRepo;

    public function __construct(
        ?HotelStatsRepository $stats = null,
        ?HotelSearchRepository $searchRepo = null,
        ?HotelLinkingRepository $linkingRepo = null,
    ) {
        $this->stats = $stats ?? new HotelStatsRepository();
        $this->searchRepo = $searchRepo ?? new HotelSearchRepository();
        $this->linkingRepo = $linkingRepo ?? new HotelLinkingRepository();
    }

    /**
     * Rows per multi-row upsert statement. Matches the sync services' own batch
     * size, and keeps each statement's payload safely under max_allowed_packet
     * even with large description/JSON columns.
     */
    private const UPSERT_CHUNK_SIZE = 100;

    /**
     * Placeholder tuple for one hotel row of the multi-row upsert (28 values).
     *
     * latitude/longitude/rating are bound as ?s with pre-formatted decimal
     * strings (TypeCoerce::toDecimalString), NOT ?d — Tygh's ?d placeholder
     * reformats decimals to ~2 dp and truncated DECIMAL(10,7) coordinates
     * (36.887069 was stored as 36.89). Do NOT revert to ?d for decimals.
     */
    private const UPSERT_ROW_PLACEHOLDERS = '(?s, ?s, ?i, ?s, ?i, ?s, ?i, ?s, ?s, ?s, ?s, ?s, '
        . "?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?i, 'active', ?s)";

    /**
     * Upsert a batch of hotels (multi-row INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * One statement per UPSERT_CHUNK_SIZE rows instead of one per hotel — at
     * full-sync scale (100k+ hotels) the per-row form was 100k round-trips.
     *
     * Uses the VALUES(col) form (not the MySQL-8-only "AS alias" syntax) so the
     * upsert runs on both MySQL and MariaDB; MySQL 8 flags VALUES() in ON
     * DUPLICATE KEY UPDATE as deprecated but keeps it working.
     *
     * @param list<array<string, mixed>> $hotels Array of hotel rows
     * @return int Number of rows submitted (invalid rows without hotel_id are skipped)
     */
    public function upsertBatch(array $hotels): int
    {
        if (empty($hotels)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $submitted = 0;

        foreach (array_chunk($hotels, self::UPSERT_CHUNK_SIZE) as $chunk) {
            $tuples = [];
            $params = [];

            foreach ($chunk as $hotel) {
                $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                if ($hotelId === '') {
                    continue;
                }

                $tuples[] = self::UPSERT_ROW_PLACEHOLDERS;
                array_push(
                    $params,
                    $hotelId,
                    TypeCoerce::toString($hotel['name'] ?? ''),
                    TypeCoerce::toInt($hotel['classification'] ?? 0),
                    TypeCoerce::toString($hotel['property_type'] ?? 'hotel'),
                    TypeCoerce::toInt($hotel['destination_id'] ?? 0),
                    TypeCoerce::toString($hotel['destination_name'] ?? ''),
                    TypeCoerce::toInt($hotel['region_id'] ?? 0),
                    TypeCoerce::toString($hotel['region_name'] ?? ''),
                    TypeCoerce::toString($hotel['country_code'] ?? ''),
                    TypeCoerce::toString($hotel['country_name'] ?? ''),
                    TypeCoerce::toDecimalString($hotel['latitude'] ?? 0, 8),
                    TypeCoerce::toDecimalString($hotel['longitude'] ?? 0, 8),
                    TypeCoerce::toString($hotel['address'] ?? ''),
                    TypeCoerce::toString($hotel['phone'] ?? ''),
                    TypeCoerce::toString($hotel['email'] ?? ''),
                    TypeCoerce::toString($hotel['website'] ?? ''),
                    TypeCoerce::toString($hotel['address_city'] ?? ''),
                    TypeCoerce::toString($hotel['address_country'] ?? ''),
                    TypeCoerce::toString($hotel['location_source'] ?? ''),
                    TypeCoerce::toString($hotel['description'] ?? ''),
                    TypeCoerce::toString($hotel['short_description'] ?? ''),
                    TypeCoerce::toString($hotel['image_url'] ?? ''),
                    TypeCoerce::toString($hotel['images_json'] ?? '[]'),
                    TypeCoerce::toString($hotel['facilities_json'] ?? '[]'),
                    TypeCoerce::toString($hotel['is_adults_only'] ?? 'N'),
                    TypeCoerce::toDecimalString($hotel['rating'] ?? 0, 1),
                    TypeCoerce::toInt($hotel['rating_count'] ?? 0),
                    $now,
                );
            }

            if ($tuples === []) {
                continue;
            }

            db_query(
                'INSERT INTO ?:sphinx_hotels
                    (hotel_id, name, classification, property_type,
                     destination_id, destination_name, region_id, region_name,
                     country_code, country_name, latitude, longitude,
                     address, phone, email, website,
                     address_city, address_country, location_source,
                     description, short_description, image_url,
                     images_json, facilities_json, is_adults_only,
                     rating, rating_count,
                     sync_status, last_synced_at)
                 VALUES ' . implode(', ', $tuples) . "
                 ON DUPLICATE KEY UPDATE
                    /* Change-detection FIRST: ON DUPLICATE KEY UPDATE assignments
                       run left to right, so these IF()s must read the OLD column
                       values before the plain assignments below overwrite them
                       (otherwise old == new always and detection never fires). */
                    product_skip_reason = IF(
                        ?:sphinx_hotels.destination_name != VALUES(destination_name)
                        OR ?:sphinx_hotels.country_name != VALUES(country_name)
                        OR ?:sphinx_hotels.country_code != VALUES(country_code),
                        NULL, ?:sphinx_hotels.product_skip_reason
                    ),
                    product_needs_update = IF(
                        ?:sphinx_hotels.product_id IS NOT NULL AND ?:sphinx_hotels.product_id > 0 AND (
                            ?:sphinx_hotels.name != VALUES(name)
                            OR ?:sphinx_hotels.description != VALUES(description)
                            OR ?:sphinx_hotels.short_description != VALUES(short_description)
                            OR ?:sphinx_hotels.classification != VALUES(classification)
                            OR ?:sphinx_hotels.image_url != VALUES(image_url)
                        ),
                        'Y', ?:sphinx_hotels.product_needs_update
                    ),
                    name = VALUES(name),
                    classification = VALUES(classification),
                    property_type = VALUES(property_type),
                    destination_id = VALUES(destination_id),
                    destination_name = VALUES(destination_name),
                    region_id = VALUES(region_id),
                    region_name = VALUES(region_name),
                    country_code = VALUES(country_code),
                    country_name = VALUES(country_name),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    address = VALUES(address),
                    phone = VALUES(phone),
                    email = VALUES(email),
                    website = VALUES(website),
                    address_city = VALUES(address_city),
                    address_country = VALUES(address_country),
                    location_source = VALUES(location_source),
                    description = VALUES(description),
                    short_description = VALUES(short_description),
                    image_url = VALUES(image_url),
                    images_json = VALUES(images_json),
                    facilities_json = VALUES(facilities_json),
                    is_adults_only = VALUES(is_adults_only),
                    rating = VALUES(rating),
                    rating_count = VALUES(rating_count),
                    sync_status = 'active',
                    last_synced_at = VALUES(last_synced_at)",
                ...$params,
            );

            $submitted += count($tuples);
        }

        return $submitted;
    }

    /**
     * Get hotels with optional filters.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getFiltered(
        string $countryCode = '',
        int $destinationId = 0,
        int $regionId = 0,
        string $syncStatus = '',
        string $query = '',
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->searchRepo->getFiltered($countryCode, $destinationId, $regionId, $syncStatus, $query, $page, $perPage);
    }

    /**
     * Get a single hotel by ID.
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function findById(string $hotelId): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:sphinx_hotels WHERE hotel_id = ?s',
            $hotelId,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Whether a hotel row exists for the given hotel id.
     */
    #[\Override]
    public function exists(string $hotelId): bool
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT 1 FROM ?:sphinx_hotels WHERE hotel_id = ?s',
            $hotelId,
        )) > 0;
    }

    /**
     * Get hotels by destination ID (excludes large JSON/TEXT columns).
     * @return list<array<string, mixed>>
     */
    public function getByDestination(int $destinationId): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . $this->listingColumns() . ' FROM ?:sphinx_hotels WHERE destination_id = ?i ORDER BY name ASC',
            $destinationId,
        ));
    }

    /**
     * Get hotel counts grouped by country code.
     *
     * @return array<string, int>
     */
    public function getCountsByCountry(): array
    {
        return $this->stats->getCountsByCountry();
    }

    /**
     * Get distinct country codes from synced hotels.
     *
     * @return list<string>
     */
    public function getDistinctCountries(): array
    {
        return $this->stats->getDistinctCountries();
    }

    /**
     * Distinct list of countries that have at least one hotel.
     *
     * Contract-named alias of getDistinctCountries().
     *
     * @return list<string>
     */
    #[\Override]
    public function getCountries(): array
    {
        return $this->getDistinctCountries();
    }

    /**
     * Get total number of active hotels.
     */
    public function getTotal(): int
    {
        return $this->stats->getTotal();
    }

    /**
     * Stored reverse-geocoded locations for a batch of hotels — the last rung
     * of HotelLocationResolver's ladder.
     *
     * They live in their own columns (never written by upsertBatch) precisely
     * so a re-sync cannot wipe them; the sync reads them back through this
     * method to keep the ladder complete while enriching.
     *
     * @param string[] $hotelIds
     * @return array<string, array<string, mixed>> hotel_id => {geo_city, geo_region}
     */
    public function findGeoLocations(array $hotelIds): array
    {
        if ($hotelIds === []) {
            return [];
        }

        $rows = self::asRowList(db_get_array(
            'SELECT hotel_id, geo_city, geo_region FROM ?:sphinx_hotels
             WHERE hotel_id IN (?a) AND (geo_city IS NOT NULL OR geo_region IS NOT NULL)',
            $hotelIds,
        ));

        $byId = [];
        foreach ($rows as $row) {
            $id = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        return $byId;
    }

    /**
     * Country of each hotel, for the location line of search offers (the
     * offer itself only names the resort/city). Falls back to the country
     * of the hotel's API address when country_name is empty.
     *
     * @param string[] $hotelIds
     * @return array<string, string> hotel_id => country name
     */
    public function findCountryNames(array $hotelIds): array
    {
        if ($hotelIds === []) {
            return [];
        }

        $rows = self::asRowList(db_get_array(
            "SELECT hotel_id, COALESCE(NULLIF(TRIM(country_name), ''), NULLIF(TRIM(address_country), '')) AS country
             FROM ?:sphinx_hotels WHERE hotel_id IN (?a)",
            $hotelIds,
        ));

        $byId = [];
        foreach ($rows as $row) {
            $id = TypeCoerce::toString($row['hotel_id'] ?? '');
            $country = TypeCoerce::toString($row['country'] ?? '');
            if ($id !== '' && $country !== '') {
                $byId[$id] = $country;
            }
        }

        return $byId;
    }

    /**
     * Get last hotel sync timestamp, optionally per country.
     */
    public function getLastSyncedAt(?string $countryCode = null): ?string
    {
        return $this->stats->getLastSyncedAt($countryCode);
    }

    /**
     * Search hotels by name (excludes large JSON/TEXT columns).
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->searchRepo->search($query, $limit);
    }

    /**
     * Lightweight hotel name search for AJAX autocomplete.
     * Returns only the columns needed for the Select2 dropdown display.
     * @return list<array<string, mixed>>
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        return $this->searchRepo->searchByName($query, $limit);
    }

    /**
     * Link a hotel to a CS-Cart product.
     *
     * @return bool True when the hotel row was updated.
     */
    #[\Override]
    public function linkToProduct(string $hotelId, int $productId): bool
    {
        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_id = ?i WHERE hotel_id = ?s',
            $productId,
            $hotelId,
        )) > 0;
    }

    /**
     * Remove the hotel↔product association for the given product.
     *
     * Sets product_id to NULL, which findUnlinked()/countLinked() treat as
     * unlinked (alongside the legacy 0 value).
     *
     * @return bool True when a hotel row was unlinked.
     */
    #[\Override]
    public function unlinkProduct(int $productId): bool
    {
        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_id = NULL WHERE product_id = ?i',
            $productId,
        )) > 0;
    }

    /**
     * Get hotels that have no images stored (images_json is null/empty/[]).
     * Used by enrich_hotel_data to backfill from the detail API.
     *
     * @return list<array<string, mixed>>
     */
    public function findMissingImages(string $countryCode = '', int $limit = 100): array
    {
        return $this->linkingRepo->findMissingImages($countryCode, $limit);
    }

    /**
     * Get unlinked hotels (no product_id) with optional country filter.
     *
     * @return list<array<string, mixed>> List of hotel rows without linked products
     */
    public function findUnlinked(string $countryCode = '', int $limit = 0): array
    {
        return $this->linkingRepo->findUnlinked($countryCode, $limit);
    }

    /**
     * Count hotels that have a linked CS-Cart product.
     */
    public function countLinked(): int
    {
        return $this->stats->countLinked();
    }

    /**
     * Get distinct destination_ids for active hotels, filtered by country codes.
     *
     * @param string[] $countryCodes Country codes to filter (e.g. ['GR', 'BG'])
     * @return int[] Distinct destination IDs
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        return $this->stats->getDestinationIdsByCountry($countryCodes);
    }

    /**
     * Get hotel_id → hotel_id map for a given destination.
     *
     * @return array<string, string> hotel_id => hotel_id
     */
    public function getHotelIdsByDestination(int $destinationId): array
    {
        $raw = db_get_hash_single_array(
            "SELECT hotel_id, hotel_id FROM ?:sphinx_hotels WHERE destination_id = ?i AND sync_status = 'active'",
            ['hotel_id', 'hotel_id'],
            $destinationId,
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $out[$k] = TypeCoerce::toString($v);
            }
        }
        return $out;
    }

    /**
     * Persist per-date calendar from-prices (raw API currency, no commission).
     *
     * @param array<string, float> $datePrices check-in date => price
     */
    public function saveCalendarPrices(string $hotelId, array $datePrices): void
    {
        $encoded = json_encode($datePrices, JSON_UNESCAPED_UNICODE);
        db_query(
            'UPDATE ?:sphinx_hotels SET calendar_prices_raw = ?s WHERE hotel_id = ?s',
            is_string($encoded) ? $encoded : '{}',
            $hotelId,
        );
    }

    /**
     * Update boards_json for a batch of hotels.
     *
     * @param array<string, list<string>> $boardsByHotel hotel_id => array of canonical board codes
     * @return int Number of hotels updated
     */
    public function updateBoardsBatch(array $boardsByHotel): int
    {
        $updated = 0;
        foreach ($boardsByHotel as $hotelId => $boards) {
            $encoded = !empty($boards)
                ? json_encode(array_values(array_unique($boards)))
                : false;
            $json = is_string($encoded) ? $encoded : '[]';
            db_query(
                'UPDATE ?:sphinx_hotels SET boards_json = ?s WHERE hotel_id = ?s',
                $json,
                (string) $hotelId,
            );
            $updated++;
        }
        return $updated;
    }

    /**
     * Get hotels that have boards_json AND a linked product.
     *
     * Returns all fields needed by SphinxFeatureAssigner::assignAll().
     *
     * @param string $countryCode Optional country filter
     * @param int $limit Max rows (0 = unlimited)
     * @param int $offset Starting offset for pagination
     * @return list<array<string, mixed>> List of hotel rows
     */
    public function findWithBoardsAndProduct(string $countryCode = '', int $limit = 0, int $offset = 0): array
    {
        return $this->linkingRepo->findWithBoardsAndProduct($countryCode, $limit, $offset);
    }

    /**
     * Count hotels that have boards_json AND a linked product.
     */
    public function countWithBoardsAndProduct(string $countryCode = ''): int
    {
        return $this->stats->countWithBoardsAndProduct($countryCode);
    }

    /**
     * Mark hotels as inactive if they weren't touched since the given timestamp.
     *
     * Replaces markInactiveExcept() for scalability: uses a single indexed WHERE clause
     * instead of NOT IN (100k IDs). Works because upsertBatch() sets last_synced_at = NOW()
     * on every hotel it touches, so any hotel with last_synced_at < $syncStartedAt was not
     * in the API response and is therefore stale.
     *
     * @param string $syncStartedAt Timestamp recorded before sync started (Y-m-d H:i:s)
     * @param string $countryCode Country code to scope the update
     * @return int Number of rows marked inactive
     */
    public function markInactiveBefore(string $syncStartedAt, string $countryCode): int
    {
        if ($countryCode === '') {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:sphinx_hotels SET sync_status = 'inactive'
             WHERE country_code = ?s AND sync_status = 'active' AND last_synced_at < ?s",
            $countryCode,
            $syncStartedAt,
        ));
    }

    /**
     * Bulk update sync_status for a list of hotel IDs.
     *
     * @param string[] $hotelIds Hotel IDs to update
     * @param string $status Target status (active, inactive, error)
     * @return int Number of rows affected
     */
    public function bulkUpdateStatus(array $hotelIds, string $status): int
    {
        if (empty($hotelIds) || !in_array($status, self::VALID_STATUSES, true)) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET sync_status = ?s WHERE hotel_id IN (?a)',
            $status,
            $hotelIds,
        ));
    }

    /**
     * Delete a single hotel by ID.
     *
     * Hotel data is self-contained on the row (boards/images/facilities are
     * JSON columns), so there are no child rows to cascade.
     *
     * @return bool True when the hotel existed and was deleted.
     */
    #[\Override]
    public function delete(string $hotelId): bool
    {
        return $this->bulkDelete([$hotelId]) > 0;
    }

    /**
     * Bulk delete hotels by ID.
     *
     * @param string[] $hotelIds Hotel IDs to delete
     * @return int Number of rows deleted
     */
    public function bulkDelete(array $hotelIds): int
    {
        if (empty($hotelIds)) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'DELETE FROM ?:sphinx_hotels WHERE hotel_id IN (?a)',
            $hotelIds,
        ));
    }

    /**
     * Fetch a batch of active linked hotels for SEO bulk-apply.
     *
     * Returns hotel rows with location data needed for placeholder building.
     *
     * @param int $offset Starting offset
     * @param int $batch Batch size
     * @return list<array<string, mixed>>
     */
    public function fetchLinkedBatchForSeo(int $offset, int $batch): array
    {
        return $this->linkingRepo->fetchLinkedBatchForSeo($offset, $batch);
    }

    /**
     * Count sphinx_hotels rows whose linked product_id no longer exists in CS-Cart.
     *
     * These are hotels that believe they have a product but the product was deleted
     * from CS-Cart without clearing the sphinx_hotels link.
     */
    public function countOrphanedProducts(): int
    {
        return $this->stats->countOrphanedProducts();
    }

    /**
     * Count sphinx-shaped CS-Cart products with no hotel row linked to them —
     * the set the relink action repairs (see HotelStatsRepository).
     */
    public function countUnlinkedProducts(string $legacyPrefix): int
    {
        return $this->stats->countUnlinkedProducts($legacyPrefix);
    }

    /**
     * The unlinked sphinx-shaped products themselves (admin audit page).
     *
     * @return list<array<string, mixed>>
     */
    public function findUnlinkedProducts(string $legacyPrefix, int $limit = 500): array
    {
        return $this->stats->findUnlinkedProducts($legacyPrefix, $limit);
    }

    /**
     * Update image URL and images JSON for a hotel.
     *
     * Used when fresh image data is fetched from the API during image sync.
     *
     * @param string $hotelId The hotel ID
     * @param string $imageUrl Primary image URL
     * @param string $imagesJson JSON-encoded array of image data
     */
    public function updateImages(string $hotelId, string $imageUrl, string $imagesJson): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET image_url = ?s, images_json = ?s WHERE hotel_id = ?s',
            $imageUrl,
            $imagesJson,
            $hotelId,
        );
    }

    /**
     * Get distinct classification values present in the data.
     *
     * @return list<int>
     */
    public function getDistinctClassifications(): array
    {
        return $this->stats->getDistinctClassifications();
    }

    /**
     * Get distinct property_type values present in the data.
     *
     * @return list<string>
     */
    public function getDistinctPropertyTypes(): array
    {
        return $this->stats->getDistinctPropertyTypes();
    }
}
