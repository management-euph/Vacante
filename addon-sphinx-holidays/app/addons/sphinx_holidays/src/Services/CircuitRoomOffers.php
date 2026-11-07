<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * "Camera pentru tot circuitul" on a circuit's page (board 16.3): the rooms
 * Sphinx offers for one departure and the customer's rooms, each with its
 * price for the whole group, commission included.
 *
 * One circuits/quote answer is a list of offers, one per room type and meal
 * plan, each with its flight. The offers that fly the same flight make one
 * block (the flight, then their rooms), cheapest first; a bus circuit has
 * one block without a flight. Every offer is kept on the server
 * (CircuitQuoteStore), so its "Rezervă" opens the booking form on that very
 * offer, at that price, without quoting again.
 *
 * Only what the page shows leaves the server: the store's price (never the
 * supplier's), the room names, the meal plan and the flight.
 */
final class CircuitRoomOffers
{
    /** Adults in one room, at most (the guest picker's own limit). */
    public const int MAX_ADULTS = 9;

    /** Children in one room, at most (the guest picker's own limit). */
    public const int MAX_CHILDREN = 4;

    /** A child is at most this old. */
    public const int MAX_CHILD_AGE = 17;

    /**
     * @param SphinxApi $api the API
     * @param \Closure(float): float $commission the store's selling price for a supplier price
     * @param CircuitQuoteStore $store where the offers are kept for the booking form
     */
    public function __construct(
        private readonly SphinxApi $api,
        private readonly \Closure $commission,
        private readonly CircuitQuoteStore $store = new CircuitQuoteStore(),
    ) {
    }

    /**
     * @param int $circuitId the circuit
     * @param int $departureId the city it leaves from (0: the supplier's default)
     * @param string $date the departure date, Y-m-d
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy one entry per room
     * @param int $now the current time
     *
     * @return array{blocks: list<array{flight: array{outbound: array<string, string>|null, inbound: array<string, string>|null}|null, nights: int, offers: list<array{offer_id: string, key: string, rooms: list<array{name: string, count: int}>, meal: string, price: float, currency: string}>}>, error: bool} error when Sphinx gave no answer
     */
    public function forDeparture(int $circuitId, int $departureId, string $date, array $occupancy, int $now): array
    {
        $params = ['circuit_id' => $circuitId, 'departure_date' => $date, 'occupancy' => $occupancy];
        if ($departureId > 0) {
            $params['departure_id'] = $departureId;
        }
        $quotes = $this->api->findCircuitQuotes($params);
        if ($quotes === null) {
            return ['blocks' => [], 'error' => true];
        }

        $kept = [];
        $blocks = [];
        foreach ($quotes as $quote) {
            $offerId = TypeCoerce::toString($quote['offer_id'] ?? '');
            $pricing = TypeCoerce::toStringMap($quote['pricing'] ?? null);
            $supplierPrice = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
            if ($offerId === '' || $supplierPrice <= 0 || TypeCoerce::toInt($quote['circuit_id'] ?? $circuitId) !== $circuitId) {
                continue;
            }
            $kept[] = $quote;

            $flight = self::flight($quote['flight'] ?? null);
            $blockKey = (string) json_encode($flight);
            $blocks[$blockKey] ??= [
                'flight' => $flight,
                'nights' => TypeCoerce::toInt(TypeCoerce::toStringMap($quote['duration'] ?? null)['nights'] ?? 0),
                'offers' => [],
            ];
            $blocks[$blockKey]['offers'][] = [
                'offer_id' => $offerId,
                'key' => self::key($quote),
                'rooms' => self::roomNames($quote['rooms'] ?? []),
                'meal' => TypeCoerce::toString($quote['meal_type_name'] ?? ''),
                'price' => round(($this->commission)($supplierPrice), 2),
                'currency' => TypeCoerce::toString($pricing['currency'] ?? ''),
            ];
        }
        $this->store->rememberQuotes($kept, $circuitId, $date, $now);

        // Cheapest first, in each block and between the blocks.
        foreach ($blocks as &$block) {
            usort($block['offers'], static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        }
        unset($block);
        $blocks = array_values($blocks);
        usort($blocks, static fn (array $a, array $b): int => $a['offers'][0]['price'] <=> $b['offers'][0]['price']);

        return ['blocks' => $blocks, 'error' => false];
    }

    /**
     * Rooms the bar may ask about: one to $maxRooms, each with 1 to 9
     * adults and at most 4 children of 0 to 17 (the guest picker's limits).
     *
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     */
    public static function validOccupancy(array $occupancy, int $maxRooms): bool
    {
        if ($occupancy === [] || count($occupancy) > $maxRooms) {
            return false;
        }
        foreach ($occupancy as $room) {
            if ($room['adults'] < 1 || $room['adults'] > self::MAX_ADULTS || count($room['children_ages']) > self::MAX_CHILDREN) {
                return false;
            }
            foreach ($room['children_ages'] as $age) {
                if ($age < 0 || $age > self::MAX_CHILD_AGE) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * What an offer is, whatever its id: its rooms' codes and its meal plan.
     * A new quote mints new offer ids; the booking form finds the same room
     * in it by this key when the offer chosen on the page is no longer kept.
     *
     * @param array<string, mixed> $quote one quote of circuits/quote
     */
    public static function key(array $quote): string
    {
        $codes = array_map(
            static fn (array $room): string => TypeCoerce::toString($room['code'] ?? ''),
            TypeCoerce::toRowList($quote['rooms'] ?? []),
        );
        $meal = TypeCoerce::toString($quote['meal_type_category_id'] ?? '');

        return implode('+', $codes) . '|' . ($meal !== '' ? $meal : TypeCoerce::toString($quote['meal_type_name'] ?? ''));
    }

    /**
     * The quote with this key (key()), else null.
     *
     * @param list<array<string, mixed>> $quotes the quotes of circuits/quote
     *
     * @return array<string, mixed>|null
     */
    public static function withKey(array $quotes, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        foreach ($quotes as $quote) {
            if (self::key($quote) === $key) {
                return $quote;
            }
        }

        return null;
    }

    /**
     * The rooms an offer is priced for, as circuits/quote takes them; []
     * when one of its rooms does not say its adults.
     *
     * @param array<string, mixed> $quote one quote of circuits/quote
     *
     * @return list<array{adults: int, children_ages: list<int>}>
     */
    public static function occupancyOf(array $quote): array
    {
        $occupancy = [];
        foreach (TypeCoerce::toRowList($quote['rooms'] ?? []) as $room) {
            $adults = TypeCoerce::toInt($room['adults'] ?? 0);
            if ($adults < 1) {
                return [];
            }
            $occupancy[] = [
                'adults' => $adults,
                'children_ages' => array_map(
                    static fn (mixed $age): int => TypeCoerce::toInt($age),
                    TypeCoerce::toList($room['children_ages'] ?? []),
                ),
            ];
        }

        return $occupancy;
    }

    /**
     * The flight of an offer, as the section shows it: each way's local
     * date and time (as the supplier gives them, never converted), its
     * airports and the airline. Null without either way (a bus circuit).
     *
     * @param mixed $flight the quote's flight
     *
     * @return array{outbound: array<string, string>|null, inbound: array<string, string>|null}|null
     */
    public static function flight(mixed $flight): ?array
    {
        $flight = TypeCoerce::toStringMap($flight);
        $outbound = self::leg($flight['outbound'] ?? null);
        $inbound = self::leg($flight['inbound'] ?? null);

        return $outbound === null && $inbound === null ? null : ['outbound' => $outbound, 'inbound' => $inbound];
    }

    /**
     * @param mixed $leg one way of the flight
     *
     * @return array<string, string>|null
     */
    private static function leg(mixed $leg): ?array
    {
        $leg = TypeCoerce::toStringMap($leg);
        $departure = TypeCoerce::toStringMap($leg['departure'] ?? null);
        $arrival = TypeCoerce::toStringMap($leg['arrival'] ?? null);
        [$departureDate, $departureTime] = self::localDateTime(TypeCoerce::toString($departure['datetime'] ?? ''));
        if ($departureDate === '') {
            return null;
        }
        [$arrivalDate, $arrivalTime] = self::localDateTime(TypeCoerce::toString($arrival['datetime'] ?? ''));

        return [
            'departure_date' => $departureDate,
            'departure_time' => $departureTime,
            'from' => TypeCoerce::toString($departure['name'] ?? ''),
            'from_code' => TypeCoerce::toString($departure['code'] ?? ''),
            'arrival_date' => $arrivalDate,
            'arrival_time' => $arrivalTime,
            'to' => TypeCoerce::toString($arrival['name'] ?? ''),
            'to_code' => TypeCoerce::toString($arrival['code'] ?? ''),
            'airline' => TypeCoerce::toString(TypeCoerce::toStringMap($leg['airline'] ?? null)['name'] ?? ''),
        ];
    }

    /**
     * "2027-07-05T10:40:00+03:00" → ["2027-07-05", "10:40"]: the local
     * time of the airport, as written; ['', ''] when it is not one.
     *
     * @return array{0: string, 1: string}
     */
    private static function localDateTime(string $value): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $value, $m) !== 1) {
            return ['', ''];
        }

        return [$m[1], $m[2] ?? ''];
    }

    /**
     * The rooms of an offer by name, a name met twice counted twice.
     *
     * @param mixed $rooms the quote's rooms
     *
     * @return list<array{name: string, count: int}>
     */
    private static function roomNames(mixed $rooms): array
    {
        $counts = [];
        foreach (TypeCoerce::toRowList($rooms) as $room) {
            $name = trim((string) preg_replace('/\s+/', ' ', TypeCoerce::toString($room['name'] ?? '')));
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($counts as $name => $count) {
            $out[] = ['name' => (string) $name, 'count' => $count];
        }

        return $out;
    }
}
