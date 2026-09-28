<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * The results name the hotel's country ("Mamaia, Romania"), and a request
 * for more rooms than the picker allows is refused before any API call.
 */
final class SearchLocationAndRoomLimitTest extends TestCase
{
    private static function search(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/eurosite_booking/search.php');
    }

    public function testTheHotelLocationNamesTheCountry(): void
    {
        self::assertStringContainsString(
            "'location'     => LocationLine::placeAndCountry(\$offer->cityName, \$countryNames[\$country] ?? ''),",
            self::search(),
        );
        self::assertStringContainsString(
            '{$hotel.location|default:$hotel.city_name|escape:html}',
            (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/search.tpl'),
        );
    }

    public function testTooManyRoomsAreRefusedBeforeTheApiIsAsked(): void
    {
        $search = self::search();

        self::assertStringContainsString("if (\$roomCount > ProviderRoomLimit::maxRooms('eurosite')) {", $search);
        self::assertLessThan(
            strpos($search, 'Container::getApi()->searchHotels('),
            strpos($search, "__('eurosite.too_many_rooms'"),
        );
    }
}
