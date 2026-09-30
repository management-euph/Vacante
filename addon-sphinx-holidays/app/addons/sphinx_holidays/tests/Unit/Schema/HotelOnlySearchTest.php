<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * sphinx_booking.search is only a hotel product page's search. Without a
 * hotel it used to browse a whole destination: every hotel the API returned,
 * store product or not (booking one then failed at add-to-cart). Now there is
 * no page without a synced hotel, and the poll never hands out a whole
 * destination's offers.
 */
final class HotelOnlySearchTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/' . $rel);
    }

    public function testNoSyncedHotelMeansNoPage(): void
    {
        $search = self::read('search.php');
        $guard = strpos($search, "if (\$hotelRow === null) {\n        return [CONTROLLER_STATUS_NO_PAGE];");

        self::assertNotFalse($guard);
        self::assertLessThan((int) strpos($search, 'fn_travel_core_render_booking_engine('), $guard, 'refused before any work');
        self::assertStringNotContainsString("} elseif (\$destination_id > 0) {", $search);
    }

    public function testThePollOnlyReturnsTheSearchedHotelsOffers(): void
    {
        $poll = self::read('search_poll.php');

        self::assertStringContainsString("if (\$filterHotelId === '') {\n        // No hotel in the meta", $poll);
        self::assertStringContainsString('$results = [];', $poll);
    }
}
