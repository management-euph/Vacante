<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

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
     * The booked offer and the answer it is in, out of the two questions
     * the booking form's re-price and add_to_cart both ask: the booked room
     * on its board, then, when that answer does not have the offer, every
     * room and board of the hotel (the search's own question, the one the
     * offer was shown from).
     *
     * Novoton can answer a room on a board it names itself with nothing:
     * Holiday Park (270) prices "DBL 2+0 ECONOMY" on "ALL INCL" (package
     * "HOLIDAY PARK **** 2027") only when no board is asked for. add_to_cart
     * asked the first question alone and rejected every such booking
     * ("Verificarea prețului a eșuat"), while the search and the booking
     * form showed the offer.
     *
     * The clicked package is looked for in both answers before another
     * package's price is taken (pick()).
     *
     * @param \Closure(array<string, mixed>): (\SimpleXMLElement|false) $ask sends one room_price request
     * @param array<string, mixed> $request the booked room's room_price request (hotel, room, board, dates, guests)
     * @param string $package the clicked package ('' = cheapest of any package)
     * @param array<string, mixed> $logContext who asks (hotel_id, source), for pick()'s log line
     * @return array{answer: \SimpleXMLElement, offer: OfferRow}|null null: neither answer offers this room + board
     */
    public static function find(\Closure $ask, array $request, string $package, array $logContext = []): ?array
    {
        $room = rawurldecode(TypeCoerce::toString($request['room_id'] ?? ''));
        $board = TypeCoerce::toString($request['board_id'] ?? '');
        $questions = [$request];
        if ($room !== '' || $board !== '') {
            $questions[] = ['room_id' => '', 'board_id' => ''] + $request;
        }

        $answers = [];
        foreach ($questions as $question) {
            $answer = $ask($question);
            if (!$answer instanceof \SimpleXMLElement) {
                continue;
            }
            $row = RoomOfferRows::cheapest(RoomOfferRows::fromXml($answer), $room, $board, $package);
            if ($row !== null) {
                return ['answer' => $answer, 'offer' => $row];
            }
            $answers[] = $answer;
        }

        foreach ($answers as $answer) {
            $row = self::pick($answer, $room, $board, $package, $logContext);
            if ($row !== null) {
                return ['answer' => $answer, 'offer' => $row];
            }
        }

        return null;
    }

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
