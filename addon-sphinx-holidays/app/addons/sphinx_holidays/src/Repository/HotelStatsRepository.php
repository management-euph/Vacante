<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Read-only aggregate / reporting queries over the sphinx_hotels table:
 * counts (total, linked, orphaned, with-boards), distinct facet values
 * (countries, classifications, property types, destination ids) and the
 * last-synced timestamp.
 *
 * Extracted from HotelRepository, which mixed these reporting reads with the
 * core CRUD + sync-write surface. Behaviour is preserved verbatim; HotelRepository
 * keeps the public methods as thin delegations so its many callers (services,
 * controllers, cron commands) are unchanged.
 */
class HotelStatsRepository
{
    use RowNarrowingTrait;

    /**
     * Get hotel counts grouped by country code.
     *
     * @return array<string, int>
     */
    public function getCountsByCountry(): array
    {
        $rows = self::asRowList(db_get_array(
            "SELECT country_code, COUNT(*) as cnt FROM ?:sphinx_hotels WHERE sync_status = 'active' GROUP BY country_code ORDER BY cnt DESC",
        ));

        $counts = [];
        foreach ($rows as $row) {
            $code = TypeCoerce::toString($row['country_code'] ?? '');
            if ($code === '') {
                $code = 'unknown';
            }
            $counts[$code] = TypeCoerce::toInt($row['cnt'] ?? 0);
        }

        return $counts;
    }

    /**
     * Get distinct country codes from synced hotels.
     *
     * @return list<string>
     */
    public function getDistinctCountries(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT country_code FROM ?:sphinx_hotels WHERE country_code != '' ORDER BY country_code",
        ));
    }

    /**
     * Get total number of active hotels.
     */
    public function getTotal(): int
    {
        return TypeCoerce::toInt(db_get_field("SELECT COUNT(*) FROM ?:sphinx_hotels WHERE sync_status = 'active'"));
    }

    /**
     * Get last hotel sync timestamp, optionally per country.
     */
    public function getLastSyncedAt(?string $countryCode = null): ?string
    {
        if ($countryCode !== null) {
            $val = TypeCoerce::toString(db_get_field(
                'SELECT MAX(last_synced_at) FROM ?:sphinx_hotels WHERE country_code = ?s',
                $countryCode,
            ));
        } else {
            $val = TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:sphinx_hotels'));
        }
        return $val === '' ? null : $val;
    }

    /**
     * Count hotels that have a linked CS-Cart product (one that still exists).
     */
    public function countLinked(): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels h INNER JOIN ?:products p ON p.product_id = h.product_id WHERE h.sync_status = 'active'",
        ));
    }

    /**
     * Get distinct destination_ids for active hotels, filtered by country codes.
     *
     * @param string[] $countryCodes Country codes to filter (e.g. ['GR', 'BG'])
     * @return int[] Distinct destination IDs
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        if (empty($countryCodes)) {
            return self::asIntList(db_get_fields(
                "SELECT DISTINCT destination_id FROM ?:sphinx_hotels WHERE sync_status = 'active' AND destination_id > 0",
            ));
        }

        $placeholders = implode(',', array_fill(0, count($countryCodes), '?s'));
        return self::asIntList(db_get_fields(
            "SELECT DISTINCT destination_id FROM ?:sphinx_hotels WHERE sync_status = 'active' AND destination_id > 0 AND country_code IN ($placeholders)",
            ...$countryCodes,
        ));
    }

    /**
     * Count hotels that have boards_json AND a linked product.
     */
    public function countWithBoardsAndProduct(string $countryCode = ''): int
    {
        $condition = ' AND h.boards_json IS NOT NULL AND h.product_id IS NOT NULL AND h.product_id > 0';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p",
            $condition,
        ));
    }

    /**
     * Count sphinx_hotels rows whose linked product_id no longer exists in CS-Cart.
     *
     * These are hotels that believe they have a product but the product was deleted
     * from CS-Cart without clearing the sphinx_hotels link.
     */
    public function countOrphanedProducts(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.product_id > 0
             AND NOT EXISTS (SELECT 1 FROM ?:products p WHERE p.product_id = h.product_id)',
        ));
    }

    /**
     * Count CS-Cart products that LOOK like sphinx hotel products but have no
     * hotel row pointing at them — exactly the set relinkExistingProducts()
     * repairs (it fetches each hotel from the API and re-inserts it linked).
     *
     * The opposite direction of countOrphanedProducts(): there the hotel row
     * survives and the product is gone; here the product survives and the
     * hotel row is missing or unlinked (fresh reinstall, table re-sync). The
     * relink admin action used to be gated on the ORPHAN count, so on a store
     * with this (much more common) breakage the button never rendered.
     *
     * Code shapes: the legacy configured prefix + the country-prefixed shape
     * SphinxProductFactory writes (TR3612). Two letters, so novoton's
     * NVT-prefixed codes can never match.
     */
    public function countUnlinkedProducts(string $legacyPrefix): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:products p
             WHERE (p.product_code LIKE ?l OR p.product_code REGEXP ?s)
             AND NOT EXISTS (
                 SELECT 1 FROM ?:sphinx_hotels h WHERE h.product_id = p.product_id
             )',
            $legacyPrefix . '%',
            '^[A-Z]{2}[0-9]+$',
        ));
    }

    /**
     * The unlinked sphinx-shaped products themselves (same predicate as
     * countUnlinkedProducts), newest code order, for the admin audit page.
     *
     * @return list<array<string, mixed>>
     */
    public function findUnlinkedProducts(string $legacyPrefix, int $limit = 500): array
    {
        $rows = db_get_array(
            'SELECT p.product_id, p.product_code, d.product AS name
             FROM ?:products p
             LEFT JOIN ?:product_descriptions d
                    ON d.product_id = p.product_id AND d.lang_code = ?s
             WHERE (p.product_code LIKE ?l OR p.product_code REGEXP ?s)
             AND NOT EXISTS (
                 SELECT 1 FROM ?:sphinx_hotels h WHERE h.product_id = p.product_id
             )
             ORDER BY p.product_code
             LIMIT ?i',
            defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en',
            $legacyPrefix . '%',
            '^[A-Z]{2}[0-9]+$',
            $limit,
        );

        return TypeCoerce::toRowList($rows);
    }

    /**
     * Get distinct classification values present in the data.
     *
     * @return list<int>
     */
    public function getDistinctClassifications(): array
    {
        return self::asIntList(db_get_fields(
            'SELECT DISTINCT classification FROM ?:sphinx_hotels WHERE classification IS NOT NULL ORDER BY classification',
        ));
    }

    /**
     * Get distinct property_type values present in the data.
     *
     * @return list<string>
     */
    public function getDistinctPropertyTypes(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT property_type FROM ?:sphinx_hotels WHERE property_type IS NOT NULL AND property_type != '' ORDER BY property_type",
        ));
    }
}
