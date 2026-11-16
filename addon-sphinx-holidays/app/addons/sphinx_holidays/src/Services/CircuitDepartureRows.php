<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

/**
 * The rows the circuits sync writes to the shared departures table
 * (Travel Core's CircuitDepartureRepository; docs/circuit-page-plan.md §3),
 * from what the rates say (BookableCircuits::departures()).
 *
 * A rate names the cities it leaves from. One that names none takes the
 * city the static list has for that circuit and day (its departures are
 * {date, destination_id}); with neither, the departure is kept without a
 * city. A city Sphinx sends without a name takes the static destination's.
 */
final class CircuitDepartureRows
{
    /**
     * @param array<int, list<array{code: string, name: string, date: string, price: float, currency: string}>> $departures by circuit id (BookableCircuits::departures())
     * @param list<int> $bookable the circuits on sale: only theirs are written
     * @param array<int, array<string, int>> $staticCities circuit id => Y-m-d => destination id, for the circuits read this run (CircuitStaticContent::departureCityByDate())
     * @param array<int, string> $cityNames destination id => name
     *
     * @return list<array{circuit_id: string, departure_code: string, departure_name: string, departure_date: string, price: float, currency: string}>
     */
    public static function build(array $departures, array $bookable, array $staticCities, array $cityNames): array
    {
        $rows = [];
        foreach ($bookable as $circuitId) {
            foreach ($departures[$circuitId] ?? [] as $departure) {
                $cityId = self::cityId($departure, $staticCities[$circuitId] ?? []);
                $rows[] = [
                    'circuit_id' => (string) $circuitId,
                    'departure_code' => $cityId > 0 ? (string) $cityId : '',
                    'departure_name' => $departure['name'] !== '' ? $departure['name'] : ($cityNames[$cityId] ?? ''),
                    'departure_date' => $departure['date'],
                    'price' => $departure['price'],
                    'currency' => $departure['currency'],
                ];
            }
        }

        return $rows;
    }

    /**
     * The cities build() has no name for from the rates: those the static
     * list stands in for, and those a rate names by id only.
     *
     * @param array<int, list<array{code: string, name: string, date: string, price: float, currency: string}>> $departures
     * @param list<int> $bookable
     * @param array<int, array<string, int>> $staticCities
     *
     * @return list<int> destination ids, each once
     */
    public static function unnamedCities(array $departures, array $bookable, array $staticCities): array
    {
        $ids = [];
        foreach ($bookable as $circuitId) {
            foreach ($departures[$circuitId] ?? [] as $departure) {
                $cityId = self::cityId($departure, $staticCities[$circuitId] ?? []);
                if ($cityId > 0 && $departure['name'] === '') {
                    $ids[$cityId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * The city a departure leaves from: the rate's, else the static list's
     * for that day; 0 when neither says.
     *
     * @param array{code: string, name: string, date: string, price: float, currency: string} $departure
     * @param array<string, int> $staticByDate Y-m-d => destination id
     */
    private static function cityId(array $departure, array $staticByDate): int
    {
        return $departure['code'] !== '' ? (int) $departure['code'] : ($staticByDate[$departure['date']] ?? 0);
    }
}
