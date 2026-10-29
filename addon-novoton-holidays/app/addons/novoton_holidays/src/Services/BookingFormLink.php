<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Where an add_to_cart that could not price the booking sends the guest:
 * back to the booking form of the same offer, with the error on top.
 *
 * The form needs the room: a link without one (as add_to_cart built it)
 * made booking_form send the guest to the homepage under a second error,
 * "Date de rezervare invalide (missing room_id)". So the link carries the
 * room, board and package, the dates and guests, the price the form showed
 * and the rooms (no prices) it posted, one or several.
 *
 * Pure: no API, no CS-Cart.
 *
 * @phpstan-import-type BookedRoom from BookedRooms
 */
final class BookingFormLink
{
    /**
     * @param array<string, mixed> $request the posted booking form (room_id already normalised)
     * @param string $childrenAges every room's child ages, in room order, comma-separated
     * @param list<BookedRoom>|null $bookedRooms BookedRooms::fromRequest(); null: inconsistent, none sent back
     * @param float $shownPrice the price the form showed
     * @return string the dispatch, for a CS-Cart redirect
     */
    public static function back(array $request, int $productId, string $childrenAges, ?array $bookedRooms, float $shownPrice): string
    {
        $params = [
            'hotel_id' => TypeCoerce::toString($request['hotel_id'] ?? ''),
            'product_id' => $productId,
            'room_id' => TypeCoerce::toString($request['room_id'] ?? ''),
            'board_id' => TypeCoerce::toString($request['board_id'] ?? ''),
            'package_name' => TypeCoerce::toString($request['package_name'] ?? ''),
            'check_in' => TypeCoerce::toString($request['check_in'] ?? ''),
            'check_out' => TypeCoerce::toString($request['check_out'] ?? ''),
            'nights' => TypeCoerce::toString($request['nights'] ?? ''),
            'adults' => TypeCoerce::toInt($request['adults'] ?? 2),
            'children' => TypeCoerce::toInt($request['children'] ?? 0),
            'children_ages' => $childrenAges,
            'total_price' => $shownPrice,
            'rooms' => TypeCoerce::toInt($request['num_rooms'] ?? 1),
        ];
        if ($bookedRooms !== null) {
            $params['rooms_data'] = json_encode($bookedRooms);
            $params['rooms'] = count($bookedRooms);
        }

        return 'novoton_booking.booking_form?' . http_build_query($params);
    }
}
