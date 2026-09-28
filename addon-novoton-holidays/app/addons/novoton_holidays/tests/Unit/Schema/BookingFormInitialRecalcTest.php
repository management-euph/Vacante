<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the booking form's initial-load price verification to the REAL searched
 * occupancy. Regression: the DOMContentLoaded "binding price" call hardcoded
 * children_ages: [], so the operator re-quoted for adults-only occupancy,
 * omitted the selected child-capacity room (QUAD 3+1 SEA), and the endpoint's
 * capacity-blind "first available price" fallback substituted the first
 * 2-adult room (DBL 2+0 @ -194 EUR) — popping a bogus "Camera s-a modificat"
 * modal before any DOB was typed. Also pins: the re-quoted price is committed
 * only when the room is unchanged or after the guest ACCEPTS a room change,
 * and the recalc endpoint's logging is wired to a setting key that exists.
 */
final class BookingFormInitialRecalcTest extends TestCase
{
    private const TPL_RELATIVE = '/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl';

    private static function themeTpl(string $theme): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/' . $theme . self::TPL_RELATIVE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function bookingFormJs(): string
    {
        // The behavioral JS is a real module now (vitest imports it); the
        // template keeps the markup + Smarty config and loads it via {script}.
        $path = dirname(__DIR__, 6) . '/js/addons/novoton_holidays/booking-form.js';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTemplateLoadsTheModuleAndKeepsConfigOnly(): void
    {
        $tpl = self::themeTpl('responsive');

        self::assertStringContainsString('{script src="js/addons/novoton_holidays/booking-form.js"}', $tpl);
        self::assertStringContainsString('window.bookingData', $tpl);
        self::assertStringContainsString('window.NovotonBookingI18n', $tpl);
        self::assertStringContainsString('ajaxRecalculateUrl', $tpl);
        // No behavioral JS left inline — the config assignments only.
        self::assertStringNotContainsString('function triggerPriceRecalculationInline', $tpl);
        self::assertStringNotContainsString('function validateAndCheckAge', $tpl);
    }

    public function testInitialLoadRecalcSendsCollectedChildrenAges(): void
    {
        $js = self::bookingFormJs();

        self::assertStringNotContainsString(
            'triggerPriceRecalculationInline([]',
            $js,
            'the on-load binding-price call must never hardcode an empty children list — '
            . 'it re-quotes for adults-only occupancy and triggers a bogus room substitution',
        );
        self::assertStringContainsString('function collectChildrenAges(', $js);
        // Both DOMContentLoaded branches (multi-room + single-room) pass real ages.
        self::assertStringContainsString('triggerPriceRecalculationInline(collectChildrenAges(roomNum), roomNum, true)', $js);
        self::assertStringContainsString('triggerPriceRecalculationInline(collectChildrenAges(1), 1, true)', $js);
    }

    public function testPriceCommitIsGatedOnRoomChangeDecision(): void
    {
        $js = self::bookingFormJs();

        self::assertStringContainsString('function applyRecalculatedPrice(', $js);

        // The success handler applies the price only when the room is unchanged...
        self::assertStringContainsString('applyRecalculatedPrice(data, roomNum, isMultiRoom, isInitialLoad)', $js);

        // ...and a room change defers the commit to the accept handler.
        $acceptPos = strpos($js, 'function acceptRoomChangeInline()');
        self::assertIsInt($acceptPos);
        self::assertStringContainsString(
            'applyRecalculatedPrice(',
            substr($js, $acceptPos),
            'accepting a room change must commit price + room together',
        );
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        self::assertSame(
            self::themeTpl('responsive'),
            self::themeTpl('nova_theme'),
            'the responsive and nova_theme booking_form.tpl copies must stay in sync',
        );
    }

    public function testBookingFormRendersTheSharedSummarySidebar(): void
    {
        $tpl = self::themeTpl('responsive');

        // The hotel identity, dates, occupancy, price and cancellation all
        // moved into the shared travel_core sidebar when the page became a
        // 2-column layout. The booking form owns no summary markup any more —
        // that is the whole point of sharing it with sphinx.
        self::assertStringContainsString('class="travel-booking-layout"', $tpl);
        self::assertStringContainsString('{include file="addons/travel_core/components/booking_sidebar.tpl" tbs_deposit_addon="novoton_holidays" tbs_deposit_form="novoton-booking-form"}', $tpl);
        self::assertStringNotContainsString('components/hotel_header.tpl', $tpl, 'the header now renders inside the sidebar');
        self::assertStringNotContainsString('travel-detail-row', $tpl, 'the old image|info|price row is gone');
        self::assertStringNotContainsString('$hotel_image', $tpl, 'the dead image block went with it');
        self::assertStringNotContainsString('travel-hero-location', $tpl, 'the blue-gradient hero location is gone');
        self::assertStringNotContainsString('ty-product-block-title', $tpl, 'the big PDP block title stays retired');

        // The availability pill keeps its id inside the sidebar: booking-form.js
        // rewrites #availability-badge after every re-price.
        $sidebar = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_sidebar.tpl',
        );
        self::assertStringContainsString('id="availability-badge"', $sidebar);

        // Stars render ABOVE the name (brief), unlike hotel_header.tpl which
        // puts them after it inside the h1.
        $starsPos = strpos($sidebar, 'travel-hotel-stars');
        $namePos = strpos($sidebar, 'travel-bsidebar-name');
        self::assertIsInt($starsPos);
        self::assertIsInt($namePos);
        self::assertLessThan($namePos, $starsPos);

        // Two-column grid that collapses with the summary ABOVE the form.
        $css = (string) file_get_contents(
            dirname(__DIR__, 7) . '/addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css',
        );
        $start = strpos($css, '.travel-booking-page .travel-booking-layout {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('grid-template-columns: 380px minmax(0, 1fr)', $rule);
        // Mobile: the sidebar is re-ordered ABOVE the guest form.
        $mqStart = strpos($css, '@media (max-width: 900px)');
        self::assertNotFalse($mqStart);
        $mq = substr($css, $mqStart, 400);
        self::assertStringContainsString('grid-template-columns: 1fr', $mq);
        self::assertStringContainsString('order: -1', $mq);
    }

    public function testBookingFormFeedsTheSharedHeaderViewModel(): void
    {
        // The header (map URL via the shared HotelMapUrl builder) is derived by
        // the novoton builder that BOTH modes of this page call, and handed to
        // the component under the shared variable name.
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/booking_form.php',
        );
        self::assertStringContainsString('NovotonBookingSidebarBuilder::header(', $controller);
        self::assertStringContainsString("assign('travel_hotel_header'", $controller);

        $builder = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/ViewModels/NovotonBookingSidebarBuilder.php',
        );
        self::assertStringContainsString('HotelHeaderFactory::fromSeo(', $builder);
        // The old template's city/region/country fallback rides in as the
        // factory's locationLineFallback argument.
        self::assertStringContainsString("implode(', ', array_filter([", $builder);
    }

    public function testRecalcDebugGateUsesARealSettingKey(): void
    {
        $path = dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/ajax_recalculate_price.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringNotContainsString(
            "ConfigProvider::get('debug'",
            $src,
            "the addon has no 'debug' setting — this gate is permanently off",
        );
        self::assertStringContainsString('ConfigProvider::isDebugMode()', $src);
    }

    public function testRoomSubstitutionIsLoggedUnconditionally(): void
    {
        $path = dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/ajax_recalculate_price.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString(
            'Room substitution',
            $src,
            'a room substitution must leave evidence in Administration → Logs regardless of debug settings',
        );
    }
}
