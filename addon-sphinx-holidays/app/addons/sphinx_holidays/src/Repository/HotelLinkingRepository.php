<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Reads that drive product creation, enrichment, feature assignment and SEO:
 * unlinked hotels awaiting a product, hotels missing images, hotels with
 * boards + a linked product, and the linked-hotel batch for SEO bulk-apply.
 *
 * Extracted from HotelRepository, which mixed these product-pipeline reads with
 * the core CRUD + sync-write surface. Behaviour is preserved verbatim;
 * HotelRepository keeps the public methods as thin delegations so its callers
 * (cron commands, enrich/SEO services) are unchanged. The shared listing-column
 * definitions come from HotelListingColumnsTrait.
 */
class HotelLinkingRepository
{
    use RowNarrowingTrait;
    use HotelListingColumnsTrait;

    /** Days before a hotel the API had no images for is asked again. */
    public const int RECHECK_IMAGES_DAYS = 30;

    /**
     * Get hotels that have no images stored (images_json is null/empty/[]).
     * Used by enrich_hotel_data to backfill from the detail API.
     *
     * @return list<array<string, mixed>>
     */
    public function findMissingImages(string $countryCode = '', int $limit = 100): array
    {
        // A hotel the API had no images for waits RECHECK_IMAGES_DAYS: else
        // every run asked the same first hotels again and never got further.
        $cond = "(h.images_json IS NULL OR h.images_json = '' OR h.images_json = '[]')"
            . db_quote(' AND (h.images_checked_at IS NULL OR h.images_checked_at < ?s)', date('Y-m-d H:i:s', time() - self::RECHECK_IMAGES_DAYS * 86400));
        if ($countryCode !== '') {
            $cond .= db_quote(' AND h.country_code = ?s', $countryCode);
        }
        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        return self::asRowList(db_get_array(
            "SELECT h.hotel_id, h.name, h.product_id
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' AND {$cond}
             ORDER BY h.hotel_id ASC {$limitClause}",
        ));
    }

    /**
     * The hotel API had no images for this hotel: findMissingImages() leaves
     * it out for RECHECK_IMAGES_DAYS.
     */
    public function markImagesChecked(string $hotelId): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET images_checked_at = ?s WHERE hotel_id = ?s',
            date('Y-m-d H:i:s'),
            $hotelId,
        );
    }

    /**
     * Hotels linked to a product that no longer exists (deleted before the
     * delete hook unlinked them, or straight from the database) are
     * unlinked, as HotelRepository::unlinkProduct() does, so add_products
     * makes them a new one: how many.
     */
    public function unlinkMissingProducts(): int
    {
        return TypeCoerce::toInt(db_query(
            "UPDATE ?:sphinx_hotels h LEFT JOIN ?:products p ON p.product_id = h.product_id
             SET h.product_id = NULL, h.gate_hidden = 'N', h.gate_released = 'N'
             WHERE h.product_id > 0 AND p.product_id IS NULL",
        ));
    }

    /**
     * Get unlinked hotels (no product_id) with optional country filter.
     *
     * @return list<array<string, mixed>> List of hotel rows without linked products
     */
    public function findUnlinked(string $countryCode = '', int $limit = 0): array
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';
        $cols = $this->aliasedListingColumns();

        // Product creation needs TEXT/JSON columns excluded from the listing columns
        $extraCols = ', h.description, h.short_description, h.facilities_json, h.boards_json';

        return self::asRowList(db_get_array(
            "SELECT {$cols}{$extraCols} FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND (h.product_id IS NULL OR h.product_id = 0)
               AND h.product_skip_reason IS NULL ?p
             ORDER BY h.country_code ASC, h.name ASC ?p",
            $condition,
            $limitClause,
        ));
    }

    /**
     * Get hotels that have boards_json AND a linked product.
     *
     * Returns all fields needed by SphinxFeatureAssigner::assignAll().
     *
     * @param string $countryCode Optional country filter
     * @param int $limit Max rows (0 = unlimited)
     * @param int $offset Starting offset for pagination
     * @return list<array<string, mixed>> List of hotel rows
     */
    public function findWithBoardsAndProduct(string $countryCode = '', int $limit = 0, int $offset = 0): array
    {
        $condition = ' AND h.boards_json IS NOT NULL AND h.product_id IS NOT NULL AND h.product_id > 0';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        $limitClause = '';
        if ($limit > 0) {
            $limitClause = db_quote(' LIMIT ?i, ?i', $offset, $limit);
        } elseif ($offset > 0) {
            // MySQL max BIGINT UNSIGNED — effectively "no limit, offset only"
            $limitClause = db_quote(' LIMIT ?i, 18446744073709551615', $offset);
        }

        return self::asRowList(db_get_array(
            "SELECT h.hotel_id, h.product_id, h.boards_json, h.name,
                    h.classification, h.property_type, h.destination_name,
                    h.facilities_json, h.country_code
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p
             ORDER BY h.hotel_id ASC ?p",
            $condition,
            $limitClause,
        ));
    }

    /**
     * Fetch a batch of active linked hotels for SEO bulk-apply.
     *
     * Returns hotel rows with location data needed for placeholder building.
     *
     * @param int $offset Starting offset
     * @param int $batch Batch size
     * @return list<array<string, mixed>>
     */
    public function fetchLinkedBatchForSeo(int $offset, int $batch): array
    {
        return self::asRowList(db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.classification, h.property_type,
                    h.description, h.rating, h.facilities_json, h.boards_json,
                    h.latitude, h.longitude, h.image_url, h.address, h.phone, h.email, h.website,
                    h.destination_name, h.country_name, h.region_name
             FROM ?:sphinx_hotels h
             WHERE h.product_id IS NOT NULL AND h.product_id > 0
               AND h.sync_status = 'active'
             LIMIT ?i, ?i",
            $offset,
            $batch,
        ));
    }
}
