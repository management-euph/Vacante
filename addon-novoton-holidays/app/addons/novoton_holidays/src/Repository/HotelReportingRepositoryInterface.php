<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Hotel Reporting Repository Contract
 *
 * Focused contract for aggregate/geography/stat queries over the
 * novoton_hotels and novoton_hotel_packages tables. Used by the
 * admin dashboard, sync pages, and CSV-export screens.
 *
 * Extracted from HotelRepository to unwind the 49-method god object.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

interface HotelReportingRepositoryInterface
{
    /**
     * Get the distinct country codes that have any hotel.
     *
     * @return string[]
     */
    public function getCountries(): array;

    /**
     * Get the distinct resorts (city column) for a country.
     *
     * Hidden/internal resorts (Constants::HIDDEN_RESORTS) are excluded.
     * When called with no country, returns resorts across all countries.
     *
     * @return string[]
     */
    public function getResorts(string $country = ''): array;

    /**
     * Get distinct country/city combinations for resort listing.
     *
     * @return array<int, array{country: string, city: string}>
     */
    public function getCountryCityPairs(): array;

    /**
     * Every resort with its hotel and product counts, for the dashboard's
     * excluded-resorts list (what excluding a resort would affect).
     *
     * @return list<array{country: string, city: string, hotels: int, products: int}>
     */
    public function getResortCounts(): array;

    /**
     * Get countries with a count of hotels that have active prices.
     *
     * @return list<array<string, mixed>>
     */
    public function getCountriesWithPriceCounts(): array;

    /**
     * Count hotels without any linked package, grouped by country.
     *
     * @return array<string, int> Keyed by country code
     */
    public function countWithoutPackagesByCountry(): array;

    /** Count distinct hotels with at least one package in a country. */
    public function countWithPackagesByCountry(string $country): int;

    /**
     * Resort statistics (total hotels, hotels-with-prices) per city.
     *
     * @return list<array<string, mixed>>
     */
    public function getResortStatsByCountry(string $country): array;

    /** Count hotels that have calendar_prices_raw populated. */
    public function countWithCalendarPrices(): int;
}
