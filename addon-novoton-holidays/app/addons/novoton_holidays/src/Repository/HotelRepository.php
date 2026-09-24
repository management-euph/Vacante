<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Hotel Repository
 *
 * Centralised database access for hotel data. Core CRUD + lookup +
 * product linking + bulk accessor operations live here directly;
 * everything else is delegated to a focused sub-repository:
 *
 *   - Listing / search / sync-selection  →  HotelSearchRepository
 *   - Stats / geography / aggregates     →  HotelReportingRepository
 *   - Cached / computed metadata columns →  HotelCacheRepository
 *   - Package table operations           →  HotelPackageRepository
 *
 * The class still implements the full HotelRepositoryInterface so existing
 * callers keep working unchanged. New callers should type-hint the narrow
 * sub-repository interface they actually need (see PR #3 of the audit).
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Dto\Hotel\Hotel;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

class HotelRepository implements HotelRepositoryInterface
{
    use RowNarrowingTrait;

    private readonly HotelSearchRepositoryInterface $search;
    private readonly HotelReportingRepositoryInterface $reporting;
    private readonly HotelCacheRepositoryInterface $cache;
    private readonly HotelPackageRepositoryInterface $packages;

    /**
     * Collaborators are injected for testability but default to concrete
     * implementations so existing `new HotelRepository()` callsites keep
     * working without any container wiring.
     */
    public function __construct(
        ?HotelSearchRepositoryInterface $search = null,
        ?HotelReportingRepositoryInterface $reporting = null,
        ?HotelCacheRepositoryInterface $cache = null,
        ?HotelPackageRepositoryInterface $packages = null,
    ) {
        $this->search = $search ?? new HotelSearchRepository();
        $this->reporting = $reporting ?? new HotelReportingRepository();
        $this->cache = $cache ?? new HotelCacheRepository();
        $this->packages = $packages ?? new HotelPackageRepository();
    }

    // ════════════════════════════════════════════════════════════════════
    // CRUD + single-row lookups (own implementation — stay local)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Core columns for hotel listing (excludes large hotel_data JSON).
     */
    private const string LISTING_COLUMNS = 'hotel_id, product_id, hotel_name, city, region, country,
        street_address, hotel_type, star_rating, property_type, is_adults_only, latitude, longitude,
        has_room_price, packages_count, hotelinfo_synced_at, hotel_list_synced_at,
        last_price_check, created_at, updated_at';

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function findById(string $hotelId): ?array
    {
        $hotel = self::asRow(db_get_row('SELECT * FROM ?:novoton_hotels WHERE hotel_id = ?s', $hotelId));
        return $hotel === [] ? null : $hotel;
    }

    #[\Override]
    public function findByIdAsDto(string $hotel_id): ?Hotel
    {
        $row = $this->findById($hotel_id);
        return $row === null ? null : Hotel::fromDbRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function findBasicById(string $hotel_id): ?array
    {
        $hotel = self::asRow(db_get_row(
            'SELECT ' . self::LISTING_COLUMNS . ' FROM ?:novoton_hotels WHERE hotel_id = ?s',
            $hotel_id,
        ));
        return $hotel === [] ? null : $hotel;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function findByProductId(int $product_id): ?array
    {
        $hotel = self::asRow(db_get_row('SELECT * FROM ?:novoton_hotels WHERE product_id = ?i', $product_id));
        return $hotel === [] ? null : $hotel;
    }

    #[\Override]
    public function getHotelIdByProduct(int $product_id): ?string
    {
        $hotel_id = TypeCoerce::toString(db_get_field('SELECT hotel_id FROM ?:novoton_hotels WHERE product_id = ?i', $product_id));
        return $hotel_id === '' ? null : $hotel_id;
    }

    #[\Override]
    public function exists(string $hotelId): bool
    {
        return TypeCoerce::toInt(db_get_field('SELECT 1 FROM ?:novoton_hotels WHERE hotel_id = ?s', $hotelId)) > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function save(string $hotel_id, array $data): bool
    {
        $data['hotel_id'] = $hotel_id;
        return $this->upsert($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function insert(array $data): bool
    {
        $data = self::filterNullValues($data);
        return (bool) db_query('INSERT INTO ?:novoton_hotels ?e', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function update(string $hotel_id, array $data): bool
    {
        $data = self::filterNullValues($data);
        return (bool) db_query('UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s', $data, $hotel_id);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function upsert(array $data): bool
    {
        $data = self::filterNullValues($data);
        return (bool) db_query('INSERT INTO ?:novoton_hotels ?e ON DUPLICATE KEY UPDATE ?u', $data, $data);
    }

    /**
     * Delete a hotel and all its related rows (facilities + packages) atomically.
     *
     * The three DELETE statements are wrapped in a transaction so a mid-sequence
     * failure cannot leave orphaned facility/package rows tied to a deleted hotel.
     */
    #[\Override]
    public function delete(string $hotelId): bool
    {
        db_query('START TRANSACTION');
        try {
            db_query('DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s', $hotelId);
            db_query('DELETE FROM ?:novoton_hotel_packages WHERE hotel_id = ?s', $hotelId);
            $deleted = (bool) db_query('DELETE FROM ?:novoton_hotels WHERE hotel_id = ?s', $hotelId);
            db_query('COMMIT');
            return $deleted;
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    #[\Override]
    public function linkToProduct(string $hotelId, int $productId): bool
    {
        return $this->update($hotelId, ['product_id' => $productId]);
    }

    #[\Override]
    public function unlinkProduct(int $productId): bool
    {
        return (bool) db_query('UPDATE ?:novoton_hotels SET product_id = NULL WHERE product_id = ?i', $productId);
    }

    /**
     * Get location data (city, region, country) for multiple hotels in one query.
     *
     * @param string[] $hotel_ids
     * @return array<string, array{hotel_id: string, city: string, region: string, country: string}>
     *                                                                                               Keyed by hotel_id
     */
    #[\Override]
    public function getLocationsByIds(array $hotel_ids): array
    {
        if (empty($hotel_ids)) {
            return [];
        }
        $rows = self::asRowList(db_get_array(
            'SELECT hotel_id, city, region, country FROM ?:novoton_hotels WHERE hotel_id IN (?a)',
            $hotel_ids,
        ));
        $result = [];
        foreach ($rows as $row) {
            $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($hotelId === '') {
                continue;
            }
            $result[$hotelId] = [
                'hotel_id' => $hotelId,
                'city' => TypeCoerce::toString($row['city'] ?? ''),
                'region' => TypeCoerce::toString($row['region'] ?? ''),
                'country' => TypeCoerce::toString($row['country'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getAllIds(): array
    {
        return self::asStringList(db_get_fields('SELECT hotel_id FROM ?:novoton_hotels'));
    }

    // ════════════════════════════════════════════════════════════════════
    // HotelSearchRepository delegation (18 methods)
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        return $this->search->findAll($filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        return $this->search->findAllForListing($filters, $limit, $offset);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findByCountry(string $country): array
    {
        return $this->search->findByCountry($country);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findByCountryForListing(string $country): array
    {
        return $this->search->findByCountryForListing($country);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function findByCountryIndexed(string $country): array
    {
        return $this->search->findByCountryIndexed($country);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findByCountryWithLimit(string $country, int $limit = 0): array
    {
        return $this->search->findByCountryWithLimit($country, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithoutPackages(int $limit = 0): array
    {
        return $this->search->findWithoutPackages($limit);
    }

    /**
     * @param list<string> $excludeResorts
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0): array
    {
        return $this->search->findUnlinkedWithPrices($country, $excludeResorts, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findUnlinkedForAdmin(string $country, string $filter = 'prices', int $limit = 500): array
    {
        return $this->search->findUnlinkedForAdmin($country, $filter, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findNeedingPriceCheck(int $daysStale = 7, int $limit = 100): array
    {
        return $this->search->findNeedingPriceCheck($daysStale, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findNeedingPriceUpdate(int $staleHours = 24, int $limit = 100): array
    {
        return $this->search->findNeedingPriceUpdate($staleHours, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithProductsSortedByStaleness(int $limit = 50): array
    {
        return $this->search->findWithProductsSortedByStaleness($limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithPricesForExport(string $country): array
    {
        return $this->search->findWithPricesForExport($country);
    }

    /**
     * @param list<string> $selectedResorts
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findForImport(string $country, string $importMode = 'new_only', array $selectedResorts = [], int $limit = 0): array
    {
        return $this->search->findForImport($country, $importMode, $selectedResorts, $limit);
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function findIdsWithPriceinfoData(): array
    {
        return $this->search->findIdsWithPriceinfoData();
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findLinkedForSeo(int $offset, int $batch): array
    {
        return $this->search->findLinkedForSeo($offset, $batch);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithPriceinfoData(int $limit = 200): array
    {
        return $this->search->findWithPriceinfoData($limit);
    }

    /**
     * @param array<string, mixed> $filters
     */
    #[\Override]
    public function count(array $filters = []): int
    {
        return $this->search->count($filters);
    }

    // ════════════════════════════════════════════════════════════════════
    // HotelReportingRepository delegation (8 methods)
    // ════════════════════════════════════════════════════════════════════

    /**
     * @return list<string>
     */
    #[\Override]
    public function getCountries(): array
    {
        return array_values($this->reporting->getCountries());
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getResorts(string $country = ''): array
    {
        return array_values($this->reporting->getResorts($country));
    }

    /**
     * @return list<array{country: string, city: string}>
     */
    #[\Override]
    public function getCountryCityPairs(): array
    {
        return array_values($this->reporting->getCountryCityPairs());
    }

    /**
     * @return list<array{country: string, city: string, hotels: int, products: int}>
     */
    #[\Override]
    public function getResortCounts(): array
    {
        return $this->reporting->getResortCounts();
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getCountriesWithPriceCounts(): array
    {
        return $this->reporting->getCountriesWithPriceCounts();
    }

    /**
     * @return array<string, int>
     */
    #[\Override]
    public function countWithoutPackagesByCountry(): array
    {
        return $this->reporting->countWithoutPackagesByCountry();
    }

    #[\Override]
    public function countWithPackagesByCountry(string $country): int
    {
        return $this->reporting->countWithPackagesByCountry($country);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getResortStatsByCountry(string $country): array
    {
        return $this->reporting->getResortStatsByCountry($country);
    }

    #[\Override]
    public function countWithCalendarPrices(): int
    {
        return $this->reporting->countWithCalendarPrices();
    }

    // ════════════════════════════════════════════════════════════════════
    // HotelCacheRepository delegation (4 methods)
    // ════════════════════════════════════════════════════════════════════

    #[\Override]
    public function getCalendarPricesRaw(string $hotel_id): ?string
    {
        return $this->cache->getCalendarPricesRaw($hotel_id);
    }

    #[\Override]
    public function setCalendarPricesRaw(string $hotel_id, ?string $json): void
    {
        $this->cache->setCalendarPricesRaw($hotel_id, $json);
    }

    #[\Override]
    public function getHotelData(string $hotel_id): ?string
    {
        return $this->cache->getHotelData($hotel_id);
    }

    #[\Override]
    public function updatePackagesCount(string $hotel_id, int $count): bool
    {
        return $this->cache->updatePackagesCount($hotel_id, $count);
    }

    // ════════════════════════════════════════════════════════════════════
    // HotelPackageRepository delegation (5 methods)
    // ════════════════════════════════════════════════════════════════════

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getPackages(string $hotel_id): array
    {
        return $this->packages->findByHotelIdFull($hotel_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getPackagesForListing(string $hotel_id): array
    {
        return $this->packages->findForListing($hotel_id);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function savePackage(string $hotel_id, string $package_id, array $data): bool
    {
        return $this->packages->upsert($hotel_id, $package_id, $data);
    }

    #[\Override]
    public function getLatestPriceinfoData(string $hotel_id): ?string
    {
        return $this->packages->getLatestPriceinfoData($hotel_id);
    }

    #[\Override]
    public function getLatestPackageSyncedAt(string $hotel_id): ?string
    {
        return $this->packages->getLastSyncedAt($hotel_id);
    }

    // ════════════════════════════════════════════════════════════════════
    // Private helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Filter null values from a data array to prevent PHP 8.1+
     * real_escape_string() deprecation when passed to ?e / ?u placeholders.
     * Null values are removed so the DB column keeps its DEFAULT / current value.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filterNullValues(array $data): array
    {
        return array_filter($data, static fn ($v): bool => $v !== null);
    }
}
