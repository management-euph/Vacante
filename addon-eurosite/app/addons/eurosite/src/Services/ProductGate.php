<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Hides a Eurosite product when its hotel has no Immediate offer on any
 * checked date, and shows it again when one returns (the Sphinx gate's rule).
 *
 * Hidden (H), not disabled: the product keeps its page, URL and SEO, and
 * drops out of listings and search. Both flips are guarded:
 *  - only an Active product is hidden, so one the admin disabled or hid by
 *    hand is never touched;
 *  - only a product THIS gate hid (gate_hidden = 'Y') is shown again, so a
 *    product the admin hid stays hidden. If the admin changed a gate-hidden
 *    product by hand, the gate lets go of it.
 *
 * Runs only over destinations whose check answered: an API outage must
 * never hide a whole destination's products.
 */
final class ProductGate
{
    /**
     * @param list<string> $cityCodes destinations the check just answered for
     *
     * @return array{hidden: int, shown: int, released: int}
     */
    public function apply(array $cityCodes): array
    {
        $result = ['hidden' => 0, 'shown' => 0, 'released' => 0];
        if ($cityCodes === []) {
            return $result;
        }

        // Lost every Immediate offer: hide.
        $toHide = TypeCoerce::toIntList(db_get_fields(
            "SELECT h.product_id FROM ?:eurosite_hotels h
             JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.city_code IN (?a) AND h.sync_status = 'active'
               AND h.availability IN ('OR', 'ST', 'NONE') AND h.gate_hidden = 'N' AND p.status = 'A'",
            $cityCodes,
        ));
        if ($toHide !== []) {
            db_query("UPDATE ?:products SET status = 'H' WHERE product_id IN (?n) AND status = 'A'", $toHide);
            db_query("UPDATE ?:eurosite_hotels SET gate_hidden = 'Y' WHERE product_id IN (?n)", $toHide);
            $result['hidden'] = count($toHide);
        }

        // Immediate again: show what this gate hid.
        $toShow = TypeCoerce::toIntList(db_get_fields(
            "SELECT h.product_id FROM ?:eurosite_hotels h
             JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.city_code IN (?a) AND h.availability = 'IM' AND h.gate_hidden = 'Y' AND p.status = 'H'",
            $cityCodes,
        ));
        if ($toShow !== []) {
            db_query("UPDATE ?:products SET status = 'A' WHERE product_id IN (?n) AND status = 'H'", $toShow);
            db_query("UPDATE ?:eurosite_hotels SET gate_hidden = 'N' WHERE product_id IN (?n)", $toShow);
            $result['shown'] = count($toShow);
        }

        // A gate-hidden product the admin has since changed by hand (made it
        // Active or Disabled) is the admin's now.
        $result['released'] = TypeCoerce::toInt(db_query(
            "UPDATE ?:eurosite_hotels h JOIN ?:products p ON p.product_id = h.product_id
             SET h.gate_hidden = 'N'
             WHERE h.gate_hidden = 'Y' AND p.status <> 'H'",
        ));

        return $result;
    }
}
