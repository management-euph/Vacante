<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Audit C2/H4: add_to_cart priced one tuple (the request's room, board and
 * adults plus every child of every room), accepted the posted total_price
 * at or above it, and stored the posted rooms_data — from which the
 * reservation is built. Now every room of rooms_data is checked against
 * the guests (BookedRooms) and priced (RoomsQuote), the customer pays the
 * sum, and rooms_data is stored as priced. The pricing itself is covered by
 * BookedRoomsTest / RoomsQuoteTest; this pins the controller's wiring.
 */
final class AddToCartPricesEveryRoomTest extends TestCase
{
    private static function controller(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/add_to_cart.php');
    }

    public function testEveryBookedRoomIsPricedAndTheSumIsCharged(): void
    {
        $src = self::controller();

        self::assertStringContainsString('BookedRooms::fromRequest($bookingData, $guests_data)', $src);
        self::assertStringContainsString('RoomsQuote($api->pricing()))->price(', $src);
        self::assertStringContainsString('$api_price = $quote[\'total\'];', $src);
        self::assertStringContainsString('$total_price = $api_price;', $src);
        // The old single-tuple quote is gone
        self::assertStringNotContainsString('getRoomPrice($priceParams)', $src);
    }

    public function testThePostedPricesAreNeverCharged(): void
    {
        $src = self::controller();

        // total_price is read once, as the price the customer saw
        self::assertSame(1, substr_count($src, "\$bookingData['total_price']"));
        self::assertStringContainsString("\$customer_visible_price = TypeCoerce::toFloat(\$bookingData['total_price'] ?? 0);", $src);
        // The posted rooms_data is read only by BookedRooms: stored rooms are the priced ones
        self::assertStringNotContainsString("\$bookingData['rooms_data']", $src);
        self::assertStringContainsString('$rooms_data[] = $priced_room + [', $src);
    }

    public function testAnUnpricedOrInconsistentBookingIsRejected(): void
    {
        $src = self::controller();

        $failure = strpos($src, 'if ($quote === null) {');
        self::assertIsInt($failure);
        $branch = substr($src, $failure, 2500);
        self::assertStringContainsString('novoton_holidays.price_verification_failed', $branch);
        self::assertStringContainsString('return [CONTROLLER_STATUS_REDIRECT, $return_url];', $branch);
        // A multi-room customer goes back to a multi-room form
        self::assertStringContainsString("\$return_params['rooms_data'] = json_encode(\$booked_rooms);", $branch);
    }

    /**
     * The room-change modal of a multi-room form changes a room: the hidden
     * rooms_data the server prices and books must carry the change, else
     * the old room is priced at the new child age and the add is rejected.
     */
    public function testARoomChangedOnTheFormIsPosted(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 6) . '/js/addons/novoton_holidays/booking-form.js');
        $start = strpos($js, 'function acceptRoomChangeInline()');
        self::assertIsInt($start);
        $end = strpos($js, "\nfunction ", $start + 1);
        $accept = substr($js, $start, $end === false ? null : $end - $start);

        self::assertStringContainsString('window.bookingData.roomsData[idx].room_id = data.new_room', $accept);
        self::assertStringContainsString("document.querySelector('input[name=\"rooms_data\"]')", $accept);
        self::assertStringContainsString('roomsDataInput.value = JSON.stringify(window.bookingData.roomsData);', $accept);
    }

    public function testEveryRoomGetsItsOwnSilentSyncEntry(): void
    {
        $src = self::controller();

        self::assertStringContainsString('PreOrderPriceVerifier::roomLines($priced_extra)', $src);
        self::assertStringContainsString('PreOrderPriceVerifier::priceCacheKey($room_line)', $src);
    }

    /**
     * The Silent Sync entries are keyed on the priced rooms' own ages, not
     * on the posted guests: a guest whose age is not the priced one (an
     * aliased key, a later edit) misses the cache and is re-priced at
     * checkout instead of trusting add_to_cart's price.
     */
    public function testTheSilentSyncKeyIsThePricedRoomsNotThePostedGuests(): void
    {
        $src = self::controller();

        $start = strpos($src, '$priced_extra = $product[\'extra\'];');
        self::assertIsInt($start);
        $unset = strpos($src, "unset(\$priced_extra['guests_data']);", $start);
        $lines = strpos($src, 'PreOrderPriceVerifier::roomLines($priced_extra)', $start);
        self::assertIsInt($unset);
        self::assertIsInt($lines);
        self::assertLessThan($lines, $unset);
    }

    /**
     * Single-room form: accepting the room-change modal must also change
     * rooms_data[0], which the server prices and books when it names a room.
     */
    public function testARoomChangedOnASingleRoomFormIsPosted(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 6) . '/js/addons/novoton_holidays/booking-form.js');
        $start = strpos($js, 'function acceptRoomChangeInline()');
        self::assertIsInt($start);
        $end = strpos($js, "\nfunction ", $start + 1);
        $accept = substr($js, $start, $end === false ? null : $end - $start);

        self::assertStringContainsString('singleRooms[0].room_id = data.new_room', $accept);
        self::assertStringContainsString('singleRoomsInput.value = JSON.stringify(singleRooms);', $accept);
    }
}
