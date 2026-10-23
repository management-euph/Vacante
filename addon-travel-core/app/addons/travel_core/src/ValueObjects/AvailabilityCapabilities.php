<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ValueObjects;

use Tygh\Addons\TravelCore\Enums\HotelListCoverage;

/**
 * What a supplier can tell us about a hotel's availability.
 *
 * The suppliers differ (docs/novoton-availability-gaps-plan.md §15.1):
 * Novoton publishes season prices about 18 months ahead and has past seasons
 * stored; Eurosite and Sphinx publish none and start with bookings only. The
 * rules use only what exists, and the report says "not provided by Eurosite"
 * instead of leaving a cell empty.
 */
final readonly class AvailabilityCapabilities
{
    /**
     * @param HotelListCoverage $hotelList how complete one hotel-list answer is
     * @param bool $publishedPrices season prices published ahead of the stay (the "contract" window)
     * @param 'resort'|'city'|'destination' $liveCheckScope what one live-check call answers for
     * @param bool $liveCheckSlow the live check is an async search (Sphinx: polled for up to a minute)
     * @param bool $pastPrices past seasons' prices are stored, so records can be backfilled on the first run
     * @param bool $bookings confirmed bookings can be read back (the last booked stay)
     */
    public function __construct(
        public HotelListCoverage $hotelList,
        public bool $publishedPrices,
        public string $liveCheckScope,
        public bool $liveCheckSlow,
        public bool $pastPrices,
        public bool $bookings,
    ) {
    }
}
