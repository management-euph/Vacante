<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\Dto\PackageOffer;

/**
 * "Camere și prețuri" on a Eurosite hotel's page in a package mode (board
 * 16.2, Travel Core's PackageRooms.jsx): the packages Eurosite offers for
 * this hotel from one departure city, on one date, for so many nights, with
 * the customer's rooms. The answer has the shape Sphinx's quote has
 * (packageModes.js): blocks by the way they travel, the offers in each,
 * cheapest first in a block and between the blocks.
 *
 * getPackageNVPriceRequest is asked for the hotel's city and operator, with a
 * one-day margin as the spec asks; its answer lists the city's hotels, and
 * only this one's offers are kept. A stop sale, a hidden offer or one without
 * the ids a booking sends is left out.
 *
 * The price is the offer's ProductPrice, in its currency, as Eurosite's
 * hotel offers are priced (DesignARates, add_to_cart). "Rezervă" carries a
 * signed PackageOfferToken; the booking form asks Eurosite again.
 *
 * @phpstan-import-type PackageService from PackageOffer
 *
 * @phpstan-type Way array{departure_date: string, departure_time: string, from: string, from_code: string, arrival_date: string, arrival_time: string, to: string, to_code: string, airline: string, stops: int}
 * @phpstan-type Block array{transport: string, flight: array{outbound: Way|null, inbound: Way|null}|null, check_in: string, check_out: string, offers: list<array{offer_id: string, rooms: list<array{name: string, count: int}>, meal: string, confirmation: string, price: float, currency: string}>}
 */
final class PackageQuote
{
    public function __construct(
        private readonly EurositeApiClient $api,
        private readonly PackageOfferToken $tokens,
        private readonly string $roomOnly = '',
    ) {
    }

    /**
     * @param array{product_code: string, tourop_code: string, country_code: string, city_code: string} $hotel
     * @param array{code: string, country_code: string} $departure PackageDepartures::offered()
     * @param string $transport the page's: "flight" or "bus"
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     *
     * @return list<Block>
     */
    public function blocks(array $hotel, array $departure, string $transport, string $date, int $nights, array $occupancy, string $currency, string $language): array
    {
        $eurositeTransport = $transport === 'bus' ? 'bus' : 'plane';
        $offers = $this->api->searchPackages([
            'country_code' => $hotel['country_code'],
            'city_code' => $hotel['city_code'],
            'dep_country_code' => $departure['country_code'],
            'dep_city_code' => $departure['code'],
            'transport' => $eurositeTransport,
            'tourop_code' => $hotel['tourop_code'],
            'currency' => $currency,
            'language' => $language,
            'check_in' => $date,
            'check_out' => self::checkOut($date, $nights),
            'days' => 1,
            'rooms' => RoomOccupancy::searchPayload($occupancy),
        ]);

        /** @var array<string, Block> $blocks */
        $blocks = [];
        foreach ($offers as $offer) {
            if (!self::belongs($offer, $hotel['product_code'])) {
                continue;
            }
            $stay = $offer->hotel;
            $legs = $offer->legs();
            $flight = $transport === 'flight' ? self::flight($legs, $stay->checkIn, $stay->checkOut) : null;
            $key = (string) json_encode([$flight, array_column($legs, 'code'), $stay->checkIn, $stay->checkOut]);
            $blocks[$key] ??= [
                'transport' => $transport,
                'flight' => $flight,
                'check_in' => $stay->checkIn,
                'check_out' => $stay->checkOut,
                'offers' => [],
            ];
            $blocks[$key]['offers'][] = [
                'offer_id' => $this->tokens->encode([
                    'product_code' => $stay->productCode,
                    'tourop_code' => $offer->tourOpCode !== '' ? $offer->tourOpCode : $hotel['tourop_code'],
                    'country_code' => $hotel['country_code'],
                    'city_code' => $hotel['city_code'],
                    'dep_country_code' => $departure['country_code'],
                    'dep_city_code' => $departure['code'],
                    'transport' => $eurositeTransport,
                    'departure_date' => $date,
                    'nights' => (string) $nights,
                    'package_id' => $offer->packageId,
                    'variant_id' => $offer->variantId(),
                    'room_codes' => implode(',', array_map(static fn (array $room): string => $room['code'] ?? '', $stay->rooms)),
                    'check_in' => $stay->checkIn,
                    'check_out' => $stay->checkOut,
                    'price' => (string) round($stay->price, 2),
                    'currency' => $stay->currency,
                ]),
                'rooms' => self::rooms($offer),
                'meal' => self::meal($offer, $this->roomOnly),
                'confirmation' => $stay->availabilityCode === 'IM' ? 'immediate' : 'on_request',
                'price' => round($stay->price, 2),
                'currency' => $stay->currency,
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

    /** The guest picker's limits per room (PackageBar.jsx): adults, then children. */
    public const int MAX_ADULTS = 9;

    public const int MAX_CHILDREN = 4;

    /**
     * Whether the rooms are ones the guest picker could have sent: 1 to
     * $maxRooms rooms, each with 1 to 9 adults and up to 4 children aged 0
     * to 17. Anything else is not sent to Eurosite.
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
                if ($age < 0 || $age > 17) {
                    return false;
                }
            }
        }

        return true;
    }

    /** The searched check-out: the departure date and its nights. */
    public static function checkOut(string $date, int $nights): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $day === false ? '' : $day->modify('+' . max(1, $nights) . ' days')->format('Y-m-d');
    }

    /**
     * The flight as the page shows it: the way there is the journeys before
     * the middle of the stay, the way back those after; each from its first
     * leg's departure to its last leg's arrival, at the times Eurosite gives.
     * Null without any journey.
     *
     * @param list<PackageService> $legs PackageOffer::legs(), earliest first
     *
     * @return array{outbound: Way|null, inbound: Way|null}|null
     */
    public static function flight(array $legs, string $checkIn, string $checkOut): ?array
    {
        if ($legs === []) {
            return null;
        }
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);
        $middle = $in !== false && $out !== false && $out > $in ? date('Y-m-d H:i:s', intdiv($in + $out, 2)) : '';
        $there = [];
        $back = [];
        foreach ($legs as $i => $leg) {
            $outbound = $middle !== '' ? $leg['start'] < $middle : $i === 0;
            if ($outbound) {
                $there[] = $leg;
            } else {
                $back[] = $leg;
            }
        }
        $outbound = self::way($there);
        $inbound = self::way($back);

        return $outbound === null && $inbound === null ? null : ['outbound' => $outbound, 'inbound' => $inbound];
    }

    /**
     * "Transport Avion OTOPENI - ANTALYA , vineri Bucuresti - Antalya" →
     * ["Otopeni", "Antalya"]: the two places before the first comma; ['', '']
     * when the name does not read so.
     *
     * @return array{0: string, 1: string}
     */
    public static function places(string $name): array
    {
        $route = trim(explode(',', $name, 2)[0]);
        $route = trim((string) preg_replace('/^transport\s+(?:avion|aerian|autocar|autobuz|bus)\s+/iu', '', $route));
        $parts = preg_split('/\s+-\s+/u', $route);
        if (!is_array($parts) || count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return ['', ''];
        }

        return [self::title($parts[0]), self::title($parts[1])];
    }

    private static function belongs(PackageOffer $offer, string $productCode): bool
    {
        return strcasecmp($offer->hotel->productCode, $productCode) === 0
            && $offer->visible
            && $offer->isBookable()
            && $offer->hotel->price > 0;
    }

    /**
     * @param list<PackageService> $legs
     *
     * @return Way|null
     */
    private static function way(array $legs): ?array
    {
        if ($legs === []) {
            return null;
        }
        $first = $legs[0];
        $last = $legs[count($legs) - 1];
        [$departureDate, $departureTime] = self::dateTime($first['start']);
        if ($departureDate === '') {
            return null;
        }
        [$arrivalDate, $arrivalTime] = self::dateTime($last['end']);

        return [
            'departure_date' => $departureDate,
            'departure_time' => $departureTime,
            'from' => self::places($first['name'])[0],
            'from_code' => '',
            'arrival_date' => $arrivalDate,
            'arrival_time' => $arrivalTime,
            'to' => self::places($last['name'])[1],
            'to_code' => '',
            'airline' => '',
            'stops' => count($legs) - 1,
        ];
    }

    /**
     * "2012-09-21 07:05:00" → ["2012-09-21", "07:05"]; a date alone has no
     * time; ['', ''] when it is neither.
     *
     * @return array{0: string, 1: string}
     */
    private static function dateTime(string $value): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $value, $m) !== 1) {
            return ['', ''];
        }

        return [$m[1], $m[2] ?? ''];
    }

    /** "OTOPENI" → "Otopeni"; a name already in mixed case stays as written. */
    private static function title(string $place): string
    {
        $place = trim($place);

        return $place === mb_strtoupper($place) ? mb_convert_case(mb_strtolower($place), MB_CASE_TITLE) : $place;
    }

    /**
     * The offer's rooms by name, a name met twice counted twice.
     *
     * @return list<array{name: string, count: int}>
     */
    private static function rooms(PackageOffer $offer): array
    {
        $counts = [];
        foreach ($offer->hotel->rooms as $room) {
            $name = trim((string) preg_replace('/\s+/', ' ', $room['name'] ?? ''));
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + max(1, (int) ($room['quantity'] ?? 1));
            }
        }
        $out = [];
        foreach ($counts as $name => $count) {
            $out[] = ['name' => (string) $name, 'count' => $count];
        }

        return $out;
    }

    /** The board, as the store names it; the room-only label without one. */
    private static function meal(PackageOffer $offer, string $roomOnly): string
    {
        foreach ($offer->hotel->meals as $meal) {
            $label = trim($meal['label'] ?? '');
            if ($label !== '') {
                return $label;
            }
        }

        return $roomOnly;
    }
}
