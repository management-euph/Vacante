<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataService;

/**
 * The part of a book request the Sphinx doc writes the same for packages
 * and circuits (Documentation/Sphinx API.txt, "Book offer"):
 *
 *   offer_id, reference_code (the store's order), price, currency,
 *   occupancy: [{room_code, guests: [{first_name, last_name, birth_date, gender}]}].
 *
 * Sphinx checks the price and currency against its own offer, and each
 * guest's age (birth date) against the ages it priced: any mismatch fails
 * the booking. So the price and the rooms come from the offer as Sphinx
 * prices it now, when the caller could ask (PackageBookRequest,
 * CircuitBookRequest). Otherwise they come from the row: base_price, the
 * offer's pre-commission selling price as add-to-cart stored it and the
 * checkout re-check keeps it (PreOrderPriceVerifier live_prices), else
 * pricing_json's (what add-to-cart priced, with its currency and the
 * chosen services), the row's currency, and rooms_data.
 */
final class OfferBookBody
{
    /** The doc's practice for an adult whose birth date is not known: 30 years old at check-in. */
    private const int DEFAULT_ADULT_AGE = 30;

    /**
     * @param array<string, mixed> $row the sphinx_bookings row
     * @param array<string, mixed> $quotedPricing the offer's pricing as Sphinx gives it now ([] when not asked)
     * @param list<array<string, mixed>> $quotedRooms the offer's rooms as Sphinx gives them now ([] when not asked)
     * @param int $orderId the store's order, sent as the reference code
     *
     * @return array<string, mixed> offer_id, reference_code (with an order), price, currency, occupancy
     */
    public static function build(array $row, array $quotedPricing, array $quotedRooms, int $orderId): array
    {
        $stored = self::storedPricing($row);

        $price = TypeCoerce::toFloat($quotedPricing['selling_price'] ?? 0);
        if ($price <= 0) {
            $price = TypeCoerce::toFloat($row['base_price'] ?? 0);
        }
        if ($price <= 0) {
            $price = TypeCoerce::toFloat($stored['selling_price'] ?? 0);
        }
        $currency = TypeCoerce::toString($quotedPricing['currency'] ?? '');
        if ($currency === '') {
            $currency = TypeCoerce::toString($stored['currency'] ?? '');
        }
        if ($currency === '') {
            $currency = TypeCoerce::toString($row['currency'] ?? '');
        }

        $rooms = $quotedRooms !== [] ? $quotedRooms : TypeCoerce::toRowList(self::decoded($row['rooms_data'] ?? null));

        $payload = ['offer_id' => TypeCoerce::toString($row['offer_id'] ?? '')];
        if ($orderId > 0) {
            $payload['reference_code'] = (string) $orderId;
        }

        return $payload + [
            'price' => round($price, 2),
            'currency' => $currency,
            'occupancy' => self::occupancy($rooms, self::guestsByRoom($row), TypeCoerce::toString($row['check_in'] ?? '')),
        ];
    }

    /**
     * The optional services the customer chose, as add-to-cart stored them.
     *
     * @param array<string, mixed> $row the sphinx_bookings row
     *
     * @return list<string> their codes
     */
    public static function chosenServices(array $row): array
    {
        return TypeCoerce::toStringList(self::storedPricing($row)['chosen_services'] ?? []);
    }

    /**
     * One entry per room of the offer, in its order, each with the guests
     * the form gave that room. Without rooms (a row stored before), one
     * entry per room of guests, without a code.
     *
     * @param list<array<string, mixed>> $rooms the offer's rooms (code, name, adults, children_ages)
     * @param array<int, list<array<string, string>>> $guests room number (1-based) => its guests
     * @param string $checkIn Y-m-d, for an adult's default birth date
     *
     * @return list<array{room_code: string, guests: list<array<string, string>>}>
     */
    private static function occupancy(array $rooms, array $guests, string $checkIn): array
    {
        $out = [];
        foreach ($rooms as $i => $room) {
            $out[] = [
                'room_code' => TypeCoerce::toString($room['code'] ?? $room['room_code'] ?? $room['room_id'] ?? ''),
                'guests' => self::withBirthDates($guests[$i + 1] ?? [], $checkIn),
            ];
        }
        if ($out === []) {
            foreach ($guests as $roomGuests) {
                $out[] = ['room_code' => '', 'guests' => self::withBirthDates($roomGuests, $checkIn)];
            }
        }

        return $out;
    }

    /**
     * An adult without a birth date gets the doc's default: 30 years old at
     * check-in. A child's must be the real one, so it is never made up.
     *
     * @param list<array<string, string>> $guests
     * @param string $checkIn Y-m-d
     *
     * @return list<array<string, string>>
     */
    private static function withBirthDates(array $guests, string $checkIn): array
    {
        $anchor = strtotime(($checkIn !== '' ? $checkIn : 'today') . ' -' . self::DEFAULT_ADULT_AGE . ' years');
        foreach ($guests as $i => $guest) {
            if ($guest['birth_date'] === '' && ($guest['_type'] ?? '') !== 'child' && $anchor !== false) {
                $guest['birth_date'] = date('Y-m-d', $anchor);
            }
            unset($guest['_type']);
            $guests[$i] = $guest;
        }

        return $guests;
    }

    /**
     * The row's guests per room: adults first, then children, each in the
     * form's order (room{N}_adult_{i}, room{N}_child_{i}).
     *
     * @param array<string, mixed> $row the sphinx_bookings row
     *
     * @return array<int, list<array<string, string>>> room number => guests (first_name, last_name, birth_date, gender, and _type)
     */
    private static function guestsByRoom(array $row): array
    {
        $rooms = [];
        foreach (TypeCoerce::toStringMap(self::decoded($row['guests_data'] ?? null)) as $key => $entry) {
            if (!is_array($entry)) {
                continue; // not a traveller
            }
            $guest = TypeCoerce::toStringMap($entry);
            $key = (string) $key;
            $type = strtolower(TypeCoerce::toString($guest['type'] ?? '')) === 'child' || str_contains($key, 'child') ? 'child' : 'adult';
            $room = preg_match('/^room(\d+)_/', $key, $m) === 1 ? (int) $m[1] : max(1, TypeCoerce::toInt($guest['room'] ?? 1));
            $index = preg_match('/_(\d+)$/', $key, $m) === 1 ? (int) $m[1] : 0;
            $rooms[$room][] = ['sort' => [$type === 'adult' ? 0 : 1, $index], 'guest' => self::guest($guest, $type)];
        }
        ksort($rooms);

        $out = [];
        foreach ($rooms as $room => $entries) {
            usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $out[$room] = array_column($entries, 'guest');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $guest a guests_data entry (GuestDataService)
     * @param string $type adult or child
     *
     * @return array<string, string> first_name, last_name, birth_date, gender (when known), _type
     */
    private static function guest(array $guest, string $type): array
    {
        $first = trim(TypeCoerce::toString($guest['first_name'] ?? ''));
        $last = trim(TypeCoerce::toString($guest['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            // An entry stored with one name: "Pop, Ana" (the display form,
            // family name first), or "Ana Pop" (the last word is the family name).
            $full = trim(TypeCoerce::toString($guest['api_name'] ?? ''));
            $display = trim(TypeCoerce::toString($guest['name'] ?? ''));
            if ($full === '' && str_contains($display, ',')) {
                [$last, $first] = array_map('trim', explode(',', $display, 2));
            } else {
                $words = preg_split('/\s+/u', $full !== '' ? $full : $display) ?: [];
                $last = (string) array_pop($words);
                $first = implode(' ', $words);
            }
        }
        $birth = TypeCoerce::toString($guest['birthday'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) !== 1) {
            $birth = GuestDataService::parseDob(['dob' => TypeCoerce::toString($guest['dob'] ?? '')]);
        }

        $out = ['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth];
        $gender = strtolower(TypeCoerce::toString($guest['gender'] ?? ''));
        if (in_array($gender, ['m', 'f'], true)) {
            $out['gender'] = $gender;
        }

        return $out + ['_type' => $type];
    }

    /**
     * @param array<string, mixed> $row the sphinx_bookings row
     *
     * @return array<string, mixed> its pricing_json
     */
    private static function storedPricing(array $row): array
    {
        return TypeCoerce::toStringMap(self::decoded($row['pricing_json'] ?? null));
    }

    /**
     * A JSON column as the repository returns it: a string, or decoded already.
     *
     * @param mixed $value the column's value
     *
     * @return mixed the decoded value (null when it is not JSON)
     */
    private static function decoded(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
