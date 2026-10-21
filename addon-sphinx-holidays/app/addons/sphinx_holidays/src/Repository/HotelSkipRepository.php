<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Manages the product_skip_reason lifecycle on sphinx_hotels, and the
 * availability gate's product flags (gate_hidden, gate_released).
 *
 * A hotel carries a skip reason when it is not eligible for CS-Cart product
 * creation: it may be missing a description, sit in an invalid country, or —
 * via the availability gate — have no immediate-confirmation offer yet. This
 * repository owns marking, clearing, counting, and locating those rows.
 *
 * A LINKED hotel's gate state lives in its own columns instead, shared by
 * every hotel row linked to the same product (all writes go by product_id):
 *  - gate_hidden = 'Y': the gate set the product to Hidden; it shows it again
 *    when an immediate offer returns.
 *  - gate_released = 'Y': an admin changed a gate-hidden product by hand
 *    (made it Active or Disabled); the gate leaves it as the admin set it
 *    until the hotel has an immediate offer again.
 * product_skip_reason was the wrong home for that: "Retry skipped" and the
 * hotel upsert clear it, which left gate-hidden products Hidden for good.
 *
 * Extracted from HotelRepository so the skip/availability-gate concern lives
 * behind its own collaborator instead of swelling the hotel repository's
 * read/write surface. Behaviour (SQL and parameters) is preserved verbatim.
 */
class HotelSkipRepository
{
    use RowNarrowingTrait;

    private const string STATUS_ACTIVE = 'active';

    /**
     * Skip reason set by the availability gate on an UNLINKED hotel that has
     * no immediate-confirmation offer: it keeps the hotel from becoming a
     * product (HotelRepository::findUnlinked() excludes flagged rows) until
     * an immediate offer appears. The gate owns it: resetSkipped() and
     * countSkipped() leave it out unless asked for it by name. Linked hotels
     * use gate_hidden / gate_released instead.
     */
    public const string SKIP_REASON_NO_AVAILABILITY = 'no_availability';

    /**
     * Mark a hotel as skipped for product creation with a reason.
     *
     * Skipped hotels are excluded from findUnlinked() on subsequent runs.
     * Reset with resetSkipped() to retry.
     */
    public function markSkipped(string $hotelId, string $reason): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s WHERE hotel_id = ?s',
            $reason,
            $hotelId,
        );
    }

    /**
     * Find hotels eligible for the availability gate within the given destinations.
     *
     * Candidates are ALL active hotels — linked to a CS-Cart product or not —
     * with either no skip reason or the availability gate's own reason, so a
     * flagged hotel is checked again on every run and comes back as soon as
     * it has an immediate offer. Hotels skipped for other reasons
     * (invalid_country, …) stay untouched.
     *
     * @param int[] $destinationIds
     * @return list<array<string, mixed>> Rows of {hotel_id, destination_id, product_id, product_skip_reason}
     */
    public function findAvailabilityGateCandidates(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        return self::asRowList(db_get_array(
            'SELECT hotel_id, destination_id, product_id, product_skip_reason
             FROM ?:sphinx_hotels
             WHERE destination_id IN (?n)
               AND sync_status = ?s
               AND (product_skip_reason IS NULL OR product_skip_reason = ?s)',
            $destinationIds,
            self::STATUS_ACTIVE,
            self::SKIP_REASON_NO_AVAILABILITY,
        ));
    }

    /**
     * Set a skip reason on hotels that do not already carry one.
     *
     * Only NULL skip reasons are overwritten, so hotels skipped for other
     * reasons (invalid_country, no_description, ...) are left untouched.
     *
     * @param string[] $hotelIds
     * @return int Number of hotels updated
     */
    public function markSkippedBatch(array $hotelIds, string $reason): int
    {
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s
             WHERE hotel_id IN (?a) AND product_skip_reason IS NULL',
            $reason,
            $hotelIds,
        ));
    }

    /**
     * The gate state of linked products, read when the gate decides (after
     * its searches, which take minutes, so an admin's change made meanwhile
     * counts): each product's CS-Cart status and whether any hotel row
     * linked to it carries gate_hidden / gate_released. Deleted products are
     * left out.
     *
     * @param int[] $productIds
     * @return array<int, array{status: string, hidden: bool, released: bool}>
     */
    public function findGateStates(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = self::asRowList(db_get_array(
            "SELECT h.product_id, p.status,
                    MAX(h.gate_hidden = 'Y') AS hidden, MAX(h.gate_released = 'Y') AS released
             FROM ?:sphinx_hotels h
             JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.product_id IN (?n)
             GROUP BY h.product_id, p.status",
            $productIds,
        ));

        $states = [];
        foreach ($rows as $row) {
            $states[TypeCoerce::toInt($row['product_id'] ?? 0)] = [
                'status' => TypeCoerce::toString($row['status'] ?? ''),
                'hidden' => TypeCoerce::toInt($row['hidden'] ?? 0) === 1,
                'released' => TypeCoerce::toInt($row['released'] ?? 0) === 1,
            ];
        }

        return $states;
    }

    /**
     * Hide the CS-Cart products of linked hotels that lost their immediate
     * offer. Hidden ('H') is CS-Cart's "reachable by direct link, absent
     * from listings and searches" status — the product page survives (URL,
     * SEO, images, history) for when the hotel becomes bookable again.
     *
     * Only ACTIVE products are touched: a product the admin already Hid or
     * Disabled by hand keeps whatever the admin chose. The flag is written
     * FIRST: a run that stops in between leaves a flagged Active product,
     * which the next run releases (it stays visible), never a Hidden product
     * without the flag, which nothing would ever show again.
     *
     * @param int[] $productIds
     * @return int Number of products hidden
     */
    public function hideProductsBatch(array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        db_query("UPDATE ?:sphinx_hotels SET gate_hidden = 'Y' WHERE product_id IN (?n)", $productIds);

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:products SET status = 'H' WHERE product_id IN (?n) AND status = 'A'",
            $productIds,
        ));
    }

    /**
     * Reactivate products the gate hid, for hotels whose immediate offer
     * returned. Only HIDDEN products flip back: the caller passes products
     * flagged gate_hidden, and the status guard keeps a product the admin
     * meanwhile Disabled exactly as the admin left it. The product goes
     * first and the flag second, for the same reason as hideProductsBatch().
     *
     * @param int[] $productIds
     * @return int Number of products reactivated
     */
    public function showProductsBatch(array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        $shown = TypeCoerce::toInt(db_query(
            "UPDATE ?:products SET status = 'A' WHERE product_id IN (?n) AND status = 'H'",
            $productIds,
        ));
        db_query("UPDATE ?:sphinx_hotels SET gate_hidden = 'N' WHERE product_id IN (?n)", $productIds);

        return $shown;
    }

    /**
     * Let go of gate-hidden products an admin changed by hand (made them
     * Active or Disabled): the gate leaves them as the admin set them until
     * the hotel has an immediate offer again (clearReleasedBatch()). Without
     * this, the next run would hide a product the admin had just shown.
     *
     * @param int[] $productIds
     * @return int Number of products released
     */
    public function releaseProductsBatch(array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        db_query(
            "UPDATE ?:sphinx_hotels SET gate_hidden = 'N', gate_released = 'Y' WHERE product_id IN (?n)",
            $productIds,
        );

        return count($productIds);
    }

    /**
     * An immediate offer is back for released products: the admin's choice
     * has done its job, and the gate handles them again from the next loss.
     *
     * @param int[] $productIds
     */
    public function clearReleasedBatch(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        db_query("UPDATE ?:sphinx_hotels SET gate_released = 'N' WHERE product_id IN (?n)", $productIds);
    }

    /**
     * Clear a specific skip reason from hotels, making them eligible again.
     *
     * Scoped to the given reason so unrelated skips are preserved.
     *
     * @param string[] $hotelIds
     * @return int Number of hotels cleared
     */
    public function clearSkipReasonBatch(array $hotelIds, string $reason): int
    {
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE hotel_id IN (?a) AND product_skip_reason = ?s',
            $hotelIds,
            $reason,
        ));
    }

    /**
     * Reset skip reason for hotels, making them eligible for product creation again.
     *
     * Without a reason, the availability gate's own no_availability is kept:
     * the gate clears it when an immediate offer appears, and a hotel that
     * becomes a product without one is only hidden again on the next run.
     *
     * @return int Number of hotels reset
     */
    public function resetSkipped(string $countryCode = '', string $reason = ''): int
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= TypeCoerce::toString(db_quote(' AND country_code = ?s', $countryCode));
        }
        $condition .= self::reasonCondition($reason);

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE product_skip_reason IS NOT NULL ?p',
            $condition,
        ));
    }

    /**
     * Count hotels that have been skipped for product creation. Without a
     * reason, hotels waiting for an immediate offer are not counted (see
     * resetSkipped()); ask for SKIP_REASON_NO_AVAILABILITY to count them.
     */
    public function countSkipped(string $reason = ''): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_skip_reason IS NOT NULL ?p',
            self::reasonCondition($reason),
        ));
    }

    /** " AND " the given reason, or every reason but the availability gate's. */
    private static function reasonCondition(string $reason): string
    {
        return ' ' . TypeCoerce::toString($reason !== ''
            ? db_quote('AND product_skip_reason = ?s', $reason)
            : db_quote('AND product_skip_reason <> ?s', self::SKIP_REASON_NO_AVAILABILITY));
    }
}
