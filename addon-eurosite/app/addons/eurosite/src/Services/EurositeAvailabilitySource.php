<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;

/**
 * What Eurosite can tell Travel Core's availability rules
 * (docs/novoton-availability-gaps-plan.md §15.1): its own hotels, listed per
 * city; no season prices published ahead; a check that answers for a whole
 * city per date (Immediate, On request or Stop sale); and its bookings.
 * Nothing older can be filled in, so its records start when recording starts.
 */
final class EurositeAvailabilitySource implements HotelAvailabilitySourceInterface
{
    public const string PROVIDER = 'eurosite';

    #[\Override]
    public function provider(): string
    {
        return self::PROVIDER;
    }

    #[\Override]
    public function capabilities(): AvailabilityCapabilities
    {
        return new AvailabilityCapabilities(
            hotelList: HotelListCoverage::PerCity,
            publishedPrices: false,
            liveCheckScope: 'city',
            liveCheckSlow: false,
            pastPrices: false,
            bookings: true,
        );
    }

    /**
     * The hotel list (seen / missing), the nightly availability check, and
     * `full`, which runs the hotel list among its steps.
     */
    #[\Override]
    public function recordingModes(): array
    {
        return ['hotels', 'availability', 'full'];
    }
}
