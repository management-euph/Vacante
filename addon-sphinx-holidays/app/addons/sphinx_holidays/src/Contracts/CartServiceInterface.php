<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx shared cart service.
 *
 * Encapsulates the logic shared across all Sphinx add-to-cart controllers
 * (hotel, circuit, experience, package): rate limiting, duplicate detection,
 * commission calculation, product resolution, guest validation, booking
 * upsert, and CS-Cart cart assembly.
 */
interface CartServiceInterface
{
    /**
     * Check rate limit for the current user/session.
     * Returns a controller redirect tuple if the limit is exceeded, or null.
     *
     * @return array<int, mixed>|null
     */
    public function checkRateLimit(string $errorRedirect = 'index.index'): ?array;

    /**
     * Check for an existing pending booking with the same offer_id.
     * Returns a redirect array if a duplicate is found, null otherwise.
     * $redirectUrl null = the cart, or checkout when the cart is skipped
     * (Settings -> Travel Core -> "Skip the cart page for").
     *
     * @return array<int, mixed>|null
     */
    public function checkDuplicate(string $offerId, ?string $redirectUrl = null): ?array;

    /**
     * Apply configured commission (if any) to a price.
     */
    public function applyCommission(float $price): float;

    /**
     * Sanitize raw guest data and run server-side validation.
     *
     * @return array<string, mixed>|false
     * @param array<string, mixed> $rawGuests
     */
    public function parseGuests(array $rawGuests, string $dateRef): array|false;

    /**
     * Resolve a CS-Cart product_id from an entity ID (hotel_id, circuit_id, etc.).
     */
    public function resolveProductId(string $entityId, int $providedId = 0): int;

    /**
     * Create or update a booking record using the findRecentUnassigned pattern.
     * Returns the booking_id.
     * @param array<string, mixed> $record
     */
    public function upsertBooking(
        array $record,
        string $entityId,
        string $checkIn,
        string $checkOut,
        string $holderName,
    ): int;

    /**
     * Assemble the product entry in the CS-Cart cart and persist it.
     * Returns the controller redirect tuple. With a deposit plan the line
     * charges only the deposit (travel_core DepositCartLine).
     *
     * @return array<int, mixed>
     * @param array<string, mixed> $productExtra
     */
    public function addToCartAndRedirect(
        int $productId,
        float $totalPrice,
        string $apiCurrency,
        array $productExtra,
        string $successMessage,
        ?string $redirectUrl = null,
        ?\Tygh\Addons\TravelCore\Services\DepositPlan $deposit = null,
    ): array;

    /**
     * Build the base booking record fields shared across all 4 booking types.
     *
     * @return array<string, mixed>
     * @param array<string, mixed> $parsedGuests
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $apiResponse
     */
    public function buildBaseBookingRecord(
        int $productId,
        string $entityId,
        string $offerId,
        string $hotelName,
        array $parsedGuests,
        array $contact,
        float $basePrice,
        float $totalPrice,
        string $currency,
        array $apiResponse,
    ): array;
}
