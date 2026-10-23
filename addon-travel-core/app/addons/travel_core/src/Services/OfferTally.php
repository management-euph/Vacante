<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * The offers one supplier answer held, per hotel
 * (docs/novoton-availability-gaps-plan.md §4.1): which stays had an offer,
 * whether any could be booked at once, and in which months. Each supplier
 * folds its own offer shape into it, so the records read the same whatever
 * the supplier; HotelAvailabilityRepository::recordCheck() and
 * recordOffersSeen() write the result.
 *
 * Only an offer that can be sold counts: on request does, a stop sale does
 * not (§15.3, point 4). The supplier decides that before calling add().
 */
final class OfferTally
{
    /** @var array<array-key, array{first: string, last: string, immediate: bool, months: array<string, true>}> */
    private array $hotels = [];

    /**
     * One sellable offer: its hotel, the stay's check-in (Y-m-d) and whether
     * it could be booked at once. An offer without a hotel or a valid date
     * is left out.
     */
    public function add(string $hotelId, string $stayDate, bool $immediate): void
    {
        if ($hotelId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $stayDate) !== 1) {
            return;
        }
        $hotel = $this->hotels[$hotelId] ?? ['first' => $stayDate, 'last' => $stayDate, 'immediate' => false, 'months' => []];
        $hotel['first'] = min($hotel['first'], $stayDate);
        $hotel['last'] = max($hotel['last'], $stayDate);
        $hotel['immediate'] = $hotel['immediate'] || $immediate;
        $hotel['months'][substr($stayDate, 0, 7)] = true;
        $this->hotels[$hotelId] = $hotel;
    }

    public function has(string $hotelId): bool
    {
        return isset($this->hotels[$hotelId]);
    }

    /** Hotels with at least one offer. */
    public function count(): int
    {
        return count($this->hotels);
    }

    /**
     * Per hotel: the latest stay with an offer (the records' stay date), the
     * earliest (the next date it can be booked), whether any could be booked
     * at once, and the stay months. Keyed by hotel id; a numeric id comes
     * back as an int key, so read the keys as strings.
     *
     * @return array<array-key, array{stay_date: string, next_date: string, immediate: bool, months: list<string>}>
     */
    public function offers(): array
    {
        $out = [];
        foreach ($this->hotels as $hotelId => $hotel) {
            $months = array_map('strval', array_keys($hotel['months']));
            sort($months);
            $out[$hotelId] = [
                'stay_date' => $hotel['last'],
                'next_date' => $hotel['first'],
                'immediate' => $hotel['immediate'],
                'months' => $months,
            ];
        }

        return $out;
    }
}
