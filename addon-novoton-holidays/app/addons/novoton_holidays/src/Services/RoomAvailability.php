<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * Which rooms of a booking the hotel confirms at once and which it is asked
 * for (on request), read from the hotel's quota when the booking goes into
 * the cart — the split booking the owner chose (docs/hotel-page-design-a-
 * plan.md, phase 5): the rooms confirmed at once are reserved together, the
 * rooms on request in a reservation of their own
 * (BookingRoomAssembler::groupRoomsByPackage), so one room on request no
 * longer holds the others.
 *
 * The server decides, never the page: each room gets `on_request` from the
 * hotel's quota (hotel_quota), read as the search reads it
 * (SearchService::parseQuotaValue): "RQ", "REQUEST", "" or 0 is on request,
 * a number is the rooms left, a room type the answer does not list is
 * bookable. With N left of a type, its first N rooms (in booking order) are
 * confirmed at once and the rest are on request.
 *
 * One room needs no split and asks nothing. A quota that cannot be read
 * leaves the rooms unmarked: one reservation, as before. RoomsQuote marks
 * the rooms it prices for add_to_cart, so the mark is stored with them.
 */
final class RoomAvailability
{
    public const string FIELD = 'on_request';

    /**
     * @param list<string> $roomIds each room's IdRoom, in booking order
     * @param \Closure(): array<array-key, mixed> $quota the hotel's quota per IdRoom (hotel_quota)
     * @return list<bool>|null each room on request or not; null: one room, or a quota that cannot be read
     */
    public static function flags(array $roomIds, \Closure $quota): ?array
    {
        if (count($roomIds) < 2) {
            return null;
        }
        try {
            $answer = $quota();
        } catch (\Throwable) {
            return null;
        }

        /** @var array<string, int|null> $left rooms left per room type; null = not limited */
        $left = [];
        foreach ($answer as $roomId => $value) {
            $parsed = SearchService::parseQuotaValue(TypeCoerce::toString($value));
            $left[RoomType::normalizeRoomCode((string) $roomId)] = $parsed['is_on_request'] ? 0 : $parsed['availability'];
        }

        $flags = [];
        foreach ($roomIds as $roomId) {
            $type = RoomType::normalizeRoomCode($roomId);
            $onRequest = false;
            if (isset($left[$type])) {
                $onRequest = $left[$type] <= 0;
                $left[$type]--;
            }
            $flags[] = $onRequest;
        }

        return $flags;
    }

    /**
     * Whether a booked room is on request, as RoomsQuote marked it (flags()).
     *
     * @param array<int|string, mixed> $room
     */
    public static function onRequest(array $room): bool
    {
        return ($room[self::FIELD] ?? false) === true;
    }
}
