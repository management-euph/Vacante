<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * "Camere și prețuri" on a hotel's page in a package mode (board 16.2): the
 * packages Sphinx offers for this one hotel, one departure city, date and
 * length, and the customer's rooms, each with its price for the whole group,
 * commission included.
 *
 * packages/search is asked with hotel_ids (seen live: it answers only that
 * hotel's offers, while hotels/search ignores hotel_ids) and polled to its
 * end, or for at most $budgetSeconds: the page waits for it. The offers that travel the same way make one block (the transport,
 * then their rooms), cheapest first: the transport is shown once above the
 * rooms, and a second block only when another flight leaves that day.
 *
 * "Rezervă" opens the package booking form on the offer's id
 * (sphinx_booking.package_booking_form), which verifies it with Sphinx.
 *
 * Only what the page shows leaves the server: the store's price (never the
 * supplier's), the room names, the meal plan, the confirmation and the
 * transport.
 */
final class PackageHotelOffers
{
    /**
     * @param SphinxApi $api the API
     * @param \Closure(float): float $commission the store's selling price for a supplier price
     * @param CacheService|null $cache where answers are kept a while (null: never)
     * @param int $cacheTtl how long, in seconds (0: never)
     * @param int $maxPolls results pages read at most
     * @param \Closure(): void|null $pause between two empty pages of a search still running
     * @param float $budgetSeconds how long a search may run: then the offers found so far
     * @param \Closure(): float|null $clock the time in seconds (null: microtime)
     */
    public function __construct(
        private readonly SphinxApi $api,
        private readonly \Closure $commission,
        private readonly ?CacheService $cache = null,
        private readonly int $cacheTtl = 0,
        private readonly int $maxPolls = 30,
        private readonly ?\Closure $pause = null,
        private readonly float $budgetSeconds = 25.0,
        private readonly ?\Closure $clock = null,
    ) {
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    /**
     * @param string $hotelId the hotel (Sphinx id)
     * @param string $transport flight or bus
     * @param int $departureId the city it leaves from (0: any)
     * @param string $date the departure date, Y-m-d
     * @param int $nights the nights at the hotel
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy one entry per room
     *
     * @return array{blocks: list<array{transport: string, flight: array{outbound: array<string, string|int>|null, inbound: array<string, string|int>|null}|null, check_in: string, check_out: string, offers: list<array{offer_id: string, rooms: list<array{name: string, count: int}>, meal: string, confirmation: string, price: float, currency: string}>}>, error: bool} error when Sphinx gave no answer
     */
    public function forHotel(string $hotelId, string $transport, int $departureId, string $date, int $nights, array $occupancy): array
    {
        $params = [
            'departure_date' => $date,
            'nights' => $nights,
            'transport' => [$transport],
            'hotel_ids' => [TypeCoerce::toInt($hotelId)],
            'occupancy' => $occupancy,
            'currency' => ConfigProvider::getDefaultCurrency(),
        ];
        if ($departureId > 0) {
            $params['departure_id'] = $departureId;
        }
        $ignore = ConfigProvider::getIgnoreDomains();
        if ($ignore !== '') {
            $params['ignore_domains'] = $ignore;
        }

        $offers = $this->search($params);
        if ($offers === null) {
            return ['blocks' => [], 'error' => true];
        }

        return ['blocks' => $this->blocks($offers, $hotelId, $transport), 'error' => false];
    }

    /**
     * The offers of a search polled to its end, from the cache when it was
     * asked a short while ago; null when Sphinx gave no answer.
     *
     * @param array<string, mixed> $params packages/search
     *
     * @return list<array<string, mixed>>|null
     */
    private function search(array $params): ?array
    {
        $key = CacheService::buildSearchKey(['_type' => 'package_hotel'] + $params);
        if ($this->cache !== null && $this->cacheTtl > 0) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return TypeCoerce::toRowList($cached['results'] ?? []);
            }
        }

        $startedAt = $this->now();
        $start = $this->api->searchPackages($params);
        $searchId = TypeCoerce::toString($start['search_id'] ?? '');
        $cursor = TypeCoerce::toString($start['cursor'] ?? '');
        if ($start === null || ($searchId === '' && $cursor === '')) {
            return null;
        }

        $offers = [];
        $complete = false;
        for ($poll = 0; $poll < $this->maxPolls; $poll++) {
            $page = $this->api->getPackageResults($searchId, $cursor !== '' ? $cursor : null);
            if ($page === null) {
                return $offers === [] ? null : $offers;
            }
            $rows = TypeCoerce::toRowList($page['results'] ?? []);
            array_push($offers, ...$rows);
            $cursor = TypeCoerce::toString($page['cursor'] ?? '');
            if ($cursor === '') {
                $complete = true;
                break;
            }
            // The page waits for this answer: past the budget, what was found
            // so far (none found: no answer).
            if ($this->now() - $startedAt >= $this->budgetSeconds) {
                return $offers === [] ? null : $offers;
            }
            if ($rows === [] && $this->pause !== null) {
                ($this->pause)();
            }
        }

        // Only a search polled to its end is kept: a cut one would hide the
        // rest of the offers for the cache's lifetime.
        if ($complete && $this->cache !== null && $this->cacheTtl > 0 && $offers !== []) {
            $this->cache->set($key, ['results' => $offers], $this->cacheTtl);
        }

        return $offers;
    }

    /**
     * The hotel's offers by the way they travel, cheapest first in each
     * block and between the blocks.
     *
     * @param list<array<string, mixed>> $offers packages/results offers
     *
     * @return list<array{transport: string, flight: array{outbound: array<string, string|int>|null, inbound: array<string, string|int>|null}|null, check_in: string, check_out: string, offers: list<array{offer_id: string, rooms: list<array{name: string, count: int}>, meal: string, confirmation: string, price: float, currency: string}>}>
     */
    public function blocks(array $offers, string $hotelId, string $transport): array
    {
        $blocks = [];
        foreach ($offers as $offer) {
            $hotel = TypeCoerce::toStringMap($offer['hotel'] ?? null);
            $offerId = TypeCoerce::toString($offer['offer_id'] ?? '');
            $pricing = TypeCoerce::toStringMap($offer['pricing'] ?? null);
            $supplierPrice = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
            if ($offerId === '' || $supplierPrice <= 0 || TypeCoerce::toString($hotel['id'] ?? '') !== $hotelId) {
                continue;
            }

            $flight = self::flight($offer['flight'] ?? null);
            $checkIn = TypeCoerce::toString($hotel['check_in'] ?? '');
            $checkOut = TypeCoerce::toString($hotel['check_out'] ?? '');
            $blockKey = (string) json_encode([$flight, $checkIn, $checkOut]);
            $blocks[$blockKey] ??= [
                'transport' => $flight !== null ? 'flight' : $transport,
                'flight' => $flight,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'offers' => [],
            ];
            $blocks[$blockKey]['offers'][] = [
                'offer_id' => $offerId,
                'rooms' => self::roomNames($hotel['rooms'] ?? []),
                'meal' => TypeCoerce::toString($hotel['meal_type_name'] ?? ''),
                'confirmation' => TypeCoerce::toString($offer['confirmation'] ?? ''),
                'price' => round(($this->commission)($supplierPrice), 2),
                'currency' => TypeCoerce::toString($pricing['currency'] ?? ''),
            ];
        }

        foreach ($blocks as &$block) {
            usort($block['offers'], static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        }
        unset($block);
        $blocks = array_values($blocks);
        usort($blocks, static fn (array $a, array $b): int => $a['offers'][0]['price'] <=> $b['offers'][0]['price']);

        return $blocks;
    }

    /**
     * A package's flight as the page shows it: each way from its first
     * segment's departure to its last segment's arrival, local date and time
     * as the supplier writes them (never converted), the airline and the
     * stops. Null without either way (a bus or a stay only).
     *
     * @param mixed $flight the offer's flight: outbound and inbound segment lists
     *
     * @return array{outbound: array<string, string|int>|null, inbound: array<string, string|int>|null}|null
     */
    public static function flight(mixed $flight): ?array
    {
        $flight = TypeCoerce::toStringMap($flight);
        $outbound = self::way($flight['outbound'] ?? null);
        $inbound = self::way($flight['inbound'] ?? null);

        return $outbound === null && $inbound === null ? null : ['outbound' => $outbound, 'inbound' => $inbound];
    }

    /**
     * @param mixed $segments one way: a list of flights (one per leg)
     *
     * @return array<string, string|int>|null
     */
    private static function way(mixed $segments): ?array
    {
        $segments = TypeCoerce::toRowList($segments);
        if ($segments === []) {
            return null;
        }
        $first = $segments[0];
        $last = $segments[count($segments) - 1];
        $departure = TypeCoerce::toStringMap($first['departure'] ?? null);
        $arrival = TypeCoerce::toStringMap($last['arrival'] ?? null);
        [$departureDate, $departureTime] = self::localDateTime(TypeCoerce::toString($departure['datetime'] ?? ''));
        if ($departureDate === '') {
            return null;
        }
        [$arrivalDate, $arrivalTime] = self::localDateTime(TypeCoerce::toString($arrival['datetime'] ?? ''));
        $airlines = array_values(array_unique(array_filter(array_map(
            static fn (array $segment): string => TypeCoerce::toString(TypeCoerce::toStringMap($segment['airline'] ?? null)['name'] ?? ''),
            $segments,
        ))));

        return [
            'departure_date' => $departureDate,
            'departure_time' => $departureTime,
            'from' => TypeCoerce::toString($departure['name'] ?? ''),
            'from_code' => TypeCoerce::toString($departure['code'] ?? ''),
            'arrival_date' => $arrivalDate,
            'arrival_time' => $arrivalTime,
            'to' => TypeCoerce::toString($arrival['name'] ?? ''),
            'to_code' => TypeCoerce::toString($arrival['code'] ?? ''),
            'airline' => implode(', ', $airlines),
            'stops' => count($segments) - 1,
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
     * @param mixed $rooms the offer's hotel rooms
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
