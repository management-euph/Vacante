<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Hotel Search Repository Contract
 *
 * Focused contract for read-side hotel queries: listing, filtering,
 * pagination, sync-selection, and staleness-based retrieval.
 *
 * Extracted from HotelRepository to unwind the 49-method god-object
 * (architectural audit: PR #3). Callers that only need to *find*
 * hotels should type-hint this interface instead of the full
 * HotelRepositoryInterface (which still delegates here for backward
 * compatibility).
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * @phpstan-type HotelRow = array<string, mixed>
 */
interface HotelSearchRepositoryInterface
{
    /**
     * Find all hotels matching optional filters, with pagination.
     * @param array<string, mixed> $filters
     * @return list<HotelRow>
     */
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array;

    /**
     * Alias for findAll — kept for backward compat with the admin listing callers.
     * @param array<string, mixed> $filters
     * @return list<HotelRow>
     */
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array;

    /** @return list<HotelRow> */
    public function findByCountry(string $country): array;

    /** @return list<HotelRow> */
    public function findByCountryForListing(string $country): array;

    /** @return array<string, HotelRow> */
    public function findByCountryIndexed(string $country): array;

    /** @return list<HotelRow> */
    public function findByCountryWithLimit(string $country, int $limit = 0): array;

    /** @return list<HotelRow> */
    public function findWithoutPackages(int $limit = 0): array;

    /**
     * @param list<string> $excludeResorts
     * @param list<string>|null $onlyResorts null = every resort; a list = only these (the destination whitelist)
     * @return list<HotelRow>
     */
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0, ?array $onlyResorts = null): array;

    /** @return list<HotelRow> */
    public function findUnlinkedForAdmin(string $country, string $filter = 'prices', int $limit = 500): array;

    /** @return list<HotelRow> */
    public function findNeedingPriceCheck(int $daysStale = 7, int $limit = 100): array;

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

    /** @return list<HotelRow> */
    public function findLinkedForSeo(int $offset, int $batch): array;

    /** @return list<HotelRow> */
    public function findWithPriceinfoData(int $limit = 200): array;

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;
}
