<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;

/**
 * What Novoton can tell Travel Core's availability rules
 * (docs/novoton-availability-gaps-plan.md §15.1): a complete hotel list per
 * country, season prices published about 18 months ahead and kept for past
 * seasons, a live check that answers for a whole resort, and its bookings.
 */
final class NovotonAvailabilitySource implements HotelAvailabilitySourceInterface
{
    public const string PROVIDER = 'novoton';

    #[\Override]
    public function provider(): string
    {
        return self::PROVIDER;
    }

    #[\Override]
    public function capabilities(): AvailabilityCapabilities
    {
        return new AvailabilityCapabilities(
            hotelList: HotelListCoverage::PerCountry,
            publishedPrices: true,
            liveCheckScope: 'resort',
            liveCheckSlow: false,
            pastPrices: true,
            bookings: true,
            // Novoton has no gate of its own: Travel Core's hides and shows its hotels.
            sharedGate: true,
        );
    }

    /**
     * The jobs that rewrite many hotels' records at once: the hotel list
     * (seen / missing), the nightly calendar rebuild (price windows and the
     * first run's backfill) and the resort check. The price syncs that run
     * every 5 minutes update one hotel's window at a time and are not listed:
     * waiting for them would leave the weekly step almost no gap to run in.
     */
    #[\Override]
    public function recordingModes(): array
    {
        return ['hotel_list', 'recompute_calendar_prices', 'room_price'];
    }
}
