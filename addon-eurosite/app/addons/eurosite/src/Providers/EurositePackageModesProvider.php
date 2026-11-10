<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Providers;

use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\PackageDepartures;
use Tygh\Addons\TravelCore\Contracts\PackageModesProviderInterface;

/**
 * A Eurosite hotel's package modes (board 16.2), from the package routes to
 * its city (PackageDepartures): its page gets "Cu autocarul" / "Cu avionul".
 * A city's dates come from eurosite_booking.package_dates, its offers from
 * eurosite_booking.package_quote.
 */
final class EurositePackageModesProvider implements PackageModesProviderInterface
{
    #[\Override]
    public function packageModes(string $hotelId): array
    {
        return PackageDepartures::cities(
            PackageDepartures::forHotel(strtoupper(trim($hotelId)), date('Y-m-d'), ConfigProvider::getPackageNights()),
        );
    }
}
