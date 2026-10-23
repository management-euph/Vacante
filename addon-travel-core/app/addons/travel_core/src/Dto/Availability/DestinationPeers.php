<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Availability;

/**
 * How a hotel's destination (Novoton resort, Eurosite city, Sphinx
 * destination) fared in its recent checks: what tells "the whole coast is
 * closed" apart from "the supplier is not selling this hotel".
 *
 * Counted from the destination's records over its last two weekly checks.
 */
final readonly class DestinationPeers
{
    /**
     * @param int $checkedHotels hotels of the destination the checks answered for
     * @param int $hotelsWithOffers of those, the ones with an offer
     * @param bool $lastCheckAnswered the destination's latest check answered at all
     */
    public function __construct(
        public int $checkedHotels = 0,
        public int $hotelsWithOffers = 0,
        public bool $lastCheckAnswered = false,
    ) {
    }

    /** At least $minPeers hotels checked and at least $sharePercent % of them with an offer. */
    public function selling(int $minPeers, int $sharePercent): bool
    {
        return $this->checkedHotels >= max(1, $minPeers)
            && $this->hotelsWithOffers * 100 >= $this->checkedHotels * $sharePercent;
    }
}
