<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for hotel search operations.
 *
 * @since 3.5.0
 */
interface SearchServiceInterface
{
    public function getBoardName(string $board_id): string;

    /**
     * Parse room_price API XML response into structured result array.
     * @param array<string, mixed> $quotaMap
     * @param array<string, mixed> $roomTypeMap
     * @return list<array<string, mixed>>
     */
    public function parseRoomPriceResponse(
        string $rawXml,
        int $nights,
        string $checkIn,
        string $checkOut,
        string $mealPlan = '',
        array $quotaMap = [],
        array $roomTypeMap = [],
        ?int $forRoom = null,
        ?string $occupancyStr = null,
    ): array;

    /**
     * Check if a board ID matches the requested meal plan.
     *
     * @param string $boardId Board identifier from the API response
     * @param string $mealPlan User-selected meal plan code (e.g. 'AI', 'HB')
     */
    public static function matchesMealPlan(string $boardId, string $mealPlan): bool;

    /**
     * Interpret a raw quota value from the hotel_quota API.
     *
     * @param string|null $quotaValue Raw quota (e.g. "5", "RQ", "REQUEST", "")
     * @return array{availability: int|null, is_on_request: bool}
     */
    public static function parseQuotaValue(?string $quotaValue): array;

    /**
     * Extract active early booking discounts from priceinfo_data.
     *
     * @param string $hotelId Hotel ID
     * @param string $checkIn Guest check-in date (Y-m-d)
     * @param string $checkOut Guest check-out date (Y-m-d)
     * @return list<array<string, mixed>> List of applicable discount records
     */
    public static function getEarlyBookingDiscounts(string $hotelId, string $checkIn, string $checkOut): array;

    /**
     * Calculate discount range from a list of early booking discounts.
     *
     * @param list<array<string, mixed>> $discounts From getEarlyBookingDiscounts()
     * @return array<string, mixed> {min, max, all} or empty
     */
    public static function getDiscountRange(array $discounts): array;

    /**
     * Deduplicate results, keeping the lowest price for each room/board/package.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>> Deduplicated results (re-indexed)
     */
    public static function deduplicateResults(array $results): array;
}
