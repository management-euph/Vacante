<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;

/**
 * The travellers a booking form posted, as both Eurosite forms (a stay's and
 * a package's) read them: the shared guest cards' fields
 * (guests[room{N}_{type}_{i}][…]) turned into what the booking row keeps and
 * AddBookingRequest sends — the name as Eurosite writes it ("LAST / First"),
 * PaxType, TGender (B male, F female, C a child), the date of birth and the
 * room.
 *
 * Free of CS-Cart, so every rule is unit-tested: whether a card was left
 * incomplete, whether each room holds the travellers it was priced for, and
 * whether a child's date of birth agrees with the age the offer was priced
 * for.
 */
final class BookingGuests
{
    /**
     * @param mixed $posted $_POST['guests'], whatever arrived
     *
     * @return array{guests: list<array<string, mixed>>, holder: string, invalid: bool} invalid: a card
     *                                                                                  was left incomplete (a name missing, a date that is not one, a child without one)
     */
    public static function parse(mixed $posted): array
    {
        $guests = [];
        $holder = '';
        foreach (is_array($posted) ? $posted : [] as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            $first = trim(TypeCoerce::toString($guest['first_name'] ?? ''));
            $last = trim(TypeCoerce::toString($guest['last_name'] ?? ''));
            $type = TypeCoerce::toString($guest['type'] ?? 'adult') === 'child' ? 'child' : 'adult';
            // The shared guest cards mask the date as DD/MM/YYYY; Eurosite wants Y-m-d.
            $rawDob = trim(TypeCoerce::toString($guest['dob'] ?? ''));
            $dob = $rawDob !== '' ? (DateHelper::parseDate($rawDob) ?? '') : '';
            if ($first === '' || $last === '' || ($rawDob !== '' && $dob === '') || ($type === 'child' && $dob === '')) {
                return ['guests' => [], 'holder' => '', 'invalid' => true];
            }
            $gender = strtoupper(trim(TypeCoerce::toString($guest['gender'] ?? '')));
            $entry = [
                'type' => $type,
                'first_name' => $first,
                'last_name' => $last,
                'name' => $last . ' / ' . $first,
                'gender' => $type === 'child' ? 'C' : (in_array($gender, ['B', 'F'], true) ? $gender : 'B'),
                'dob' => $dob,
                'room' => max(1, TypeCoerce::toInt($guest['room'] ?? 1)),
            ];
            if ($type === 'child') {
                $entry['age'] = TypeCoerce::toInt($guest['age'] ?? 0);
            }
            if (!empty($guest['is_holder']) || $holder === '') {
                $holder = $entry['name'];
            }
            $guests[] = $entry;
        }

        return ['guests' => $guests, 'holder' => $holder, 'invalid' => false];
    }

    /**
     * Whether every room holds exactly the adults and children it was priced
     * for.
     *
     * @param list<array<string, mixed>> $guests parse()'s
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     */
    public static function roomsMatch(array $guests, array $occupancy): bool
    {
        $perRoom = [];
        foreach ($guests as $guest) {
            $room = TypeCoerce::toInt($guest['room'] ?? 1);
            $type = TypeCoerce::toString($guest['type'] ?? 'adult');
            $perRoom[$room][$type] = ($perRoom[$room][$type] ?? 0) + 1;
        }
        if (count($perRoom) !== count($occupancy)) {
            return false;
        }
        foreach ($occupancy as $i => $room) {
            $got = $perRoom[$i + 1] ?? [];
            if (($got['adult'] ?? 0) !== $room['adults'] || ($got['child'] ?? 0) !== count($room['children_ages'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first child whose date of birth gives another age at check-in than
     * the offer was priced for, and the rooms with every child's real age
     * (the search runs again with those instead of booking a wrong price).
     *
     * Calendar dates compared in one timezone, so a birthday on the check-in
     * day counts.
     *
     * @param list<array<string, mixed>> $guests parse()'s
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy
     *
     * @return array{mismatch: array{name: string, declared: int, actual: int}|null, corrected: list<array{adults: int, children_ages: list<int>}>}
     */
    public static function ageCheck(array $guests, string $checkIn, array $occupancy): array
    {
        $checkInDate = DateHelper::parseDate($checkIn);
        $mismatch = null;
        $corrected = array_map(
            static fn (array $room): array => ['adults' => $room['adults'], 'children_ages' => []],
            $occupancy,
        );
        foreach ($guests as $guest) {
            if (TypeCoerce::toString($guest['type'] ?? '') !== 'child') {
                continue;
            }
            $declared = TypeCoerce::toInt($guest['age'] ?? 0);
            $dob = TypeCoerce::toString($guest['dob'] ?? '');
            $actual = $checkInDate !== null && $dob !== ''
                ? (new \DateTimeImmutable($dob))->diff(new \DateTimeImmutable($checkInDate))->y
                : $declared;
            $room = TypeCoerce::toInt($guest['room'] ?? 1) - 1;
            if (isset($corrected[$room])) {
                $corrected[$room]['children_ages'][] = $actual;
            }
            if ($mismatch === null && $actual !== $declared) {
                $mismatch = ['name' => TypeCoerce::toString($guest['name'] ?? ''), 'declared' => $declared, 'actual' => $actual];
            }
        }

        return ['mismatch' => $mismatch, 'corrected' => $corrected];
    }
}
