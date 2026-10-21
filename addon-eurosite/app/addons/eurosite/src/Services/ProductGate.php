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
 *    product the admin hid stays hidden.
 * If the admin changes a gate-hidden product by hand (makes it Active or
 * Disabled), the gate lets go of it (gate_released = 'Y') and leaves it as
 * the admin set it until the hotel has an Immediate offer again; from then
 * on the gate handles it as before. Without that, a product the admin had
 * just shown was hidden again two runs later.
 *
 * Runs only over destinations whose check answered: an API outage must
 * never hide a whole destination's products. A destination where only some
 * dates answered can have products shown again, never hidden: a date that
 * did not answer may have held the Immediate offer.
 */
final class ProductGate
{
    /**
     * @param list<string> $cityCodes destinations the check just answered for, on every date
     * @param list<string> $partlyAnsweredCityCodes destinations where only some dates answered
     *
     * @return array{hidden: int, shown: int, released: int}
     */
    public function apply(array $cityCodes, array $partlyAnsweredCityCodes = []): array
    {
        // First, so the steps below see it: a gate-hidden product the admin
        // has since changed by hand is the admin's now.
        $result = ['hidden' => 0, 'shown' => 0, 'released' => TypeCoerce::toInt(db_query(
            "UPDATE ?:eurosite_hotels h JOIN ?:products p ON p.product_id = h.product_id
             SET h.gate_hidden = 'N', h.gate_released = 'Y'
             WHERE h.gate_hidden = 'Y' AND p.status <> 'H'",
        ))];

        $answered = [...$cityCodes, ...$partlyAnsweredCityCodes];
        if ($answered === []) {
            return $result;
        }

        // An Immediate offer is back: a release has done its job, and the
        // next loss hides the product again.
        db_query(
            "UPDATE ?:eurosite_hotels SET gate_released = 'N'
             WHERE city_code IN (?a) AND availability = 'IM' AND gate_released = 'Y'",
            $answered,
        );

        // Lost every Immediate offer: hide. The flag goes first: a run that
        // stops in between leaves a flagged Active product, which the next
        // run releases (it stays visible), never a Hidden product without
        // the flag, which nothing would ever show again.
        $toHide = $cityCodes === [] ? [] : TypeCoerce::toIntList(db_get_fields(
            "SELECT h.product_id FROM ?:eurosite_hotels h
             JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.city_code IN (?a) AND h.sync_status = 'active'
               AND h.availability IN ('OR', 'ST', 'NONE') AND h.gate_hidden = 'N' AND h.gate_released = 'N'
               AND p.status = 'A'",
            $cityCodes,
        ));
        if ($toHide !== []) {
            db_query("UPDATE ?:eurosite_hotels SET gate_hidden = 'Y' WHERE product_id IN (?n)", $toHide);
            db_query("UPDATE ?:products SET status = 'H' WHERE product_id IN (?n) AND status = 'A'", $toHide);
            $result['hidden'] = count($toHide);
        }

        // Immediate again: show what this gate hid. The product goes first,
        // the flag second, for the same reason.
        $toShow = TypeCoerce::toIntList(db_get_fields(
            "SELECT h.product_id FROM ?:eurosite_hotels h
             JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.city_code IN (?a) AND h.availability = 'IM' AND h.gate_hidden = 'Y' AND p.status = 'H'",
            $answered,
        ));
        if ($toShow !== []) {
            db_query("UPDATE ?:products SET status = 'A' WHERE product_id IN (?n) AND status = 'H'", $toShow);
            db_query("UPDATE ?:eurosite_hotels SET gate_hidden = 'N' WHERE product_id IN (?n)", $toShow);
            $result['shown'] = count($toShow);
        }

        return $result;
    }
}
