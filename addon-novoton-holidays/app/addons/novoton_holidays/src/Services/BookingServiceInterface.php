<?php

declare(strict_types=1);

/**
 * Novoton Booking Service Interface
 *
 * Contract for booking operations: creation, retrieval, cart, and order linking.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface BookingServiceInterface
{
    /**
     * @param array<string, mixed> $bookingData
     */
    public function createBooking(array $bookingData, int $product_id): int;
    /**
     * @param array<string, mixed> $data
     */
    public function updateBooking(int $booking_id, array $data): bool;
    /**
     * @return array<string, mixed>|null
     */
    public function getBooking(int $booking_id): ?array;
    /**
     * @return list<array<string, mixed>>
     */
    public function getBookingsForOrder(int $order_id): array;
    public function linkToOrder(int $booking_id, int $order_id): bool;
    /**
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     */
    public function parseRoomsData(array $bookingData): array;
    public function calculateNights(string $check_in, string $check_out): int;
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function verifyPrice(array $params): array;
    /**
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $hotelInfo
     * @param array<string, mixed> $guestsData
     * @param array<string, mixed> $priceResult
     * @param array<string, mixed> $roomsData
     * @return array<string, mixed>
     */
    public function assembleCartProduct(int $productId, int $bookingId, array $bookingData, array $hotelInfo, array $guestsData, array $priceResult, array $roomsData): array;
    /**
     * @param array<string, mixed> $roomsData
     * @param array<string, mixed> $guestsData
     * @return array<string, mixed>
     */
    public function enrichRoomsData(array $roomsData, array $guestsData): array;
    public function resolveProductId(string $hotelId, int $fallbackProductId = 0): int;
}
