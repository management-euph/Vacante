<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Flattens a Sphinx hotel-search offer into the shape the storefront
 * search template (and its JS poller) consumes.
 *
 * The Sphinx /hotels/search results endpoint returns a nested offer shape:
 *   {
 *     offer_id, hotel_id, hotel_name, destination_name,
 *     pricing: { selling_price, currency, ... },
 *     rooms: [ { room_name|room_type|name, ... }, ... ],
 *     meal_type_name, ...
 *   }
 *
 * The template and the JS renderer read a FLAT shape (price, currency,
 * destination, board_name, room_name). Without this mapping every offer
 * renders with a 0,00 price and blank room/board, and the commission step
 * in the poll controller is skipped (it keys off `price`, which is absent).
 *
 * Field sources mirror the existing readers already in the codebase:
 *   - pricing.selling_price / pricing.currency  (CircuitSyncService, ExperienceSyncService)
 *   - meal_type_name                            (DiscoverBoardsCommand)
 *   - destination_name                          (HotelSyncService, CacheEndpointService)
 *
 * Each source also falls back to the legacy flat key, so already-flat
 * payloads (e.g. cached results) pass through unchanged and idempotently.
 *
 * `room_lines` lists every room of the offer (name + guests), so a
 * multi-room offer shows all its rooms, not just the first one's name.
 */
class SearchOfferNormalizer
{
    /**
     * @param array<string, mixed> $offer Raw search offer from the Sphinx API
     * @return array<string, mixed> Flattened offer with template-ready keys
     */
    public static function flatten(array $offer): array
    {
        $pricing = TypeCoerce::toStringMap($offer['pricing'] ?? null);

        // Price sources: flat `price`, top-level `selling_price`, or nested
        // `pricing.selling_price` — shared chain in OfferAvailability.
        $price = OfferAvailability::extractPrice($offer);

        $currency = TypeCoerce::toString(
            $offer['currency'] ?? $pricing['currency'] ?? '',
        );

        // room_name lives inside the first room of the offer's `rooms` array;
        // booking_form/circuit templates read room_name|room_type|name there.
        $roomName = TypeCoerce::toString($offer['room_name'] ?? $offer['room_type'] ?? '');
        if ($roomName === '') {
            $rooms = TypeCoerce::toRowList($offer['rooms'] ?? null);
            if ($rooms !== []) {
                $firstRoom = $rooms[0];
                $roomName = TypeCoerce::toString(
                    $firstRoom['room_name'] ?? $firstRoom['room_type'] ?? $firstRoom['name'] ?? '',
                );
            }
        }

        $flat = $offer;
        $flat['offer_id'] = TypeCoerce::toString($offer['offer_id'] ?? '');
        $flat['hotel_id'] = TypeCoerce::toString($offer['hotel_id'] ?? $offer['id'] ?? '');
        $flat['hotel_name'] = TypeCoerce::toString($offer['hotel_name'] ?? $offer['name'] ?? '');
        $flat['destination'] = TypeCoerce::toString(
            $offer['destination'] ?? $offer['destination_name'] ?? '',
        );
        $flat['room_name'] = $roomName;
        // meal_type is the live results endpoint's key (e.g. "ULTRA ALL
        // INCLUSIVE"); board_name/meal_type_name are the legacy/nested names.
        $flat['board_name'] = TypeCoerce::toString(
            $offer['board_name'] ?? $offer['meal_type_name'] ?? $offer['meal_type'] ?? '',
        );
        $flat['price'] = $price;
        $flat['currency'] = $currency;
        // Only offers that carry rooms get lines: an already-flat payload
        // (cached, re-flattened) passes through unchanged.
        if (TypeCoerce::toRowList($offer['rooms'] ?? null) !== []) {
            $flat['room_lines'] = self::roomLines($offer);
        }

        return $flat;
    }

    /**
     * One line per room of the offer — a multi-room search returns ONE offer
     * for the whole party, its `rooms` array holding a room per occupancy.
     *
     * @param array<string, mixed> $offer
     * @return list<array{name: string, adults: int, children: int}>
     */
    private static function roomLines(array $offer): array
    {
        $lines = [];
        foreach (TypeCoerce::toRowList($offer['rooms'] ?? null) as $room) {
            $ages = $room['children_ages'] ?? [];
            $lines[] = [
                'name' => TypeCoerce::toString($room['room_name'] ?? $room['name'] ?? $room['room_type'] ?? ''),
                'adults' => TypeCoerce::toInt($room['adults'] ?? 0),
                'children' => is_array($ages) ? count($ages) : 0,
            ];
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public static function flattenAll(array $offers): array
    {
        return array_map([self::class, 'flatten'], $offers);
    }
}
