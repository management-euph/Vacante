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
