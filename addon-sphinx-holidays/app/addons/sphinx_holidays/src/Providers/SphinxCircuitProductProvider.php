<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Providers;

use Tygh\Addons\TravelCore\Contracts\CircuitProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Claims the products that are Sphinx circuits: a row of ?:sphinx_circuits
 * linked to the product (the add_circuit_products cron's SPXC… products),
 * still sold. Its departures come from the circuits sync: the ids (JSON)
 * and the names (", "-joined), in the same order; its hotels' stars from
 * the itinerary the sync keeps (each day's accommodation category).
 */
final class SphinxCircuitProductProvider implements CircuitProductProviderInterface
{
    #[\Override]
    public function resolveCircuit(int $productId, string $productCode): ?CircuitPage
    {
        if ($productId <= 0) {
            return null;
        }
        $row = TypeCoerce::toStringMap(db_get_row(
            "SELECT circuit_id, name, departure_ids, departure_names, duration_days, duration_nights, itinerary_json
             FROM ?:sphinx_circuits WHERE product_id = ?i AND sync_status = 'active' LIMIT 1",
            $productId,
        ));
        $circuitId = TypeCoerce::toInt($row['circuit_id'] ?? 0);
        if ($circuitId <= 0) {
            return null;
        }

        [$starsMin, $starsMax] = self::hotelStars($row['itinerary_json'] ?? null);

        return new CircuitPage(
            'sphinx',
            (string) $circuitId,
            TypeCoerce::toString($row['name'] ?? ''),
            self::departures($row['departure_ids'] ?? null, TypeCoerce::toString($row['departure_names'] ?? '')),
            TypeCoerce::toInt($row['duration_days'] ?? 0),
            TypeCoerce::toInt($row['duration_nights'] ?? 0),
            $starsMin,
            $starsMax,
        );
    }

    /**
     * The fewest and the most stars of the hotels on the route: each
     * itinerary day's accommodation category ("4"), 1 to 5; [0, 0] when no
     * day says.
     *
     * @param mixed $itinerary the itinerary_json column (a JSON list of days)
     *
     * @return array{0: int, 1: int}
     */
    public static function hotelStars(mixed $itinerary): array
    {
        $stars = [];
        foreach (TypeCoerce::toRowList(is_string($itinerary) ? json_decode($itinerary, true) : $itinerary) as $day) {
            $category = TypeCoerce::toString(TypeCoerce::toStringMap($day['accommodation'] ?? null)['category'] ?? '');
            if (preg_match('/^\s*([1-5])(?![\d.,])/', $category, $m) === 1) {
                $stars[] = (int) $m[1];
            }
        }

        return $stars === [] ? [0, 0] : [min($stars), max($stars)];
    }

    /**
     * The departures, each id with its name. The names are paired by
     * position when there are as many as the ids; otherwise (a name with
     * ", " in it) a lone id keeps the whole text and several get none, the
     * bar then naming them by number.
     *
     * @param mixed $ids the departure_ids column (a JSON list)
     * @param string $names the departure_names column (", "-joined)
     *
     * @return list<array{id: string, name: string}>
     */
    public static function departures(mixed $ids, string $names): array
    {
        $idList = [];
        foreach (TypeCoerce::toList(is_string($ids) ? json_decode($ids, true) : $ids) as $id) {
            $id = TypeCoerce::toString($id);
            if ($id !== '' && $id !== '0') {
                $idList[] = $id;
            }
        }
        $nameList = $names !== '' ? explode(', ', $names) : [];
        if (count($nameList) !== count($idList)) {
            $nameList = count($idList) === 1 ? [$names] : [];
        }

        $departures = [];
        foreach ($idList as $i => $id) {
            $departures[] = ['id' => $id, 'name' => trim($nameList[$i] ?? '')];
        }

        return $departures;
    }
}
