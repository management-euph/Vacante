<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * What the Destination whitelist page reads (Services\DestinationsPicker):
 * every country with its continent and size, the tree of the countries it
 * opens, and what selling a destination means — hotels, live products,
 * circuits. Grouped queries: the page never walks the tree one node at a time.
 */
class WhitelistPickerRepository
{
    use RowNarrowingTrait;

    /**
     * @return list<array{destination_id: int, name: string, country_code: string, continent: string}>
     */
    public function countries(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT c.destination_id, c.name, c.country_code, COALESCE(p.name, '') AS continent
             FROM ?:sphinx_destinations c
             LEFT JOIN ?:sphinx_destinations p ON p.destination_id = c.parent_id AND p.type = 'continent'
             WHERE c.type = 'country' AND c.country_code IS NOT NULL AND c.country_code != ''
             ORDER BY c.name",
        )) as $row) {
            $out[] = [
                'destination_id' => TypeCoerce::toInt($row['destination_id'] ?? 0),
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'country_code' => strtoupper(TypeCoerce::toString($row['country_code'] ?? '')),
                'continent' => TypeCoerce::toString($row['continent'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Per country: its direct children (the regions) and theirs (the cities),
     * the two levels the page lists.
     *
     * @return array<string, array{groups: int, items: int}>
     */
    public function structure(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT c.country_code, COUNT(DISTINCT r.destination_id) AS groups_n, COUNT(ct.destination_id) AS items_n
             FROM ?:sphinx_destinations c
             JOIN ?:sphinx_destinations r ON r.parent_id = c.destination_id
             LEFT JOIN ?:sphinx_destinations ct ON ct.parent_id = r.destination_id
             WHERE c.type = 'country'
             GROUP BY c.country_code",
        )) as $row) {
            $out[strtoupper(TypeCoerce::toString($row['country_code'] ?? ''))] = [
                'groups' => TypeCoerce::toInt($row['groups_n'] ?? 0),
                'items' => TypeCoerce::toInt($row['items_n'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Hotels and live products per country.
     *
     * @return array<string, array{hotels: int, live: int}>
     */
    public function hotelTotals(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT h.country_code, COUNT(*) AS hotels, SUM(CASE WHEN p.status = 'A' THEN 1 ELSE 0 END) AS live
             FROM ?:sphinx_hotels h
             LEFT JOIN ?:products p ON p.product_id = h.product_id AND h.product_id > 0
             WHERE h.country_code IS NOT NULL AND h.country_code != ''
             GROUP BY h.country_code",
        )) as $row) {
            $out[strtoupper(TypeCoerce::toString($row['country_code'] ?? ''))] = [
                'hotels' => TypeCoerce::toInt($row['hotels'] ?? 0),
                'live' => TypeCoerce::toInt($row['live'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Every destination of the given countries (all levels: the page lists
     * two, the figures roll up from below them).
     *
     * @param list<string> $countryCodes
     * @return list<array{destination_id: int, parent_id: int, name: string, type: string, country_code: string, first_seen_at: string}>
     */
    public function tree(array $countryCodes): array
    {
        if ($countryCodes === []) {
            return [];
        }
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT destination_id, parent_id, name, type, country_code, first_seen_at
             FROM ?:sphinx_destinations WHERE country_code IN (?a) AND type != 'country' ORDER BY name",
            $countryCodes,
        )) as $row) {
            $out[] = [
                'destination_id' => TypeCoerce::toInt($row['destination_id'] ?? 0),
                'parent_id' => TypeCoerce::toInt($row['parent_id'] ?? 0),
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'type' => TypeCoerce::toString($row['type'] ?? ''),
                'country_code' => strtoupper(TypeCoerce::toString($row['country_code'] ?? '')),
                'first_seen_at' => TypeCoerce::toString($row['first_seen_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Hotels and live products per hotel destination, for some countries.
     *
     * @param list<string> $countryCodes
     * @return array<int, array{hotels: int, live: int}>
     */
    public function hotelsByDestination(array $countryCodes): array
    {
        if ($countryCodes === []) {
            return [];
        }
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT h.destination_id, COUNT(*) AS hotels, SUM(CASE WHEN p.status = 'A' THEN 1 ELSE 0 END) AS live
             FROM ?:sphinx_hotels h
             LEFT JOIN ?:products p ON p.product_id = h.product_id AND h.product_id > 0
             WHERE h.country_code IN (?a)
             GROUP BY h.destination_id",
            $countryCodes,
        )) as $row) {
            $out[TypeCoerce::toInt($row['destination_id'] ?? 0)] = [
                'hotels' => TypeCoerce::toInt($row['hotels'] ?? 0),
                'live' => TypeCoerce::toInt($row['live'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Every circuit with its destinations and whether its product is live.
     *
     * @return list<array{circuit_id: int, name: string, product_id: int, live: bool, destination_ids: list<int>}>
     */
    public function circuits(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            'SELECT c.circuit_id, c.name, c.product_id, c.destination_ids, p.status
             FROM ?:sphinx_circuits c
             LEFT JOIN ?:products p ON p.product_id = c.product_id AND c.product_id > 0',
        )) as $row) {
            $ids = json_decode(TypeCoerce::toString($row['destination_ids'] ?? '[]'), true);
            $out[] = [
                'circuit_id' => TypeCoerce::toInt($row['circuit_id'] ?? 0),
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'product_id' => TypeCoerce::toInt($row['product_id'] ?? 0),
                'live' => TypeCoerce::toString($row['status'] ?? '') === 'A',
                'destination_ids' => TypeCoerce::toIntList(is_array($ids) ? array_values($ids) : []),
            ];
        }

        return $out;
    }

    /**
     * Live hotel products, with the hotel's destination and country.
     *
     * @return list<array{product_id: int, destination_id: int, place: string}>
     */
    public function liveHotels(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT h.product_id, h.destination_id, h.destination_name, h.country_name
             FROM ?:sphinx_hotels h
             INNER JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.product_id > 0 AND p.status = 'A'",
        )) as $row) {
            $place = trim(TypeCoerce::toString($row['destination_name'] ?? ''));
            $country = trim(TypeCoerce::toString($row['country_name'] ?? ''));
            $out[] = [
                'product_id' => TypeCoerce::toInt($row['product_id'] ?? 0),
                'destination_id' => TypeCoerce::toInt($row['destination_id'] ?? 0),
                'place' => $place . ($place !== '' && $country !== '' ? ', ' : '') . $country,
            ];
        }

        return $out;
    }

    /**
     * Package routes arriving in the given countries (they follow the
     * arrival country, not the city).
     *
     * @param list<string> $countryCodes
     */
    public function routesInto(array $countryCodes): int
    {
        if ($countryCodes === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_package_routes r
             JOIN ?:sphinx_destinations d ON d.destination_id = r.arrival_id
             WHERE d.country_code IN (?a)',
            $countryCodes,
        ));
    }

    /**
     * Set products to Disabled ('D'). Never deletes: a product disabled by
     * mistake comes back with its URL, reviews and images by enabling it.
     *
     * @param list<int> $productIds
     */
    public function disableProducts(array $productIds): int
    {
        $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id > 0));
        if ($productIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:products SET status = 'D' WHERE product_id IN (?n) AND status = 'A'",
            $productIds,
        ));
    }
}
