<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the hotel page calendar's published-price window from the server to
 * the committed bundle (docs/novoton-availability-gaps-plan.md §7.1):
 * booking_config sends priceWindow, the engine hands it to both calendars
 * (the current bar and Design A's), and the calendar opens on its first date
 * and marks the days outside it.
 */
final class CalendarPriceWindowWiringTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function src(string $file): string
    {
        return (string) file_get_contents(self::addonRoot() . '/../../../react-src/src/' . $file);
    }

    public function testBookingConfigSendsTheWindowAndItsTexts(): void
    {
        $controller = (string) file_get_contents(self::addonRoot() . '/controllers/frontend/travel_booking.php');

        self::assertStringContainsString('$priceWindow = (new CalendarPriceWindow())->forHotel($providerName, $hotelId, new \DateTimeImmutable());', $controller);
        self::assertStringContainsString("\$config['priceWindow'] = \$priceWindow;", $controller);
        self::assertStringContainsString("'price_window_note', 'outside_price_window',", $controller);
    }

    public function testTheEngineHandsTheWindowToBothCalendars(): void
    {
        self::assertStringContainsString('priceWindow:         serverConfig.priceWindow || null,', self::src('index.jsx'));

        $engine = self::src('BookingEngine.jsx');
        self::assertSame(2, preg_match_all('/<Calendar\s/', $engine), 'the engine renders two calendars');
        self::assertSame(2, substr_count($engine, 'priceWindow={priceWindow}'), 'the current bar and Design A');

        $calendar = self::src('Calendar.jsx');
        self::assertStringContainsString(': opensOnPublished ? new Date(published.from.getFullYear(), published.from.getMonth(), 1)', $calendar);
        self::assertStringContainsString("if (outsideWindow) className += ' nvt-calendar-day--outside-window';", $calendar);
    }

    public function testTheCommittedBundleAndStylesCarryIt(): void
    {
        $bundle = (string) file_get_contents(self::addonRoot() . '/../../../js/addons/travel_core/react19-bundle.js');
        self::assertStringContainsString('nvt-calendar-day--outside-window', $bundle, 'rebuild: cd addon-travel-core/react-src && npm run build');
        self::assertStringContainsString('nvt-calendar-window-note', $bundle);
        self::assertStringContainsString('priceWindow', $bundle);

        $init = (string) file_get_contents(self::addonRoot() . '/init.php');
        self::assertMatchesRegularExpression("/define\\('TRAVEL_CACHE_VER', '(1[1-9]|[2-9]\\d|\\d{3,})'\\)/", $init, 'browsers fetch the new bundle');

        foreach (['responsive', 'nova_theme'] as $theme) {
            $css = (string) file_get_contents(self::addonRoot() . '/../../../design/themes/' . $theme . '/css/addons/travel_core/booking-engine.css');
            self::assertStringContainsString('.nvt-calendar-day--outside-window {', $css, $theme);
            self::assertStringContainsString('.nvt-calendar-window-note {', $css, $theme);
        }
    }
}
