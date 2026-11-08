<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Providers;

use Tygh\Addons\SphinxHolidays\Services\PackageDepartures;
use Tygh\Addons\TravelCore\Contracts\PackageModesProviderInterface;

/**
 * A Sphinx hotel's package modes (board 16.2), from the saved routes
 * (PackageDepartures): its page gets "Cu autocarul" / "Cu avionul". A
 * city's dates come from sphinx_booking.package_dates, its offers from
 * sphinx_booking.package_quote.
 */
final class SphinxPackageModesProvider implements PackageModesProviderInterface
{
    #[\Override]
    public function packageModes(string $hotelId): array
    {
        return PackageDepartures::cities(PackageDepartures::forHotel($hotelId, date('Y-m-d')));
    }
}
