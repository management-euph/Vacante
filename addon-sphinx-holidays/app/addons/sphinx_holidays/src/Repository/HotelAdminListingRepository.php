<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Admin hotels-grid listing (fn_get_products pattern): filtering, sorting
 * and pagination over ?:sphinx_hotels. Body of the
 * fn_sphinx_holidays_get_hotels shell the backend controller calls; the
 * per-page default comes in through the constructor (Registry access is
 * banned inside src/ bodies).
 */
final class HotelAdminListingRepository
{
    use RowNarrowingTrait;

    public function __construct(
        private readonly int $defaultPerPage = 50,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params Search/filter/sort parameters from $_REQUEST
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} [$hotels, $search_params]
     */
    public function getListing(array $params = []): array
    {
        $default_params = [
            'page' => 1,
            'items_per_page' => $this->defaultPerPage,
            'sort_by' => 'name',
            'sort_order' => 'asc',
            'country_code' => '',
            'region_id' => 0,
            'destination_id' => 0,
            'sync_status' => '',
            'classification' => '',
            'property_type' => '',
            'link_status' => '',
            'availability' => '',
            'q' => '',
            'city' => '',
        ];

        $params = array_merge($default_params, array_intersect_key($params, $default_params));
        $params['page'] = max(1, TypeCoerce::toInt($params['page']));
        $params['items_per_page'] = max(1, TypeCoerce::toInt($params['items_per_page']));
        $params['region_id'] = TypeCoerce::toInt($params['region_id']);
        $params['destination_id'] = TypeCoerce::toInt($params['destination_id']);
        $params['q'] = trim(TypeCoerce::toString($params['q']));
        $params['city'] = trim(TypeCoerce::toString($params['city']));

        // Sortings map: allowed sort columns
        $sortings = [
            'hotel_id' => 'h.hotel_id',
            'name' => 'h.name',
            'classification' => 'h.classification',
            'country_code' => 'h.country_code',
            'address_city' => 'h.address_city',
            'address_country' => 'h.address_country',
            // The RESOLVED location the product features use (the grid's
            // City/Region columns) — distinct from the raw API address above.
            'destination_name' => 'h.destination_name',
            'region_name' => 'h.region_name',
            'sync_status' => 'h.sync_status',
            'last_synced_at' => 'h.last_synced_at',
            'property_type' => 'h.property_type',
        ];

        $sort_by = isset($sortings[TypeCoerce::toString($params['sort_by'])]) ? TypeCoerce::toString($params['sort_by']) : 'name';
        $sort_order = strtolower(TypeCoerce::toString($params['sort_order'])) === 'desc' ? 'DESC' : 'ASC';
        $sort_column = $sortings[$sort_by];

        $params['sort_by'] = $sort_by;
        $params['sort_order'] = strtolower($sort_order);
        $params['sort_order_toggle'] = ($sort_order === 'ASC') ? 'desc' : 'asc';

        // Build WHERE condition
        $condition = '';

        if ($params['country_code'] !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $params['country_code']);
        }
        if ($params['region_id'] > 0) {
            $condition .= db_quote(' AND h.region_id = ?i', $params['region_id']);
        }
        if ($params['destination_id'] > 0) {
            $condition .= db_quote(' AND h.destination_id = ?i', $params['destination_id']);
        }
        if ($params['sync_status'] !== '') {
            $condition .= db_quote(' AND h.sync_status = ?s', $params['sync_status']);
        }
        if ($params['classification'] !== '') {
            $classification = TypeCoerce::toInt($params['classification']);
            if ($classification === 0) {
                $condition .= ' AND (h.classification IS NULL OR h.classification = 0)';
            } else {
                $condition .= db_quote(' AND h.classification = ?i', $classification);
            }
        }
        if ($params['property_type'] !== '') {
            $condition .= db_quote(' AND h.property_type = ?s', $params['property_type']);
        }
        if ($params['link_status'] === 'linked') {
            $condition .= ' AND h.product_id IS NOT NULL AND h.product_id > 0';
        } elseif ($params['link_status'] === 'orphan') {
            $condition .= ' AND (h.product_id IS NULL OR h.product_id = 0)';
        }
        if ($params['q'] !== '') {
            $condition .= db_quote(' AND h.name LIKE ?l', '%' . $params['q'] . '%');
        }
        if ($params['city'] !== '') {
            // The grid's City column shows the RESOLVED city, so the filter has
            // to search it too — searching only the raw API address would hide
            // the very rows the column displays.
            $condition .= db_quote(
                ' AND (h.address_city LIKE ?l OR h.destination_name LIKE ?l)',
                '%' . $params['city'] . '%',
                '%' . $params['city'] . '%',
            );
        }

        // "Hotels with immediate confirmation": the availability gate keeps a
        // hotel without a product and without an immediate offer, flagged
        // no_availability, so it can come back when it becomes bookable. The
        // grid shows those waiting rows only on request (availability =
        // waiting / all). <=> because product_skip_reason is usually NULL.
        $waiting = TypeCoerce::toString(db_quote(
            '(h.product_id IS NULL OR h.product_id = 0) AND h.product_skip_reason <=> ?s',
            HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY,
        ));
        if ($params['availability'] === 'waiting') {
            $condition .= ' AND ' . $waiting;
        } elseif ($params['availability'] !== 'all') {
            $params['availability'] = '';
            $condition .= ' AND NOT (' . $waiting . ')';
        }

        // Total count
        $params['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels h WHERE 1 ?p',
            $condition,
        ));

        // Pagination
        $offset = ($params['page'] - 1) * $params['items_per_page'];

        // Select listing columns (prefixed with alias)
        $listing_cols = 'h.hotel_id, h.product_id, h.name, h.classification, h.property_type, '
            . 'h.destination_id, h.destination_name, h.region_id, h.region_name, '
            . 'h.country_code, h.country_name, h.address_city, h.address_country, h.latitude, h.longitude, '
            . 'h.image_url, h.is_recommended, h.is_adults_only, h.rating, h.rating_count, '
            . 'h.sync_status, h.last_synced_at, h.created_at, h.updated_at, h.product_skip_reason';

        $hotels = db_get_array(
            "SELECT {$listing_cols} FROM ?:sphinx_hotels h"
            . ' WHERE 1 ?p'
            . " ORDER BY {$sort_column} {$sort_order}"
            . ' LIMIT ?i, ?i',
            $condition,
            $offset,
            $params['items_per_page'],
        );

        return [self::asRowList($hotels), $params];
    }
}
