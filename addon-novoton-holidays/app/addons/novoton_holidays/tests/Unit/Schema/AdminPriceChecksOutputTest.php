<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The admin price and package checks print their progress as HTML. The
 * country from the request was printed as it came, so a crafted link opened
 * by an admin ran its script there; the supplier's hotel names were printed
 * the same way. A country now has to be one the add-on knows, and every
 * name is escaped.
 */
final class AdminPriceChecksOutputTest extends TestCase
{
    private static function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    public function testOnlyKnownCountriesAreCheckedAndPrinted(): void
    {
        $prices = self::read('controllers/backend/novoton_prices.php');

        self::assertSame(2, substr_count($prices, '$selected_countries = PriceCheckCountries::fromRequest($_REQUEST);'), 'both price checks');
        self::assertSame(2, substr_count($prices, "echo '<br><strong>' . htmlspecialchars(\$country) . ' summary:</strong> ';"));
        self::assertStringNotContainsString('{$country}', $prices);
    }

    public function testTheSuppliersNamesAreEscaped(): void
    {
        $packages = self::read('controllers/backend/novoton_hotels.php');

        self::assertStringNotContainsString('{$hotel_name}', $packages);
        self::assertStringNotContainsString('{$country}', $packages);
        self::assertSame(3, substr_count($packages, "htmlspecialchars(\$hotel_id) . ' | ' . htmlspecialchars(\$hotel_name)"));

        // The per-hotel check escapes its names where it reads them.
        $prices = self::read('controllers/backend/novoton_prices.php');
        self::assertStringContainsString("\$hotel_name = htmlspecialchars(TypeCoerce::toString(\$hotel['hotel_name'] ?? ''));", $prices);
        self::assertStringContainsString("\$city = htmlspecialchars(TypeCoerce::toString(\$hotel['city'] ?? '') ?: '(no city)');", $prices);
    }

    /**
     * A new store does not label its reservations "test, do not proceed"
     * and does not log travellers' names and dates of birth.
     */
    public function testANewStoreStartsWithoutTestBookingsAndDebugLogs(): void
    {
        $xml = self::read('addon.xml');
        foreach (['test_booking', 'debug_logging', 'debug_mode'] as $setting) {
            self::assertSame(1, preg_match('#<item id="' . $setting . '">\s*<type>checkbox</type>\s*<default_value>N</default_value>#', $xml), $setting);
        }
        self::assertStringContainsString("(self::settings()['debug_logging'] ?? 'N') === 'Y'", self::read('src/Services/ConfigProvider.php'));
    }
}
