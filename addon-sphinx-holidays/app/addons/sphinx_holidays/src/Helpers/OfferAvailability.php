<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Availability semantics for Sphinx search offers.
 *
 * The Sphinx search results endpoint (/api/v1/hotels/results) returns offers
 * carrying a `confirmation` field. A value of `immediate` means the offer has
 * real-time availability and can be booked instantly; any other value (or a
 * missing field) means the offer is on-request / not immediately bookable.
 *
 * This helper is intentionally pure (no DB, no I/O) so the availability rule
 * lives in one tested place, shared by the hotel sync gate and the storefront
 * search filter.
 */
final class OfferAvailability
{
    /** Confirmation value that denotes real, immediately-bookable availability. */
    public const string CONFIRMATION_IMMEDIATE = 'immediate';

    /**
     * Whether a single offer has immediate confirmation.
     *
     * @param array<array-key, mixed> $offer
     */
    public static function isImmediate(array $offer): bool
    {
        return strtolower(trim(TypeCoerce::toString($offer['confirmation'] ?? '')))
            === self::CONFIRMATION_IMMEDIATE;
    }

    /**
     * Keep only offers with immediate confirmation, re-indexed.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public static function filterImmediate(array $offers): array
    {
        return array_values(array_filter($offers, self::isImmediate(...)));
    }

    /**
     * Offer price wherever the API put it: legacy flat `price`, the live
     * endpoints' top-level `selling_price`, or the nested
     * `pricing.selling_price` shape. Single source of truth for every
     * verify/search consumer.
     *
     * @param array<array-key, mixed> $offer
     */
    public static function extractPrice(array $offer): float
    {
        // Envelope-tolerant: verify responses may wrap the offer (see
        // unwrapOffer); flat offers pass through unchanged at depth 0.
        $offer = self::unwrapOffer($offer) ?? [];

        if (isset($offer['price']) && is_numeric($offer['price'])) {
            return (float) $offer['price'];
        }
        if (isset($offer['selling_price']) && is_numeric($offer['selling_price'])) {
            return (float) $offer['selling_price'];
        }
        $pricing = TypeCoerce::toStringMap($offer['pricing'] ?? null);
        return TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
    }

    /**
     * Unwrap a verify-offer response to the offer payload itself.
     *
     * The HTTP client returns the WHOLE decoded body and does not unwrap
     * envelopes (see SphinxHttpClient::request). When the verify endpoint
     * answers `{success: true, data: {confirmation, selling_price, …}}`, the
     * availability gate used to inspect only the TOP level, found none of the
     * offer signals there, and rejected EVERY offer ("offer no longer
     * available" on each Book-now click, on every date). Descend through the
     * known envelope keys (depth-bounded) until a level carries offer signals;
     * flat (un-enveloped) responses are returned unchanged.
     *
     * @param array<array-key, mixed>|null $response
     * @return array<array-key, mixed>|null
     */
    public static function unwrapOffer(?array $response): ?array
    {
        // Single implementation of the descent — the API-layer decode
        // boundary owns it; this helper stays as the availability-semantics
        // entry point (and for consumers holding pre-decoded payloads, the
        // descent is an idempotent no-op).
        return \Tygh\Addons\SphinxHolidays\Api\ResponseDecoder::descendToPayload($response);
    }

    /**
     * Whether a verify-offer response denotes a bookable offer.
     *
     * The verify endpoint's shape drifted twice: older builds returned an
     * explicit `available` boolean; later builds the bare offer
     * (`confirmation`, `selling_price`, …); the live API wraps the offer in a
     * `data`/`offer` envelope (handled by unwrapOffer above). Interpret
     * tolerantly, at whichever level the offer payload lives:
     *  - explicit `available` key → honor it (the API's explicit signal);
     *  - else a `confirmation` value → bookable (immediate-only when the
     *    storefront requires immediate availability);
     *  - else a derivable price → bookable (an offer with a price is sellable);
     *  - anything else (or an empty response) → not bookable.
     *
     * @param array<array-key, mixed>|null $response
     */
    public static function isVerifiedAvailable(?array $response, bool $requireImmediate): bool
    {
        $offer = self::unwrapOffer($response);
        if ($offer === null || $offer === []) {
            return false;
        }

        if (array_key_exists('available', $offer)) {
            $available = $offer['available'];
            if (is_string($available)) {
                return in_array(strtolower(trim($available)), ['1', 'true', 'yes', 'y'], true);
            }
            return (bool) $available;
        }

        if (TypeCoerce::toString($offer['confirmation'] ?? '') !== '') {
            return !$requireImmediate || self::isImmediate($offer);
        }

        return self::extractPrice($offer) > 0;
    }

    /**
     * Collect the set of hotel IDs that have at least one immediate-confirmation
     * offer. Returned as a map (hotel_id => true) for O(1) membership tests.
     *
     * Accepts the raw poll payload (list<mixed>): non-array rows and rows
     * without a usable hotel id are skipped. Mirrors the hotel-id resolution
     * used across the search controllers (hotel_id, falling back to id).
     *
     * @param list<mixed> $offers
     * @return array<string, true>
     */
    public static function collectImmediateHotelIds(array $offers): array
    {
        $hotelIds = [];
        foreach ($offers as $offer) {
            if (!is_array($offer) || !self::isImmediate($offer)) {
                continue;
            }
            $hotelId = TypeCoerce::toString($offer['hotel_id'] ?? $offer['id'] ?? '');
            if ($hotelId !== '') {
                $hotelIds[$hotelId] = true;
            }
        }

        return $hotelIds;
    }
}
