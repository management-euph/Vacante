<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Circuit repository — wraps sphinx_circuits table.
 *
 * @since 1.2.0
 */
class CircuitRepository
{
    public function exists(int $circuitId): bool
    {
        return (bool) db_get_field(
            'SELECT circuit_id FROM ?:sphinx_circuits WHERE circuit_id = ?i',
            $circuitId,
        );
    }

    /**
     * Timestamp of the most recently synced circuit, or null if none synced yet.
     * Drives incremental (updated_since) pulls — mirrors DestinationRepository.
     */
    public function getLastSyncedAt(): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT MAX(last_synced_at) FROM ?:sphinx_circuits',
        ));

        return $val === '' ? null : $val;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $circuitId, array $data): void
    {
        db_query('UPDATE ?:sphinx_circuits SET ?u WHERE circuit_id = ?i', $data, $circuitId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        db_query('INSERT INTO ?:sphinx_circuits ?e', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(int $circuitId, array $data): void
    {
        if ($this->exists($circuitId)) {
            $this->update($circuitId, $data);
        } else {
            $data['circuit_id'] = $circuitId;
            $this->insert($data);
        }
    }

    /**
     * What the rates say of each circuit (BookableCircuits::facts()): the
     * cities it leaves from and the months it still leaves in. The static
     * list has neither, so the circuits sync writes them here.
     *
     * @param array<int, array{months: list<string>, departures: array<int, string>}> $facts by circuit id
     */
    public function saveDepartureFacts(array $facts): void
    {
        foreach ($facts as $circuitId => $fact) {
            $this->update((int) $circuitId, [
                'departure_ids' => $fact['departures'] !== [] ? json_encode(array_keys($fact['departures'])) : null,
                'departure_names' => $fact['departures'] !== [] ? implode(', ', $fact['departures']) : null,
                'departure_months' => $fact['months'] !== [] ? json_encode($fact['months']) : null,
            ]);
        }
    }

    /**
     * The active circuits that have a product, with what their product's
     * features are made of (CircuitFeatures).
     *
     * @return list<array<string, mixed>>
     */
    public function findLinkedForFeatures(): array
    {
        return TypeCoerce::toRowList(db_get_array(
            "SELECT circuit_id, product_id, transport_type, duration_nights, destination_ids,
                    departure_names, departure_months, tags_json
             FROM ?:sphinx_circuits
             WHERE product_id > 0 AND sync_status = 'active'
             ORDER BY circuit_id",
        ));
    }

    /**
     * One linked circuit's row for its features; [] when it has no product.
     *
     * @return array<string, mixed>
     */
    public function findForFeatures(int $circuitId): array
    {
        return TypeCoerce::toStringMap(db_get_row(
            'SELECT circuit_id, product_id, transport_type, duration_nights, destination_ids,
                    departure_names, departure_months, tags_json
             FROM ?:sphinx_circuits WHERE circuit_id = ?i',
            $circuitId,
        ));
    }

    /**
     * The countries of a circuit's destinations: each destination of the
     * static tree rolled up to its country, by name, each once.
     *
     * @param list<int> $destinationIds
     *
     * @return list<string>
     */
    public function countryNames(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        return array_values(array_unique(array_filter(TypeCoerce::toStringList(db_get_fields(
            "SELECT DISTINCT c.name FROM ?:sphinx_destinations d
             JOIN ?:sphinx_destinations c ON c.country_code = d.country_code AND c.type = 'country'
             WHERE d.destination_id IN (?n) ORDER BY c.name",
            $destinationIds,
        )))));
    }

    /** Total number of stored circuits (for the grid's freshness line). */
    public function countAll(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_circuits'));
    }

    /**
     * Active circuits with no CS-Cart product yet — the add_circuit_products
     * work queue. Resumable by construction: linked rows drop out.
     *
     * @return list<array<string, mixed>>
     */
    public function findUnlinked(int $limit = 200): array
    {
        return TypeCoerce::toRowList(db_get_array(
            "SELECT circuit_id, name, summary, description, duration_days, duration_nights,
                    transport_type, destination_names, image_url, min_price, currency
             FROM ?:sphinx_circuits
             WHERE (product_id IS NULL OR product_id = 0) AND sync_status = 'active'
             ORDER BY circuit_id
             LIMIT ?i",
            $limit,
        ));
    }

    /**
     * Every stored circuit's id.
     *
     * @return list<int>
     */
    public function allIds(): array
    {
        return TypeCoerce::toIntList(db_get_fields('SELECT circuit_id FROM ?:sphinx_circuits'));
    }

    /**
     * The stored circuits Sphinx no longer prices from today on (not in
     * $bookable, BookableCircuits) leave the list: removed, or set inactive
     * when a product was made for one (the product keeps its link, so a
     * circuit priced again gets the same product, never a second one).
     *
     * An inactive circuit's product is hidden (H: off the lists and the
     * search; its address and orders stay) when it was on sale (A), and
     * marked product_hidden. A circuit priced again is active again, and
     * its product on sale again only when this sync hid it and it is still
     * hidden: a product an admin hid, or changed since, is left as it is.
     *
     * @param list<int> $bookable never empty: the caller keeps everything on an unknown answer
     * @return array{removed: int, deactivated: int, hidden: int, shown: int}
     */
    public function pruneUnbookable(array $bookable): array
    {
        if ($bookable === []) {
            return ['removed' => 0, 'deactivated' => 0, 'hidden' => 0, 'shown' => 0];
        }
        $removed = db_query(
            'DELETE FROM ?:sphinx_circuits WHERE circuit_id NOT IN (?n) AND (product_id IS NULL OR product_id = 0)',
            $bookable,
        );
        $hidden = db_query(
            "UPDATE ?:products p INNER JOIN ?:sphinx_circuits c ON c.product_id = p.product_id
             SET p.status = 'H', c.product_hidden = 'Y'
             WHERE c.circuit_id NOT IN (?n) AND c.sync_status = 'active' AND p.status = 'A'",
            $bookable,
        );
        $deactivated = db_query(
            "UPDATE ?:sphinx_circuits SET sync_status = 'inactive' WHERE circuit_id NOT IN (?n) AND product_id > 0 AND sync_status = 'active'",
            $bookable,
        );
        $shown = db_query(
            "UPDATE ?:products p INNER JOIN ?:sphinx_circuits c ON c.product_id = p.product_id
             SET p.status = 'A'
             WHERE c.circuit_id IN (?n) AND c.product_hidden = 'Y' AND p.status = 'H'",
            $bookable,
        );
        db_query(
            "UPDATE ?:sphinx_circuits SET sync_status = 'active', product_hidden = 'N'
             WHERE circuit_id IN (?n) AND (sync_status = 'inactive' OR product_hidden = 'Y')",
            $bookable,
        );

        // A multi-table UPDATE counts the rows of both tables it changes.
        return [
            'removed' => TypeCoerce::toInt($removed),
            'deactivated' => TypeCoerce::toInt($deactivated),
            'hidden' => intdiv(TypeCoerce::toInt($hidden), 2),
            'shown' => TypeCoerce::toInt($shown),
        ];
    }

    /**
     * The circuit of a deleted product loses its link (and the sync's
     * hidden mark, which was the product's): the next "Create Products"
     * makes it a new one.
     */
    public function unlinkProduct(int $productId): int
    {
        return TypeCoerce::toInt(db_query(
            "UPDATE ?:sphinx_circuits SET product_id = NULL, product_hidden = 'N' WHERE product_id = ?i",
            $productId,
        ));
    }

    /**
     * Circuits linked to a product that no longer exists (deleted before
     * the delete hook unlinked them, or straight from the database) are
     * unlinked, as unlinkProduct() does: how many.
     */
    public function unlinkMissingProducts(): int
    {
        return TypeCoerce::toInt(db_query(
            "UPDATE ?:sphinx_circuits c LEFT JOIN ?:products p ON p.product_id = c.product_id
             SET c.product_id = NULL, c.product_hidden = 'N'
             WHERE c.product_id > 0 AND p.product_id IS NULL",
        ));
    }

    /** Write the CS-Cart product link (makes the circuit sellable). */
    public function linkToProduct(int $circuitId, int $productId): void
    {
        db_query(
            'UPDATE ?:sphinx_circuits SET product_id = ?i WHERE circuit_id = ?i',
            $productId,
            $circuitId,
        );
    }

    /**
     * Paginated, sortable, filterable circuit listing for the admin grid.
     * Returns [$rows, $params] where $params carries total_items / page /
     * items_per_page — the CS-Cart pagination.tpl contract (mirrors
     * fn_sphinx_holidays_get_hotels).
     *
     * @param array<string, mixed> $params
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function getListing(array $params = []): array
    {
        $default_params = [
            'page' => 1,
            'items_per_page' => 50,
            'sort_by' => 'name',
            'sort_order' => 'asc',
            'transport_type' => '',
            'sync_status' => '',
            'q' => '',
        ];

        $params = array_merge($default_params, array_intersect_key($params, $default_params));
        $params['page'] = max(1, TypeCoerce::toInt($params['page']));
        $params['items_per_page'] = max(1, TypeCoerce::toInt($params['items_per_page']));
        $params['transport_type'] = trim(TypeCoerce::toString($params['transport_type']));
        $params['sync_status'] = trim(TypeCoerce::toString($params['sync_status']));
        $params['q'] = trim(TypeCoerce::toString($params['q']));

        $sortings = [
            'circuit_id' => 'c.circuit_id',
            'name' => 'c.name',
            'transport_type' => 'c.transport_type',
            'duration_nights' => 'c.duration_nights',
            'min_price' => 'c.min_price',
            'sync_status' => 'c.sync_status',
            'last_synced_at' => 'c.last_synced_at',
        ];
        $sort_by = isset($sortings[TypeCoerce::toString($params['sort_by'])]) ? TypeCoerce::toString($params['sort_by']) : 'name';
        $sort_order = strtolower(TypeCoerce::toString($params['sort_order'])) === 'desc' ? 'DESC' : 'ASC';
        $sort_column = $sortings[$sort_by];

        $params['sort_by'] = $sort_by;
        $params['sort_order'] = strtolower($sort_order);
        $params['sort_order_toggle'] = ($sort_order === 'ASC') ? 'desc' : 'asc';

        $condition = '';
        if ($params['transport_type'] !== '') {
            $condition .= db_quote(' AND c.transport_type = ?s', $params['transport_type']);
        }
        if ($params['sync_status'] !== '') {
            $condition .= db_quote(' AND c.sync_status = ?s', $params['sync_status']);
        }
        if ($params['q'] !== '') {
            $condition .= db_quote(' AND c.name LIKE ?l', '%' . $params['q'] . '%');
        }

        $params['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_circuits c WHERE 1 ?p',
            $condition,
        ));

        $offset = ($params['page'] - 1) * $params['items_per_page'];

        $listing_cols = 'c.circuit_id, c.name, c.transport_type, c.duration_days, c.duration_nights, '
            . 'c.destination_names, c.image_url, c.min_price, c.currency, c.product_id, '
            . 'c.sync_status, c.last_synced_at';

        $rows = TypeCoerce::toRowList(db_get_array(
            "SELECT {$listing_cols} FROM ?:sphinx_circuits c"
            . ' WHERE 1 ?p'
            . " ORDER BY {$sort_column} {$sort_order}"
            . ' LIMIT ?i, ?i',
            $condition,
            $offset,
            $params['items_per_page'],
        ));

        return [$rows, $params];
    }
}
