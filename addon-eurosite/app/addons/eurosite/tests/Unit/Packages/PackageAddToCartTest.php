<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Install\SchemaMigrator;
use Tygh\Addons\Eurosite\Services\BookingGuests;
use Tygh\Addons\Eurosite\Services\OrderCardFacts;
use Tygh\Addons\Eurosite\Tests\Support\CannedTransport;

/**
 * Booking a package: the travellers both forms read the same way, the row's
 * package columns, the PackageId AddBookingRequest sends, and the order
 * card that says "package".
 */
final class PackageAddToCartTest extends TestCase
{
    private const string ADDON = __DIR__ . '/../../..';

    /** @return array<string, array<string, string>> */
    private static function posted(): array
    {
        return [
            'room1_adult_1' => ['first_name' => ' Ana ', 'last_name' => 'Pop', 'type' => 'adult', 'gender' => 'f', 'dob' => '14/03/1988', 'room' => '1', 'is_holder' => '1'],
            'room1_adult_2' => ['first_name' => 'Mihai', 'last_name' => 'Pop', 'type' => 'adult', 'gender' => 'x', 'dob' => '02/11/1985', 'room' => '1'],
            'room1_child_1' => ['first_name' => 'Ioana', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7', 'dob' => '05/05/2020', 'room' => '1'],
        ];
    }

    // ── The travellers ──────────────────────────────────────────────────

    public function testTheCardsBecomeEurositesTravellers(): void
    {
        $parsed = BookingGuests::parse(self::posted());

        self::assertFalse($parsed['invalid']);
        self::assertSame('Pop / Ana', $parsed['holder']);
        self::assertSame([
            ['type' => 'adult', 'first_name' => 'Ana', 'last_name' => 'Pop', 'name' => 'Pop / Ana', 'gender' => 'F', 'dob' => '1988-03-14', 'room' => 1],
            // A gender that is neither B nor F reads as B, as the hotel form has it.
            ['type' => 'adult', 'first_name' => 'Mihai', 'last_name' => 'Pop', 'name' => 'Pop / Mihai', 'gender' => 'B', 'dob' => '1985-11-02', 'room' => 1],
            ['type' => 'child', 'first_name' => 'Ioana', 'last_name' => 'Pop', 'name' => 'Pop / Ioana', 'gender' => 'C', 'dob' => '2020-05-05', 'room' => 1, 'age' => 7],
        ], $parsed['guests']);
    }

    public function testACardLeftIncompleteIsRefused(): void
    {
        foreach ([
            'no name' => ['first_name' => '', 'last_name' => 'Pop', 'type' => 'adult', 'dob' => '14/03/1988'],
            'no surname' => ['first_name' => 'Ana', 'last_name' => ' ', 'type' => 'adult', 'dob' => '14/03/1988'],
            'a date that is not one' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'dob' => 'ieri'],
            'a child without one' => ['first_name' => 'Ioana', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7', 'dob' => ''],
        ] as $what => $guest) {
            $parsed = BookingGuests::parse(['room1_1' => $guest]);
            self::assertTrue($parsed['invalid'], $what);
            self::assertSame([], $parsed['guests'], $what);
        }
        // An adult may leave it out here; the package form asks for it itself.
        self::assertFalse(BookingGuests::parse(['room1_adult_1' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'dob' => '']])['invalid']);
    }

    public function testEachRoomMustHoldWhatItWasPricedFor(): void
    {
        $guests = BookingGuests::parse(self::posted())['guests'];

        self::assertTrue(BookingGuests::roomsMatch($guests, [['adults' => 2, 'children_ages' => [7]]]));
        self::assertFalse(BookingGuests::roomsMatch($guests, [['adults' => 2, 'children_ages' => []]]), 'a child too many');
        self::assertFalse(BookingGuests::roomsMatch($guests, [['adults' => 3, 'children_ages' => [7]]]), 'an adult missing');
        self::assertFalse(BookingGuests::roomsMatch($guests, [['adults' => 2, 'children_ages' => [7]], ['adults' => 1, 'children_ages' => []]]), 'a room with nobody');
    }

    public function testAChildsRealAgeAtCheckIn(): void
    {
        $guests = BookingGuests::parse(self::posted())['guests'];
        $rooms = [['adults' => 2, 'children_ages' => [7]]];

        // Born 05.05.2020, checking in 10.07.2027: 7, as priced.
        $ok = BookingGuests::ageCheck($guests, '2027-07-10', $rooms);
        self::assertNull($ok['mismatch']);
        self::assertSame([['adults' => 2, 'children_ages' => [7]]], $ok['corrected']);

        // Checking in 02.05.2027, three days before the birthday: 6.
        $off = BookingGuests::ageCheck($guests, '2027-05-02', $rooms);
        self::assertSame(['name' => 'Pop / Ioana', 'declared' => 7, 'actual' => 6], $off['mismatch']);
        self::assertSame([['adults' => 2, 'children_ages' => [6]]], $off['corrected'], 'the search runs again with the real age');

        // The birthday itself counts as the new age.
        self::assertNull(BookingGuests::ageCheck(
            BookingGuests::parse(['room1_child_1' => ['first_name' => 'Ioana', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7', 'dob' => '05/05/2020', 'room' => '1']])['guests'],
            '2027-05-05',
            [['adults' => 0, 'children_ages' => [7]]],
        )['mismatch']);
    }

    // ── The booking ─────────────────────────────────────────────────────

    public function testAddBookingSendsThePackageIdWhereTheSpecHasIt(): void
    {
        $transport = new CannedTransport('<?xml version="1.0" encoding="utf-8"?><Response ResponseType="AddBookingResponse">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><AddBookingResponse><BookingReferences>'
            . '<BookingReference Source="api">EU_XML_1</BookingReference><BookingReference Source="client">ES1</BookingReference>'
            . '</BookingReferences></AddBookingResponse></ResponseDetails></Response>');
        $api = new EurositeApiClient($transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA');

        $booking = [
            'currency' => 'EUR', 'booking_name' => 'ES1', 'client_id' => 'ES1', 'country_code' => 'TR', 'city_code' => 'TRALN',
            'product_code' => 'TR0012', 'variant_id' => '1|1068746_669_1', 'check_in' => '2027-07-03', 'check_out' => '2027-07-10',
            'tourop_code' => 'EU', 'rooms' => [['code' => '274', 'adults' => 2, 'children' => [], 'pax' => [
                ['type' => 'adult', 'name' => 'Pop / Ana', 'gender' => 'F', 'dob' => '1988-03-14'],
            ]]],
        ];
        $result = $api->addBooking(['package_id' => '4'] + $booking);

        self::assertTrue($result['ok']);
        self::assertSame('EU_XML_1', $result['api_ref']);
        self::assertStringContainsString(
            '<CheckOut>2027-07-10</CheckOut></PeriodOfStay><PackageId>4</PackageId><VariantId>1|1068746_669_1</VariantId>',
            $transport->lastRequest,
        );
        self::assertStringContainsString('<PaxName PaxType="adult" TGender="F" DOB="1988-03-14">Pop / Ana</PaxName>', $transport->lastRequest);

        // A stay sends none.
        $api->addBooking($booking);
        self::assertStringNotContainsString('<PackageId>', $transport->lastRequest);
    }

    /** The row keeps what a package is booked on; a store with the add-on gets the columns. */
    public function testTheBookingRowsPackageColumns(): void
    {
        $xml = (string) file_get_contents(self::ADDON . '/addon.xml');
        foreach (['package_id', 'transport', 'dep_city_code', 'departure_date'] as $column) {
            self::assertStringContainsString('`' . $column . '`', $xml, $column);
            self::assertArrayHasKey($column, SchemaMigrator::BOOKING_COLUMNS, $column);
        }
        self::assertStringContainsString('AFTER `series_id`', SchemaMigrator::BOOKING_COLUMNS['package_id']);
    }

    public function testTheSubmissionPassesThePackageIdOn(): void
    {
        $src = (string) file_get_contents(self::ADDON . '/src/Services/BookingSubmissionService.php');

        self::assertStringContainsString("'package_id' => TypeCoerce::toString(\$booking['package_id'] ?? ''),", $src);
    }

    // ── The order card ──────────────────────────────────────────────────

    public function testTheOrderCardSaysPackageAndHowItTravels(): void
    {
        $row = [
            'api_ref' => 'EU_XML_1', 'client_ref' => 'ES1', 'status' => 'pending', 'order_id' => 5,
            'total_price' => 1321.6, 'currency' => 'EUR', 'package_id' => '4', 'transport' => 'FLIGHT',
        ];
        $facts = OrderCardFacts::fromRow(9, $row);

        self::assertSame(['package', 'flight'], [$facts['kind'], $facts['transport']]);
        self::assertSame(['EU_XML_1', 'ES1'], [$facts['reference'], $facts['our_reference']]);
        self::assertSame(['amount' => 1321.6, 'currency' => 'EUR'], $facts['supplier_price']);

        // A stay is no package.
        $stay = OrderCardFacts::fromRow(9, ['api_ref' => 'X', 'status' => 'confirmed', 'package_id' => '', 'transport' => ''] + $row);
        self::assertArrayNotHasKey('kind', $stay);
        self::assertArrayNotHasKey('transport', $stay);
    }

    // ── The page ────────────────────────────────────────────────────────

    public function testTheControllerIsRoutedAndReadsOnlyItsSnapshot(): void
    {
        $router = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking.php');
        $src = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/package_add_to_cart.php');

        self::assertStringContainsString("'package_add_to_cart' => 'package_add_to_cart.php',", $router);
        self::assertStringContainsString("if (\$_SERVER['REQUEST_METHOD'] !== 'POST')", $src, 'a GET books nothing');
        self::assertStringContainsString('PackageSnapshotStore::get($packageKey)', $src);
        // The price and the ids are the snapshot's, never the POST's.
        self::assertStringContainsString("'total_price' => round(TypeCoerce::toFloat(\$snapshot['price']) + \$extrasTotal, 2),", $src);
        self::assertStringContainsString("'package_id' => TypeCoerce::toString(\$snapshot['package_id']),", $src);
        self::assertStringNotContainsString('RequestCoerce::float', $src);
        // The cart line is a package's.
        self::assertStringContainsString("'booking_type' => 'package',", $src);
        self::assertStringContainsString("'stored_price' => 'Y',", $src);
        // Every traveller's date of birth, and the shared reading.
        self::assertStringContainsString('BookingGuests::parse($_POST[\'guests\'] ?? null)', $src);
        self::assertStringContainsString("__('eurosite.package_dob_required')", $src);

        $lang = require self::ADDON . '/lang_keys.php';
        self::assertArrayHasKey('eurosite.package_dob_required', $lang);
    }

    /** The stay's own add-to-cart reads the travellers the same way now. */
    public function testTheHotelFormUsesTheSameReading(): void
    {
        $src = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/add_to_cart.php');

        self::assertStringContainsString('BookingGuests::parse($_POST[\'guests\'] ?? null)', $src);
        self::assertStringContainsString('BookingGuests::roomsMatch($guests, $occupancy)', $src);
        self::assertStringContainsString('BookingGuests::ageCheck($guests,', $src);
    }
}
