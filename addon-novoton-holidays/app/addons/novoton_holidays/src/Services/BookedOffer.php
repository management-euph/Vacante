<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * The offer a booking is priced from, out of a room_price answer: same room
 * and board AND the package of the card the customer clicked.
 *
 * room_price lists one row per package (e.g. "+BEACH" at 795 next to an
 * early-booking "+BEACH [STAY …]" at 600). The booking page re-price and
 * add_to_cart took the cheapest row of ANY package, so the clicked 795 card
 * reached the cart at 600 while the booking kept "+BEACH" — and the
 * Place-order check then pushed it back up. Only when the clicked package is
 * missing from the answer altogether is the booking priced by room + board
 * (the behaviour before packages were pinned), and the log says so.
 *
 * The Place-order check never falls back to another package
 * (PreOrderPriceVerifier::lineOffer): by then the customer has seen a price.
 *
 * @phpstan-import-type OfferRow from RoomOfferRows
 */
final class BookedOffer
{
    /**
     * @param string $room URL-decoded room id ('' = any)
     * @param string $board board id ('' = any)
     * @param string $package the clicked package ('' = cheapest of any package)
     * @param array<string, mixed> $logContext who asks (hotel_id, source), for the fallback log line
     * @return OfferRow|null null: this room + board is not offered — no price
     */
    public static function pick(\SimpleXMLElement $xml, string $room, string $board, string $package, array $logContext = []): ?array
    {
        $rows = RoomOfferRows::fromXml($xml);
        $row = RoomOfferRows::cheapest($rows, $room, $board, $package);
        if ($row !== null || RoomOfferRows::packageName($package) === '') {
            return $row;
        }

        $row = RoomOfferRows::cheapest($rows, $room, $board);
        if ($row !== null) {
            fn_log_event('general', 'runtime', ['message' => 'Novoton: booked package not offered, priced by room + board instead'] + $logContext + [
                'room_id' => $room,
                'board_id' => $board,
                'package_name' => $package,
                'priced_package' => $row['package'],
                'price' => $row['price'],
            ]);
        }

        return $row;
    }
}
