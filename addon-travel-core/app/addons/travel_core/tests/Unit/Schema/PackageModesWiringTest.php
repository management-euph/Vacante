<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A hotel's page with its packages (board 16.2): the page's config carries
 * the hotel's modes and the provider's three endpoints; the engine puts the
 * switch above the hotel bar, which stays mounted ("Doar cazare" is the page
 * as it was); a transport shows the package bar and its offers and hides
 * the accommodation results; the title row's buttons follow the mode.
 */
final class PackageModesWiringTest extends TestCase
{
    private static function addon(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private static function repo(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/' . $path);
    }

    public function testBookingConfigCarriesTheHotelsModes(): void
    {
        $controller = self::addon('controllers/frontend/travel_booking.php');

        self::assertStringContainsString('$packageModes = TravelProviderRegistry::packageModes($providerName, $hotelId);', $controller);
        self::assertStringContainsString("'datesDispatch' => \$providerName . '_booking.package_dates',", $controller);
        self::assertStringContainsString("'quoteDispatch' => \$providerName . '_booking.package_quote',", $controller);
        self::assertStringContainsString("'bookingDispatch' => \$providerName . '_booking.package_booking_form',", $controller);
        self::assertLessThan(
            strpos($controller, '$packageModes = TravelProviderRegistry::packageModes('),
            strpos($controller, 'if (DesignA::requested($_REQUEST)) {'),
            'on the Design A page (board 16.2)',
        );
        foreach (['package_stay_only', 'package_by_bus', 'package_by_plane', 'package_nights', 'package_rooms_title', 'package_loading_rooms', 'pdpa_r_immediate'] as $key) {
            self::assertStringContainsString("'{$key}'", $controller, $key . ' reaches the engine');
        }
    }

    public function testTheEngineShowsTheSwitchForAHotelWithPackages(): void
    {
        $index = self::repo('react-src/src/index.jsx');
        $modes = self::repo('react-src/src/HotelModes.jsx');
        $bar = self::repo('react-src/src/PackageBar.jsx');

        self::assertStringContainsString('<HotelModes hotelConfig={config} packageConfig={pkg} />', $index);
        self::assertStringContainsString('<div hidden={mode !== \'\'}>', $modes, 'the hotel bar stays mounted');
        self::assertStringContainsString('<BookingEngine config={hotelConfig} />', $modes);
        self::assertStringContainsString("page.classList.toggle('travel-pdp-results--package', mode !== '')", $modes);
        self::assertStringContainsString('fetch(packageDatesUrl(base, datesDispatch, hotelId, transport, departureId)', $bar);
        self::assertStringContainsString('fetchQuote(packageQuoteUrl(base, quoteDispatch, choice), signal)', $bar);
        self::assertStringContainsString('if (dropped) dropped.abort();', $bar, 'a dropped quote is aborted');
        self::assertStringContainsString('<PackageRooms quote={quote} bookHref={bookHref} />', $bar);
        self::assertStringContainsString('href={bookHref(offer)}', self::repo('react-src/src/PackageRooms.jsx'));
    }

    public function testTheBundleAndTheStylesFollow(): void
    {
        $bundle = self::repo('js/addons/travel_core/react19-bundle.js');
        foreach (['travel-modes__switch', 'travel-pdp-results--package', 'nvt-package-bar', 'travel-package-room__name'] as $needle) {
            self::assertStringContainsString($needle, $bundle, $needle . ': rebuild the bundle (npm run build)');
        }
        foreach (['responsive', 'nova_theme'] as $theme) {
            $css = self::repo("design/themes/{$theme}/css/addons/travel_core/booking-engine.css");
            self::assertStringContainsString('.travel-pdp-results--package > :not(.travel-search-form-wrapper) {', $css, $theme);
            self::assertStringContainsString('.travel-modes__option--selected,', $css, $theme);
        }
    }

    public function testTheTitleRowFollowsTheMode(): void
    {
        $page = self::repo('js/addons/travel_core/pdp-design-a.js');

        self::assertStringContainsString("if (root.closest('.travel-pdp-results--package')) {", $page, '"Alege datele" does not open the hidden hotel calendar');
        // The sticky header's "Alege cameră" counts a package's room types among the rooms.
        self::assertStringContainsString("var ROOM_OPTIONS = '[data-travel-room], .travel-offer-card, .travel-package-room, .travel-circuit-rooms .travel-circuit-offer';", $page);
    }
}
