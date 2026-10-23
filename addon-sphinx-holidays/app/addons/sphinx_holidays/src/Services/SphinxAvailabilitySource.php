<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;

/**
 * What Sphinx can tell Travel Core's availability rules
 * (docs/novoton-availability-gaps-plan.md §15.1): its whole hotel list only
 * in a full sync (the daily one brings the hotels changed since the last);
 * no season prices published ahead; a search that answers for a whole
 * destination but is slow (polled for up to a minute); and its bookings.
 * Nothing older can be filled in, so its records start when recording starts.
 */
final class SphinxAvailabilitySource implements HotelAvailabilitySourceInterface
{
    public const string PROVIDER = 'sphinx';

    #[\Override]
    public function provider(): string
    {
        return self::PROVIDER;
    }

    #[\Override]
    public function capabilities(): AvailabilityCapabilities
    {
        return new AvailabilityCapabilities(
            hotelList: HotelListCoverage::FullSyncOnly,
            publishedPrices: false,
            liveCheckScope: 'destination',
            liveCheckSlow: true,
            pastPrices: false,
            bookings: true,
        );
    }

    /**
     * The hotel sync, which also runs the availability gate's searches, and
     * `full`, which runs the hotel sync among its steps.
     */
    #[\Override]
    public function recordingModes(): array
    {
        return ['hotels', 'full'];
    }
}
