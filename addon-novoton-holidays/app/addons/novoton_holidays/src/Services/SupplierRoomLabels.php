<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * Novoton's own name for each room of a booking, for the admin: the room's
 * IdRoom and, from the hotel's stored hotelinfo, its Type:
 * "QUAT 2+2 FRENCH BALCONY (QUATRIPLE)".
 *
 * The shop shows a translated room name ("Camera Cvadruplă"); staff who talk
 * to Novoton about a booking need the room as Novoton names it. The Type
 * comes from ?:novoton_hotels.hotel_data, the hotelinfo answer as the sync
 * stored it, so nothing is asked of the API. A room whose code the hotel's
 * rooms do not list keeps its code alone.
 */
final class SupplierRoomLabels
{
    /** @var \Closure(string): array<string, string> hotel id => [IdRoom => Type] */
    private readonly \Closure $types;

    /**
     * @param (\Closure(string): array<string, string>)|null $types IdRoom => Type of a hotel (default: the stored hotelinfo)
     */
    public function __construct(?\Closure $types = null)
    {
        $this->types = $types ?? self::storedTypes(...);
    }

    /**
     * @param list<string> $roomIds each room's IdRoom, in room order
     * @return list<string> "IdRoom (Type)", or the IdRoom alone; [] when no room has a code
     */
    public function labels(string $hotelId, array $roomIds): array
    {
        $codes = array_map(RoomType::normalizeRoomCode(...), $roomIds);
        if (array_filter($codes, static fn (string $code): bool => $code !== '') === []) {
            return [];
        }
        $types = $hotelId !== '' ? ($this->types)($hotelId) : [];

        return array_map(static function (string $code) use ($types): string {
            $type = $types[$code] ?? '';

            return $type !== '' && strcasecmp($type, $code) !== 0 ? "{$code} ({$type})" : $code;
        }, $codes);
    }

    /**
     * Each room's IdRoom: the booking's rooms_data in room order, else the
     * line's own room_id (one room).
     *
     * @return list<string>
     */
    public static function roomIds(mixed $roomsData, string $roomId): array
    {
        if (is_string($roomsData)) {
            $roomsData = json_decode($roomsData, true);
        }
        $ids = array_map(
            static fn (array $room): string => trim(TypeCoerce::toString($room['room_id'] ?? '')),
            TypeCoerce::toRowList($roomsData),
        );
        if ($ids === []) {
            $ids = [trim($roomId)];
        }

        return $ids;
    }

    /**
     * IdRoom => Type from the hotel's rooms in its stored hotelinfo, keyed by
     * the normalised code ("QUAT 2+2", never "QUAT 2%2B2").
     *
     * @return array<string, string>
     */
    public static function typesFromJson(string $roomsJson): array
    {
        $rooms = json_decode($roomsJson, true);
        if (!is_array($rooms)) {
            return [];
        }
        if (isset($rooms['IdRoom'])) {
            // A hotel with one room: the stored XML-to-JSON keeps it an object.
            $rooms = [$rooms];
        }
        $types = [];
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $code = RoomType::normalizeRoomCode(TypeCoerce::toString($room['IdRoom'] ?? ''));
            $type = trim(TypeCoerce::toString($room['Type'] ?? ''));
            if ($code !== '' && $type !== '') {
                $types[$code] = $type;
            }
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private static function storedTypes(string $hotelId): array
    {
        if (!function_exists('db_get_field')) {
            return [];
        }

        return self::typesFromJson(TypeCoerce::toString(db_get_field(
            "SELECT JSON_EXTRACT(hotel_data, '$.rooms') FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotelId,
        )));
    }
}
