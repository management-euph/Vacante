<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Contracts\HotelRepositoryInterface as CoreHotelRepositoryInterface;
use Tygh\Addons\TravelCore\Dto\Hotel\Hotel;

/**
 * Novoton hotel repository contract.
 *
 * Extends the shared, provider-neutral {@see CoreHotelRepositoryInterface}
 * (findById/exists/delete/linkToProduct/unlinkProduct/getCountries) with the
 * Novoton-specific surface below: DTO views, package/priceinfo access, listing
 * and sync-selection queries, and reporting aggregates tied to the Novoton
 * schema.
 *
 * @phpstan-type HotelRow = array<string, mixed>
 * @phpstan-type PackageRow = array<string, mixed>
 */
interface HotelRepositoryInterface extends CoreHotelRepositoryInterface
{
    /**
     * Typed view of {@see self::findById()}.
     *
     * Returns the same row shape as a {@see Hotel} DTO; returns null when
     * no row matches. New code paths should prefer this over findById();
     * existing array callers keep working unchanged.
     */
    public function findByIdAsDto(string $hotel_id): ?Hotel;
    /** @return HotelRow|null */
    public function findBasicById(string $hotel_id): ?array;
    /** @return HotelRow|null */
    public function findByProductId(int $product_id): ?array;
    public function getHotelIdByProduct(int $product_id): ?string;
    /**
     * @param array<string, mixed> $filters
     * @return list<HotelRow>
     */
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array;
    /**
     * @param array<string, mixed> $filters
     * @return list<HotelRow>
     */
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array;
    /** @return list<HotelRow> */
    public function findByCountry(string $country): array;
    /** @return list<HotelRow> */
    public function findWithoutPackages(int $limit = 0): array;
    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;
    /** @return array<string, int> */
    public function countWithoutPackagesByCountry(): array;
    /**
     * @param array<string, mixed> $data
     */
    public function save(string $hotel_id, array $data): bool;
    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): bool;
    /**
     * @param array<string, mixed> $data
     */
    public function update(string $hotel_id, array $data): bool;
    /**
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): bool;
    /** @return list<string> */
    public function getResorts(string $country = ''): array;
    /** @return list<PackageRow> */
    public function getPackages(string $hotel_id): array;
    /** @return list<PackageRow> */
    public function getPackagesForListing(string $hotel_id): array;
    /**
     * @param array<string, mixed> $data
     */
    public function savePackage(string $hotel_id, string $package_id, array $data): bool;
    /**
     * @param list<string> $excludeResorts
     * @return list<HotelRow>
     */
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0): array;
    /**
     * @param list<string> $hotel_ids
     * @return array<string, array<string, mixed>>
     */
    public function getLocationsByIds(array $hotel_ids): array;
    /** @return list<HotelRow> */
    public function findByCountryForListing(string $country): array;
    public function getLatestPriceinfoData(string $hotel_id): ?string;
    public function getLatestPackageSyncedAt(string $hotel_id): ?string;
    public function getCalendarPricesRaw(string $hotel_id): ?string;
    public function setCalendarPricesRaw(string $hotel_id, ?string $json): void;
    /** @return list<HotelRow> */
    public function findNeedingPriceCheck(int $daysStale = 7, int $limit = 100): array;
    /** @return list<HotelRow> */
    public function findLinkedForSeo(int $offset, int $batch): array;
    /** @return list<string> */
    public function getAllIds(): array;
    /** @return array<string, HotelRow> */
    public function findByCountryIndexed(string $country): array;
    /** @return list<HotelRow> */
    public function findByCountryWithLimit(string $country, int $limit = 0): array;
    /** @return list<HotelRow> */
    public function findNeedingPriceUpdate(int $staleHours = 24, int $limit = 100): array;
    /** @return list<HotelRow> */
    public function findWithProductsSortedByStaleness(int $limit = 50): array;
    /** @return list<HotelRow> */
    public function findWithPricesForExport(string $country): array;
    /**
     * @param list<string> $selectedResorts
     * @return list<HotelRow>
     */
    public function findForImport(string $country, string $importMode = 'new_only', array $selectedResorts = [], int $limit = 0): array;
    /** @return list<string> */
    public function findIdsWithPriceinfoData(): array;
    public function countWithCalendarPrices(): int;
    public function countWithPackagesByCountry(string $country): int;
    /** @return list<array<string, mixed>> */
    public function getResortStatsByCountry(string $country): array;
    /** @return list<HotelRow> */
    public function findUnlinkedForAdmin(string $country, string $filter = 'prices', int $limit = 500): array;
    /** @return list<array<string, mixed>> */
    public function getCountriesWithPriceCounts(): array;
    /** @return list<array{country: string, city: string}> */
    public function getCountryCityPairs(): array;
    /** @return list<array{country: string, city: string, hotels: int, products: int}> */
    public function getResortCounts(): array;
    public function getHotelData(string $hotel_id): ?string;
    /** @return list<HotelRow> */
    public function findWithPriceinfoData(int $limit = 200): array;
    public function updatePackagesCount(string $hotel_id, int $count): bool;
}
