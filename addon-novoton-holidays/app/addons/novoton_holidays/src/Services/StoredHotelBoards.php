<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * The boards in a hotel's stored hotelinfo JSON (novoton_hotels.hotel_data).
 *
 * The sync stores json_encode() of Novoton's XML, so the key is the element's
 * own name: `board` (one <board><IdBoard>BB</IdBoard></board> per board), as
 * HotelInfoExtractor reads it from the live response. The readers of the
 * stored copy looked for `boards`, found none, and no product ever got its
 * "Regim de Masă" ({"board":[{"IdBoard":"BB"},{"IdBoard":"HB"},
 * {"IdBoard":"ALL INCL"}]} for hotel 4516). Both names are accepted, and a
 * hotel with a single board (json_encode gives one object, not a list) comes
 * back as a list of one.
 *
 * Pure: no API, no CS-Cart.
 */
final class StoredHotelBoards
{
    /**
     * @param array<array-key, mixed> $hotelInfo the decoded hotel_data
     * @return list<mixed> the board entries (rows with IdBoard, or plain codes), [] when none
     */
    public static function fromHotelInfo(array $hotelInfo): array
    {
        $boards = $hotelInfo['boards'] ?? $hotelInfo['board'] ?? null;
        if (!is_array($boards) || $boards === []) {
            return [];
        }

        // One board: {"IdBoard":"BB"}, not [{"IdBoard":"BB"}].
        if (isset($boards['IdBoard']) || isset($boards['Board'])) {
            return [$boards];
        }

        return array_values($boards);
    }
}
