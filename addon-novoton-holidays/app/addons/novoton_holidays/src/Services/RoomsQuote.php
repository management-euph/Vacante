<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;

/**
 * Prices every room of a booking (audit C2/H4): one room_price call per
 * room with that room's own room, board, adults and child ages, the offer
 * of its own package (BookedOffer), the commission applied per room. The
 * booking's price is the sum.
 *
 * A room that cannot be priced makes the whole quote null: a booking is
 * never charged for fewer rooms than it books.
 *
 * A room's offer is looked for as the booking form's re-price looks for
 * it (BookedOffer::find): the room on its board, then every room and board
 * of the hotel when Novoton answers that with nothing.
 *
 * Identical questions share one room_price call.
 *
 * @phpstan-import-type BookedRoom from BookedRooms
 * @phpstan-import-type OfferRow from RoomOfferRows
 * @phpstan-type PricedRoom array{room_id: string, board_id: string, package_name: string, adults: int, children: int, childrenAges: list<int>, price: float, price_raw: float}
 * @phpstan-type Quote array{rooms: non-empty-list<PricedRoom>, total: float, base: float, answer: \SimpleXMLElement, offer: OfferRow}
 */
final class RoomsQuote
{
    public function __construct(private readonly PricingApiClientInterface $pricing)
    {
    }

    /**
     * @param list<BookedRoom> $rooms BookedRooms::fromRequest()
     * @param array<string, mixed> $logContext who asks (hotel_id, source), for the log lines
     * @return Quote|null rooms: each room as it is booked — board and package are
     *                    the priced offer's; price (with commission) and
     *                    price_raw (net API price) are that room's.
     *                    total / base: the sums. answer / offer: room 1's
     *                    room_price answer and offer, whose terms are the
     *                    booking's. null: a room has no price.
     */
    public function price(string $hotelId, string $checkIn, string $checkOut, array $rooms, array $logContext = []): ?array
    {
        $priced = [];
        $total = 0.0;
        $base = 0.0;
        $first = null;
        /** @var array<string, \SimpleXMLElement|false> $answers */
        $answers = [];

        foreach ($rooms as $index => $room) {
            $params = [
                'hotel_id' => $hotelId,
                'room_id' => $room['room_id'],
                'board_id' => $room['board_id'],
                'star_rating' => '',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $room['adults'],
                'children' => $room['childrenAges'],
            ];
            // The booking form's re-price asks the same questions
            // (BookedOffer::find), so the cart gets the price the form showed.
            $found = BookedOffer::find(
                function (array $request) use (&$answers): \SimpleXMLElement|false {
                    return $answers[serialize($request)] ??= $this->pricing->getRoomPrice($request);
                },
                $params,
                $room['package_name'],
                $logContext + ['room' => $index + 1],
            );
            if ($found === null) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton: a room of the booking has no price — the booking is not priced',
                    'room' => $index + 1,
                    'room_id' => $room['room_id'],
                    'board_id' => $room['board_id'],
                    'package_name' => $room['package_name'],
                    'adults' => $room['adults'],
                    'children_ages' => $room['childrenAges'],
                ] + $logContext);

                return null;
            }
            $first ??= $found;
            $offer = $found['offer'];

            $raw = $offer['price'];
            $price = $this->pricing->applyCommission($raw);
            $total += $price;
            $base += $raw;

            $board = $room['board_id'];
            if ($board === '') {
                // No board asked for: the offer was priced on its cheapest
                // board, so book that board.
                $board = $offer['board'] !== '' ? $offer['board'] : 'BB';
            }

            $priced[] = [
                'room_id' => $room['room_id'],
                'board_id' => $board,
                // The package the price comes from: the asked one, or, when
                // the answer no longer offers it, the one BookedOffer priced.
                'package_name' => $offer['package'] !== '' ? $offer['package'] : $room['package_name'],
                'adults' => $room['adults'],
                'children' => $room['children'],
                'childrenAges' => $room['childrenAges'],
                'price' => $price,
                'price_raw' => $raw,
            ];
        }

        if ($first === null) {
            return null;
        }

        return [
            'rooms' => $priced,
            'total' => $total,
            'base' => $base,
            'answer' => $first['answer'],
            'offer' => $first['offer'],
        ];
    }
}
