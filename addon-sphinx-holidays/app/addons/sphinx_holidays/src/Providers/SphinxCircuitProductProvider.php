<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Providers;

use Tygh\Addons\TravelCore\Contracts\CircuitProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDay;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\Helpers\PlainText;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Claims the products that are Sphinx circuits: a row of ?:sphinx_circuits
 * linked to the product (the add_circuit_products cron's SPXC… products),
 * still sold. Its departures come from the circuits sync: the ids (JSON)
 * and the names (", "-joined), in the same order; its hotels' stars from
 * the itinerary the sync keeps (each day's accommodation category). Its
 * page's program, inclusions and things to know come from the itinerary
 * and the details the sync keeps (itinerary_json, details_json).
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

    #[\Override]
    public function circuitDetails(string $circuitId): ?CircuitDetails
    {
        $id = TypeCoerce::toInt($circuitId);
        if ($id <= 0) {
            return null;
        }
        // Every column, not the two by name: on an install SchemaMigrator has
        // not reached yet details_json does not exist, and the page must not break.
        $row = TypeCoerce::toStringMap(db_get_row(
            "SELECT * FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active' LIMIT 1",
            $id,
        ));

        return $row === [] ? null : self::details($row['itinerary_json'] ?? null, $row['details_json'] ?? null);
    }

    /**
     * The page's program and details from what the circuits sync keeps:
     * each itinerary day (its title, its description and additional info,
     * its hotel and stars, its meals) and the details (included, not
     * included, the things to know, the group's size, the age asked). The
     * texts come as plain text: Sphinx may send HTML.
     *
     * @param mixed $itinerary the itinerary_json column (a JSON list of days)
     * @param mixed $details the details_json column (CircuitSyncService::details())
     */
    public static function details(mixed $itinerary, mixed $details): CircuitDetails
    {
        $plain = static fn (mixed $value): string => PlainText::fromHtml(TypeCoerce::toString($value));

        $days = [];
        foreach (self::jsonRows($itinerary) as $day) {
            $accommodation = TypeCoerce::toStringMap($day['accommodation'] ?? null);
            $text = trim($plain($day['description'] ?? '') . "\n\n" . $plain($day['additional_info'] ?? ''));
            $circuitDay = new CircuitDay(
                $plain($day['title'] ?? ''),
                $text,
                $plain($accommodation['hotel'] ?? ''),
                self::stars(TypeCoerce::toString($accommodation['category'] ?? '')),
                $plain(TypeCoerce::toStringMap($day['meals'] ?? null)['description'] ?? ''),
            );
            if ($circuitDay->title !== '' || $circuitDay->text !== '' || $circuitDay->hotel !== '' || $circuitDay->meals !== '') {
                $days[] = $circuitDay;
            }
        }

        $map = TypeCoerce::toStringMap(is_string($details) ? json_decode($details, true) : $details);
        // An item is its title, else its description.
        $titles = static function (mixed $items) use ($plain): array {
            $out = [];
            foreach (TypeCoerce::toRowList($items) as $item) {
                $title = $plain($item['title'] ?? '');
                $title = $title !== '' ? $title : $plain($item['description'] ?? '');
                if ($title !== '') {
                    $out[] = $title;
                }
            }

            return $out;
        };
        $notes = [];
        foreach (TypeCoerce::toRowList($map['additional_info'] ?? []) as $item) {
            $note = ['title' => $plain($item['title'] ?? ''), 'text' => $plain($item['description'] ?? '')];
            if ($note['title'] !== '' || $note['text'] !== '') {
                $notes[] = $note;
            }
        }
        $group = TypeCoerce::toStringMap($map['group_size'] ?? null);
        $age = TypeCoerce::toStringMap($map['age'] ?? null);

        return new CircuitDetails(
            $days,
            $titles($map['included'] ?? []),
            $titles($map['not_included'] ?? []),
            $notes,
            max(0, TypeCoerce::toInt($group['min'] ?? 0)),
            max(0, TypeCoerce::toInt($group['max'] ?? 0)),
            max(0, TypeCoerce::toInt($age['min'] ?? 0)),
            max(0, TypeCoerce::toInt($age['max'] ?? 0)),
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
        foreach (self::jsonRows($itinerary) as $day) {
            $count = self::stars(TypeCoerce::toString(TypeCoerce::toStringMap($day['accommodation'] ?? null)['category'] ?? ''));
            if ($count > 0) {
                $stars[] = $count;
            }
        }

        return $stars === [] ? [0, 0] : [min($stars), max($stars)];
    }

    /** A hotel category ("4", "4*") as stars, 1 to 5; 0 for anything else ("10", "4.5", "lux"). */
    private static function stars(string $category): int
    {
        return preg_match('/^\s*([1-5])(?![\d.,])/', $category, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * @param mixed $json a JSON list column (or its decoded value)
     *
     * @return list<array<string, mixed>>
     */
    private static function jsonRows(mixed $json): array
    {
        return TypeCoerce::toRowList(is_string($json) ? json_decode($json, true) : $json);
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
