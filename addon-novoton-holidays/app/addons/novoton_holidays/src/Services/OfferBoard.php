<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * The meal plan a Novoton offer is sold with: its board, or the supplement
 * that upgrades it.
 *
 * Off-season, Admiral (476) sells half board as "BB" with the "HB"
 * supplement (ExtBoard) next to "BB" alone, each at its own price (535 and
 * 415 for a double, 5–9 Nov 2026); asked for the room on "HB", Novoton
 * answers with that same "BB" + "HB" offer. The guest gets half board, and
 * the reservation books IdBoard "BB" with IdExtBoard "HB".
 *
 * Pure: no API, no CS-Cart.
 */
final class OfferBoard
{
    /** The board the guest gets: the supplement when there is one, else the board. */
    public static function meal(string $board, string $extBoard): string
    {
        $extBoard = trim($extBoard);

        return $extBoard !== '' ? $extBoard : trim($board);
    }
}
