<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Providers\SphinxCircuitProductProvider;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;

/**
 * A circuit's departure dates from one city, each with its price for 2
 * adults (commission included), from what the circuits sync saved
 * (?:travel_circuit_departures; docs/circuit-page-plan.md step 4): the
 * circuit bar's dates without asking Sphinx. The rooms and the final price
 * still come from the live quote.
 *
 * Empty when nothing is saved for the circuit and city: the caller then
 * asks Sphinx (CircuitDepartureDates), as before the table existed.
 */
final class StoredCircuitDates
{
    /**
     * @param \Closure(float): float $commission the store's selling price for a supplier price
     */
    public function __construct(
        private readonly \Closure $commission,
        private readonly CircuitDepartureRepository $departures = new CircuitDepartureRepository(),
    ) {
    }

    /**
     * @param int $departureId the city it leaves from (0: any)
     *
     * @return list<array{date: string, price: float, currency: string}> by date
     */
    public function forDeparture(int $circuitId, int $departureId, \DateTimeImmutable $today): array
    {
        if ($circuitId <= 0) {
            return [];
        }
        $byDate = [];
        foreach ($this->departures->forCircuit(SphinxCircuitProductProvider::PROVIDER, (string) $circuitId, $today->format('Y-m-d')) as $row) {
            // A row without a city leaves from any of them; one with a city, from that one only.
            if ($departureId > 0 && $row['departure_code'] !== '' && $row['departure_code'] !== (string) $departureId) {
                continue;
            }
            $date = $row['departure_date'];
            if (!isset($byDate[$date]) || $row['price'] < $byDate[$date]['price']) {
                $byDate[$date] = ['price' => $row['price'], 'currency' => $row['currency']];
            }
        }
        ksort($byDate);

        $dates = [];
        foreach ($byDate as $date => $offer) {
            $dates[] = ['date' => (string) $date, 'price' => round(($this->commission)($offer['price']), 2), 'currency' => $offer['currency']];
        }

        return $dates;
    }
}
