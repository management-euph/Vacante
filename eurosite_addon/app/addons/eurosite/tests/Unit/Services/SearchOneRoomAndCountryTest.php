<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Eurosite books ONE room: a multi-room search is refused (it used to be
 * sent as one room holding every adult), and the hotel's location line on
 * the results names the country too ("Mamaia, Romania").
 */
final class SearchOneRoomAndCountryTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testAMultiRoomSearchIsRefusedNotPricedAsOneBigRoom(): void
    {
        $search = self::src('controllers/frontend/eurosite_booking/search.php');

        self::assertStringContainsString("\$roomCount = max(1, TypeCoerce::toInt(\$_REQUEST['rooms'] ?? 1));", $search);
        self::assertStringContainsString("if (\$roomCount > ProviderRoomLimit::maxRooms('eurosite')) {", $search);
        self::assertStringContainsString("\$searchError = __('eurosite.one_room_only');", $search);
        // The refusal comes BEFORE any API search.
        self::assertLessThan(
            strpos($search, 'Container::getApi()->searchHotels('),
            strpos($search, "__('eurosite.one_room_only')"),
        );
    }

    public function testTheHotelLocationNamesTheCountry(): void
    {
        self::assertStringContainsString(
            "'location'     => LocationLine::placeAndCountry(\$offer->cityName, \$countryNames[\$country] ?? ''),",
            self::src('controllers/frontend/eurosite_booking/search.php'),
        );
        self::assertStringContainsString(
            '{$hotel.location|default:$hotel.city_name|escape:html}',
            (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/search.tpl'),
        );
    }
}
