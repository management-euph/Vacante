<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Provider-specific bridge between a hotel and its packages (board 16.2):
 * the hotel's page gets a switch above its bar, "Doar cazare / Cu
 * autocarul / Cu avionul", with a mode for each transport the hotel has.
 *
 * A provider add-on that sells packages registers an implementation with
 * TravelProviderRegistry::setPackageModesProvider(); travel_core asks
 * TravelProviderRegistry::packageModes() for a hotel's page.
 */
interface PackageModesProviderInterface
{
    /**
     * The hotel's package modes: for each transport it has ("bus",
     * "flight"), the cities it leaves from (the one most dates first).
     * Read from stored data: no API call, the page knows its modes at once.
     * A city's dates and nights come from the provider's
     * "<provider>_booking.package_dates" when the bar shows it, its offers
     * from "<provider>_booking.package_quote".
     *
     * Must return [] (not throw) when the hotel has none, or is not one of
     * this provider's.
     *
     * @param string $hotelId the provider's hotel id
     *
     * @return list<array{transport: string, departures: list<array{id: int, name: string}>}>
     */
    public function packageModes(string $hotelId): array;
}
