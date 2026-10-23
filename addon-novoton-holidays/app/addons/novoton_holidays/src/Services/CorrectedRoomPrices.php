<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A checkout price correction written into the booking's rooms (audit
 * C2/H4, novoton-money-9).
 *
 * pre_place_order corrects a line to the live price of its rooms. The
 * booking records are built from rooms_data at submission
 * (BookingRecordBuilder: total = the sum of the rooms' price), so the
 * corrected price must be in each room too — left at the add_to_cart price,
 * the submission wrote the pre-correction sum back over the corrected
 * total, and the line no longer matched its booking on a payment retry.
 *
 * Each room gets its own live price (PreOrderPriceVerifier's room_prices, in
 * rooms_data order); a one-room line gets the line's corrected price.
 *
 * Pure: no API, no CS-Cart.
 */
final class CorrectedRoomPrices
{
    /**
     * @param mixed $roomsData the line's rooms_data (list or JSON)
     * @param array<string, mixed> $correction PreOrderPriceVerifier::verify()['corrections'][cart id]
     * @return list<array<string, mixed>>|null the rooms with their corrected price and price_raw;
     *                                         null: no rooms, or prices that do not fit them
     */
    public static function apply(mixed $roomsData, array $correction): ?array
    {
        if (is_string($roomsData) && $roomsData !== '') {
            $roomsData = json_decode($roomsData, true);
        }
        if (!is_array($roomsData) || $roomsData === [] || !array_is_list($roomsData)) {
            return null;
        }
        $rooms = [];
        foreach ($roomsData as $room) {
            if (!is_array($room)) {
                return null;
            }
            $rooms[] = TypeCoerce::toStringMap($room);
        }

        if (count($rooms) === 1) {
            $prices = [[
                'price' => TypeCoerce::toFloat($correction['api_price'] ?? 0),
                'price_raw' => TypeCoerce::toFloat($correction['api_price_raw'] ?? 0),
            ]];
        } else {
            $prices = TypeCoerce::toRowList($correction['room_prices'] ?? null);
            if (count($prices) !== count($rooms)) {
                return null;
            }
        }

        foreach ($rooms as $index => $room) {
            $price = TypeCoerce::toFloat($prices[$index]['price'] ?? 0);
            if ($price <= 0) {
                return null;
            }
            $room['price'] = $price;
            $raw = TypeCoerce::toFloat($prices[$index]['price_raw'] ?? 0);
            if ($raw > 0) {
                $room['price_raw'] = $raw;
            }
            $rooms[$index] = $room;
        }

        return $rooms;
    }
}
