<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;

/**
 * The hotel page's package modes reach Eurosite: the provider is registered,
 * the two endpoints are routed, and the quote checks what it is asked
 * before it asks Eurosite.
 */
final class PackageModesWiringTest extends TestCase
{
    private const string ADDON = __DIR__ . '/../../..';

    public function testTheProviderIsRegistered(): void
    {
        $init = (string) file_get_contents(self::ADDON . '/init.php');

        self::assertStringContainsString('TravelProviderRegistry::setPackageModesProvider(', $init);
        self::assertStringContainsString('new \Tygh\Addons\Eurosite\Providers\EurositePackageModesProvider()', $init);
    }

    /** Travel Core asks "<provider>_booking.package_dates" and "…package_quote". */
    public function testTheEndpointsAreRouted(): void
    {
        $router = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking.php');

        self::assertStringContainsString("'package_dates' => 'package_dates.php',", $router);
        self::assertStringContainsString("'package_quote' => 'package_quote.php',", $router);
        self::assertFileExists(self::ADDON . '/controllers/frontend/eurosite_booking/package_dates.php');
        self::assertFileExists(self::ADDON . '/controllers/frontend/eurosite_booking/package_quote.php');
    }

    /** Never an arbitrary request relayed: the hotel, the departure and the rooms are checked first. */
    public function testTheQuoteChecksBeforeItAsks(): void
    {
        $quote = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/package_quote.php');
        $asks = (int) strpos($quote, '->blocks(');

        foreach (['Container::hotels()->findByProductCode($hotelId)', 'PackageDepartures::offered(', "PackageQuote::validOccupancy(\$occupancy, ProviderRoomLimit::maxRooms('eurosite'))"] as $check) {
            $at = strpos($quote, $check);
            self::assertIsInt($at, $check);
            self::assertLessThan($asks, $at, $check);
        }
        // JSON only, whatever happens.
        foreach (['package_dates.php', 'package_quote.php'] as $file) {
            $src = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/' . $file);
            self::assertStringContainsString("header('Content-Type: application/json; charset=utf-8');", $src, $file);
            self::assertStringContainsString('while (ob_get_level() > 0) {', $src, $file);
        }
    }
}
