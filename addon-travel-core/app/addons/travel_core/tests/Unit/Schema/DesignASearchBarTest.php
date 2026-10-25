<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Design A search bar (docs/hotel-page-design-a-plan.md, phase 2):
 *
 *  - the engine recognises a Design A page by its mount's ancestor
 *    ([data-travel-design="a"], travelcore_design_a.tpl) and asks
 *    booking_config with design=a;
 *  - booking_config then adds per-night calendar prices for two adults from
 *    the owning supplier's indicative price source, in the shopper's
 *    currency, and the bar's texts;
 *  - the engine renders separate Check-in / Check-out fields and
 *    "Vezi prețurile", and the calendar its stay header and footer — only
 *    for design "a", so the current hotel page and the search pages keep
 *    their bar;
 *  - CI never rebuilds the bundle, so the committed one must carry it.
 */
final class DesignASearchBarTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function src(string $file): string
    {
        return (string) file_get_contents(self::addonRoot() . '/../../../react-src/src/' . $file);
    }

    public function testEngineAsksForTheDesignAConfigFromADesignAPage(): void
    {
        $index = self::src('index.jsx');

        self::assertStringContainsString('el.closest(\'[data-travel-design="a"]\')', $index);
        self::assertStringContainsString('&design=${encodeURIComponent(design)}', $index);
        self::assertStringContainsString('calendarPrices:      serverConfig.calendarPrices || null', $index);
        self::assertStringContainsString("design:              serverConfig.design || ''", $index);
    }

    public function testBookingConfigAddsCalendarPricesOnlyForDesignA(): void
    {
        $controller = (string) file_get_contents(self::addonRoot() . '/controllers/frontend/travel_booking.php');

        self::assertStringContainsString("if (RequestCoerce::string(\$_REQUEST, 'design') === 'a') {", $controller);
        self::assertStringContainsString('TravelProviderRegistry::indicativeNightlyCalendar($providerName, $hotelId)', $controller);
        self::assertStringContainsString("\$config['calendarPricesCurrency']", $controller);
        foreach (['add_date', 'see_prices', 'clear_dates', 'change_guests', 'lowest_prices', 'nightly_prices_note'] as $key) {
            self::assertStringContainsString("'" . $key . "'", $controller, $key . ' is sent to the engine');
        }

        $langKeys = require self::addonRoot() . '/lang_keys.php';
        self::assertIsArray($langKeys);
        self::assertSame('Vezi prețurile', $langKeys['travel_core.see_prices']['ro'] ?? null);
        self::assertSame('Adaugă data', $langKeys['travel_core.add_date']['ro'] ?? null);
    }

    public function testSplitFieldsAndCalendarFooterAreDesignAOnly(): void
    {
        $engine = self::src('BookingEngine.jsx');
        self::assertStringContainsString("const isDesignA = design === 'a';", $engine);
        self::assertStringContainsString("{isDesignA ? (\n                    ['checkIn', 'checkOut'].map((field) => {", $engine);
        self::assertStringContainsString("if (isDesignA) return t('seePrices', 'See prices');", $engine);
        self::assertStringContainsString('variant="a"', $engine);

        $calendar = self::src('Calendar.jsx');
        self::assertStringContainsString("const isVariantA = variant === 'a';", $calendar);
        self::assertStringContainsString('nvt-calendar-popup--a', $calendar);
        self::assertStringContainsString('onClick={clearDates}', $calendar);
        // The current calendar keeps its footer for every other page.
        self::assertStringContainsString('<div className="nvt-calendar-footer">', $calendar);
    }

    public function testCommittedBundleCarriesTheDesignABar(): void
    {
        $bundle = (string) file_get_contents(dirname(__DIR__, 6) . '/js/addons/travel_core/react19-bundle.js');
        self::assertStringContainsString('nvt-calendar-popup--a', $bundle, 'rebuild: cd addon-travel-core/react-src && npm run build');
        self::assertStringContainsString('data-travel-design="a"', $bundle);
        self::assertStringContainsString('nvt-field--date-split', $bundle);

        $init = (string) file_get_contents(self::addonRoot() . '/init.php');
        self::assertMatchesRegularExpression("/define\\('TRAVEL_CACHE_VER', '(9|[1-9]\\d+)'\\)/", $init, 'browsers fetch the new bundle');
    }

    public function testDesignAStylesTheBarAndCalendar(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/css/addons/travel_core/design-a.css');
        self::assertStringContainsString('.travel-pdpa .nvt-calendar-popup--a {', $css);
        self::assertStringContainsString('.travel-pdpa .nvt-field-input.nvt-field-input--active {', $css);
        self::assertStringContainsString('.travel-pdpa .nvt-calendar-actions {', $css);
    }
}
