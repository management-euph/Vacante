<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;

/**
 * The availability gate (docs/novoton-availability-gaps-plan.md §7.3, §7.4,
 * §10): for a supplier in Automatic mode, hides the hotel products the weekly
 * review found dormant, gone from the supplier's list or closed, and shows
 * them again once they are not. In Report only mode it changes nothing and
 * counts what it would hide.
 *
 * Hidden (H), never disabled or deleted: the page, its URL and its bookings
 * stay, the hotel drops out of listings and search, and it can still be
 * booked from a direct link (ProviderCartProduct, gate_hidden). The flips are
 * guarded as Eurosite's gate does them:
 *  - only an Active product is hidden; one an admin disabled or hid by hand
 *    is never touched, and the gate never activates a product it did not hide;
 *  - only a product this gate hid (gate_hidden = 'Y') is shown again;
 *  - a gate-hidden product an admin changed by hand is released
 *    (gate_released = 'Y'): the gate leaves it as the admin set it until the
 *    hotel has availability again.
 * The admin's overrides win: keep visible, keep hidden, closed.
 *
 * A product is judged on all its records with the supplier: it is hidden
 * only when every one of them hides (Sphinx links several hotel ids to one
 * product), and shown again when one of them no longer does.
 *
 * The brake: a run that would hide more than max(10, 5% of the supplier's
 * visible products) hides none; the weekly email and the Hotel availability
 * page say so, and the admin can hide them anyway from the page once the
 * list looks right.
 */
final class AvailabilityGate
{
    /** The states whose hotels are hidden (decision 7 in §11; closed, §7.4). */
    public const array HIDING_STATES = ['dormant', 'left_feed', 'closed'];

    public const string KEEP_VISIBLE = 'keep_visible';

    public const string KEEP_HIDDEN = 'keep_hidden';

    public const string CLOSED = 'closed';

    /** The overrides an admin can set on a hotel. */
    public const array OVERRIDES = [self::KEEP_VISIBLE, self::KEEP_HIDDEN, self::CLOSED];

    /** A run hides at most max(BRAKE_MIN, BRAKE_PERCENT % of the supplier's visible products). */
    public const int BRAKE_MIN = 10;

    public const int BRAKE_PERCENT = 5;

    /** Log reason when an admin changed a gate-hidden product by hand. */
    public const string REASON_RELEASED = 'admin_changed';

    /** Products per IN (...) list. */
    private const int CHUNK = 500;

    /**
     * One supplier's products through the gate.
     *
     * @param bool $enforce Automatic mode: change products; false only counts what it would hide
     * @param bool $hide hide too (the weekly review, an admin's override); false only shows and releases (the daily step)
     * @param int $productId one product (an admin's override), 0 = all the supplier's
     * @param int $userId the admin, for the log; 0 = the review
     * @param bool $brake hold a run that would hide too many; false when the admin confirmed the list
     *
     * @return array{hidden: int, shown: int, released: int, would_hide: int, braked: int}
     */
    public function apply(string $provider, string $now, bool $enforce, bool $hide = true, int $productId = 0, int $userId = 0, bool $brake = true): array
    {
        HotelAvailabilityRepository::ensureTables();
        $result = ['hidden' => 0, 'shown' => 0, 'released' => 0, 'would_hide' => 0, 'braked' => 0];
        $scope = $productId > 0 ? db_quote(' AND a.product_id = ?i', $productId) : '';

        if ($enforce) {
            // First, so the steps below see it: a gate-hidden product the admin
            // has since changed by hand (or deleted) is the admin's now.
            $released = TypeCoerce::toRowList(db_get_array(
                "SELECT a.hotel_id, a.product_id, a.state, COALESCE(p.status, '') AS status
                 FROM ?:travel_hotel_availability a
                 LEFT JOIN ?:products p ON p.product_id = a.product_id
                 WHERE a.provider = ?s AND a.gate_hidden = 'Y' AND (p.status IS NULL OR p.status <> 'H')?p",
                $provider,
                $scope,
            ));
            if ($released !== []) {
                $hotelIds = array_map(static fn (array $row): string => TypeCoerce::toString($row['hotel_id'] ?? ''), $released);
                foreach (array_chunk($hotelIds, self::CHUNK) as $chunk) {
                    db_query(
                        "UPDATE ?:travel_hotel_availability SET gate_hidden = 'N', gate_released = 'Y' WHERE provider = ?s AND hotel_id IN (?a)",
                        $provider,
                        $chunk,
                    );
                }
                $this->log($provider, $released, 'H', null, self::REASON_RELEASED, $now, $userId);
                $result['released'] = count($released);
            }

            // Availability is back: a release has done its job, and the next
            // loss hides the product again.
            db_query(
                "UPDATE ?:travel_hotel_availability a SET a.gate_released = 'N'
                 WHERE a.provider = ?s AND a.gate_released = 'Y' AND NOT ?p?p",
                $provider,
                self::hides(),
                $scope,
            );
        }

        if ($hide) {
            $toHide = TypeCoerce::toIntList(db_get_fields(
                "SELECT a.product_id FROM ?:travel_hotel_availability a
                 JOIN ?:products p ON p.product_id = a.product_id
                 WHERE a.provider = ?s AND a.product_id > 0 AND p.status = 'A'?p
                 GROUP BY a.product_id
                 HAVING SUM(NOT ?p) = 0 AND SUM(a.gate_hidden = 'Y' OR a.gate_released = 'Y') = 0",
                $provider,
                $scope,
                self::hides(),
            ));
            $result['would_hide'] = count($toHide);
            if ($enforce && $toHide !== []) {
                if ($brake && $productId <= 0 && count($toHide) > self::brakeLimit($this->visibleProducts($provider))) {
                    $result['braked'] = count($toHide);
                } else {
                    $result['hidden'] = $this->hideProducts($provider, $toHide, $now, $userId);
                }
            }
        }

        if ($enforce) {
            $toShow = TypeCoerce::toIntList(db_get_fields(
                "SELECT a.product_id FROM ?:travel_hotel_availability a
                 JOIN ?:products p ON p.product_id = a.product_id
                 WHERE a.provider = ?s AND a.product_id > 0 AND p.status = 'H'?p
                 GROUP BY a.product_id
                 HAVING SUM(a.gate_hidden = 'Y') > 0 AND SUM(NOT ?p) > 0",
                $provider,
                $scope,
                self::hides(),
            ));
            if ($toShow !== []) {
                $result['shown'] = $this->showProducts($provider, $toShow, $now, $userId);
            }
        }

        return $result;
    }

    /** The most products one run may hide, for a supplier with $visible active products. */
    public static function brakeLimit(int $visible): int
    {
        return max(self::BRAKE_MIN, intdiv(max(0, $visible) * self::BRAKE_PERCENT + 99, 100));
    }

    /**
     * SQL: the record `a` hides its product. The admin's override first:
     * keep hidden and closed hide, keep visible never does; else its state.
     */
    public static function hides(): string
    {
        return db_quote(
            '(a.override IN (?a) OR (a.override <> ?s AND a.state IN (?a)))',
            [self::KEEP_HIDDEN, self::CLOSED],
            self::KEEP_VISIBLE,
            self::HIDING_STATES,
        );
    }

    /** The supplier's hotel products that are Active now. */
    private function visibleProducts(string $provider): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(DISTINCT a.product_id) FROM ?:travel_hotel_availability a
             JOIN ?:products p ON p.product_id = a.product_id
             WHERE a.provider = ?s AND a.product_id > 0 AND p.status = 'A'",
            $provider,
        ));
    }

    /**
     * A → H. The flag goes first: a run that stops in between leaves a
     * flagged Active product, which the next run releases (it stays
     * visible), never a Hidden product without the flag, which nothing
     * would ever show again.
     *
     * @param list<int> $productIds
     */
    private function hideProducts(string $provider, array $productIds, string $now, int $userId): int
    {
        $hidden = 0;
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $rows = $this->records($provider, $chunk);
            db_query("UPDATE ?:travel_hotel_availability SET gate_hidden = 'Y' WHERE provider = ?s AND product_id IN (?n)", $provider, $chunk);
            $hidden += TypeCoerce::toInt(db_query("UPDATE ?:products SET status = 'H' WHERE product_id IN (?n) AND status = 'A'", $chunk));
            $this->log($provider, $rows, 'A', 'H', null, $now, $userId);
        }

        return $hidden;
    }

    /**
     * H → A, only what this gate hid. The product goes first, the flag
     * second, for the same reason.
     *
     * @param list<int> $productIds
     */
    private function showProducts(string $provider, array $productIds, string $now, int $userId): int
    {
        $shown = 0;
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $rows = $this->records($provider, $chunk);
            $shown += TypeCoerce::toInt(db_query("UPDATE ?:products SET status = 'A' WHERE product_id IN (?n) AND status = 'H'", $chunk));
            db_query("UPDATE ?:travel_hotel_availability SET gate_hidden = 'N' WHERE provider = ?s AND product_id IN (?n)", $provider, $chunk);
            $this->log($provider, $rows, 'H', 'A', null, $now, $userId);
        }

        return $shown;
    }

    /**
     * The records of these products, for the log.
     *
     * @param list<int> $productIds
     *
     * @return list<array<string, mixed>>
     */
    private function records(string $provider, array $productIds): array
    {
        return TypeCoerce::toRowList(db_get_array(
            "SELECT a.hotel_id, a.product_id, a.state, a.state_reason, a.override, COALESCE(p.status, '') AS status
             FROM ?:travel_hotel_availability a
             LEFT JOIN ?:products p ON p.product_id = a.product_id
             WHERE a.provider = ?s AND a.product_id IN (?n)",
            $provider,
            $productIds,
        ));
    }

    /**
     * One state-log row per record whose product changed.
     *
     * @param list<array<string, mixed>> $rows records: hotel_id, product_id, state, and state_reason, override or status
     * @param string|null $newStatus null = the product's status as read
     * @param string|null $reason null = the override, else the hotel's state reason
     */
    private function log(string $provider, array $rows, string $oldStatus, ?string $newStatus, ?string $reason, string $now, int $userId): void
    {
        $values = [];
        foreach ($rows as $row) {
            $override = TypeCoerce::toString($row['override'] ?? '');
            $state = TypeCoerce::toString($row['state'] ?? '');
            $values[] = '(' . implode(', ', [
                db_quote('?s', $provider),
                db_quote('?s', TypeCoerce::toString($row['hotel_id'] ?? '')),
                db_quote('?i', TypeCoerce::toInt($row['product_id'] ?? 0)),
                db_quote('?s', $state),
                db_quote('?s', $state),
                db_quote('?s', $oldStatus),
                db_quote('?s', $newStatus ?? TypeCoerce::toString($row['status'] ?? '')),
                db_quote('?s', $reason ?? ($override !== '' ? 'override_' . $override : TypeCoerce::toString($row['state_reason'] ?? ''))),
                db_quote('?i', $userId),
                db_quote('?s', $now),
            ]) . ')';
        }
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            db_query(
                'INSERT INTO ?:travel_hotel_state_log
                    (provider, hotel_id, product_id, old_state, new_state, old_status, new_status, reason, user_id, created_at)
                 VALUES ?p',
                implode(', ', $chunk),
            );
        }
    }
}
