<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\RoomOfferRows;

/**
 * Pins the price of the package the customer clicked.
 *
 * The fixture is a live room_price answer (hotel 476, DBL 2+0 DELUXE NO
 * BALCONY, 5-11 Oct 2026, 2 adults) in Novoton's FLAT layout: every offer is
 * a run of siblings under <room_price> starting at <agent>. Three offers:
 * "+BEACH" 795 (ULTRA ALL INCL), the early-booking "+BEACH [STAY 18.09 -
 * 31.10]" 600 (ULTRA ALL INCL) and "BB/HB [STAY 22.09 - 19.12]" 675 (BB).
 *
 * The regression: the customer clicked the 795 "+BEACH" card, the booking
 * page re-priced it to the cheapest row of ANY package (600) and the
 * Place-order check "corrected" it to the first <Price> (795).
 */
#[CoversClass(RoomOfferRows::class)]
final class RoomOfferRowsTest extends TestCase
{
    private const ROOM = 'DBL 2+0 DELUXE NO BALCONY';
    private const UAI = 'ULTRA ALL INCL';
    private const BEACH = 'ADMIRAL ***** +BEACH';
    private const BEACH_EB = 'ADMIRAL ***** +BEACH [STAY 18.09 - 31.10]';

    private static function fixture(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        return $xml;
    }

    public function testTheFlatAnswerSplitsIntoOneRowPerOffer(): void
    {
        $rows = RoomOfferRows::fromXml(self::fixture());

        self::assertSame([
            ['price' => 795.0, 'room' => self::ROOM, 'board' => self::UAI, 'package' => self::BEACH, 'early_booking' => 0.0, 'idcont' => '1280335'],
            ['price' => 600.0, 'room' => self::ROOM, 'board' => self::UAI, 'package' => self::BEACH_EB, 'early_booking' => 25.0, 'idcont' => '1296501'],
            ['price' => 675.0, 'room' => self::ROOM, 'board' => 'BB', 'package' => 'ADMIRAL ***** BB/HB [STAY 22.09 - 19.12]', 'early_booking' => 0.0, 'idcont' => '1292618'],
        ], $rows);
    }

    /** The clicked "+BEACH" card stays at 795 — not the EB package's 600. */
    public function testThePackagePinsThePrice(): void
    {
        $rows = RoomOfferRows::fromXml(self::fixture());

        self::assertSame(795.0, RoomOfferRows::cheapest($rows, self::ROOM, self::UAI, self::BEACH)['price'] ?? null);
        self::assertSame(600.0, RoomOfferRows::cheapest($rows, self::ROOM, self::UAI, self::BEACH_EB)['price'] ?? null);
    }

    public function testPackageMatchingIgnoresCaseUrlEncodingAndALostPlus(): void
    {
        $rows = RoomOfferRows::fromXml(self::fixture());

        self::assertSame(795.0, RoomOfferRows::cheapest($rows, self::ROOM, 'ultra all incl', 'admiral ***** +beach')['price'] ?? null);
        self::assertSame(795.0, RoomOfferRows::cheapest($rows, self::ROOM, self::UAI, rawurlencode(self::BEACH))['price'] ?? null);
        // "+" decoded to a space by a form post is still the same package.
        self::assertSame(795.0, RoomOfferRows::cheapest($rows, self::ROOM, self::UAI, 'ADMIRAL *****  BEACH')['price'] ?? null);
    }

    /** No package asked for: the cheapest offer of that room + board. */
    public function testWithoutAPackageTheCheapestRoomBoardOfferWins(): void
    {
        $best = RoomOfferRows::cheapest(RoomOfferRows::fromXml(self::fixture()), self::ROOM, self::UAI);

        self::assertSame(600.0, $best['price'] ?? null);
        self::assertSame(self::BEACH_EB, $best['package'] ?? null);
    }

    /** The BB offer (675) is never taken for an ULTRA ALL INCL booking. */
    public function testTheBoardFilterIgnoresOtherBoards(): void
    {
        $rows = RoomOfferRows::fromXml(self::fixture());

        self::assertSame(675.0, RoomOfferRows::cheapest($rows, self::ROOM, 'BB')['price'] ?? null);
        self::assertCount(2, RoomOfferRows::matching($rows, self::ROOM, self::UAI));
        self::assertNull(RoomOfferRows::cheapest($rows, self::ROOM, 'BB', self::BEACH));
    }

    public function testAPackageNotInTheAnswerMatchesNothing(): void
    {
        self::assertNull(RoomOfferRows::cheapest(RoomOfferRows::fromXml(self::fixture()), self::ROOM, self::UAI, 'ADMIRAL ***** HB ONLY'));
    }

    public function testAnotherRoomMatchesNothing(): void
    {
        self::assertNull(RoomOfferRows::cheapest(RoomOfferRows::fromXml(self::fixture()), 'SGL', self::UAI));
    }

    /** One element per offer (the layout QuoteOfferReader reads). */
    public function testTheNestedLayoutReadsOneRowPerElement(): void
    {
        $xml = new \SimpleXMLElement('<room_prices>'
            . '<room_price><IdRoom>DBL%2bSEA</IdRoom><IdBoard>AI</IdBoard><Price>1200</Price><PackageName>Summer%20A</PackageName></room_price>'
            . '<room_price><IdRoom>DBL%2bSEA</IdRoom><Board>AI</Board><Price>1030</Price><early_booking>10</early_booking><PackageName>Summer B</PackageName></room_price>'
            . '<room_price><IdRoom>DBL%2bSEA</IdRoom><Board>AI</Board><Price>0</Price></room_price>'
            . '</room_prices>');

        $rows = RoomOfferRows::fromXml($xml);

        self::assertSame([
            ['price' => 1200.0, 'room' => 'DBL+SEA', 'board' => 'AI', 'package' => 'Summer A', 'early_booking' => 0.0, 'idcont' => ''],
            ['price' => 1030.0, 'room' => 'DBL+SEA', 'board' => 'AI', 'package' => 'Summer B', 'early_booking' => 10.0, 'idcont' => ''],
        ], $rows, 'a zero price is no offer');
        self::assertSame(1200.0, RoomOfferRows::cheapest($rows, 'DBL+SEA', 'AI', 'Summer A')['price'] ?? null);
    }

    /** A single result: the root element is the one offer. */
    public function testASingleResultIsItsOwnRow(): void
    {
        $rows = RoomOfferRows::fromXml(new \SimpleXMLElement(
            '<room_price><agent>X</agent><PackageName>P</PackageName><Price>800</Price><IdRoom>DBL</IdRoom><IdBoard>HB</IdBoard></room_price>',
        ));

        self::assertSame([['price' => 800.0, 'room' => 'DBL', 'board' => 'HB', 'package' => 'P', 'early_booking' => 0.0, 'idcont' => '']], $rows);
    }

    /** Room and board one level up (<rooms><IdRoom/><board>…<Price/></board>). */
    public function testANestedRowInheritsTheRoomFromItsParent(): void
    {
        $xml = new \SimpleXMLElement('<room_price><hotel>'
            . '<rooms><IdRoom>DBL</IdRoom><board><IdBoard>BB</IdBoard><Price>500</Price></board><board><IdBoard>HB</IdBoard><Price>650</Price></board></rooms>'
            . '<rooms><IdRoom>FAM</IdRoom><board><IdBoard>BB</IdBoard><Price>700</Price></board></rooms>'
            . '</hotel></room_price>');

        $rows = RoomOfferRows::fromXml($xml);

        self::assertSame(650.0, RoomOfferRows::cheapest($rows, 'DBL', 'HB')['price'] ?? null);
        self::assertSame(700.0, RoomOfferRows::cheapest($rows, 'fam', 'bb')['price'] ?? null);
    }

    /**
     * An offer without its optional elements (no early_booking, no extras,
     * no agent) keeps its own values and does not shift the next offer's.
     * The old parallel //Price, //IdRoom, //Board lists paired by position.
     */
    public function testARowMissingOptionalElementsDoesNotShiftTheOthers(): void
    {
        $xml = new \SimpleXMLElement('<room_price>'
            . '<agent>A</agent><PackageName>P1</PackageName><Price>795</Price><IdRoom>DBL</IdRoom><Board>UAI</Board><early_booking/><extras/>'
            // Second offer: no <agent>, no <IdRoom> of its own, no optional elements.
            . '<PackageName>P2</PackageName><Price>600</Price><Board>BB</Board>'
            . '<agent>A</agent><PackageName>P3</PackageName><Price>675</Price><IdRoom>DBL</IdRoom><Board>HB</Board><early_booking>20</early_booking>'
            . '</room_price>');

        $rows = RoomOfferRows::fromXml($xml);

        self::assertSame([
            ['price' => 795.0, 'room' => 'DBL', 'board' => 'UAI', 'package' => 'P1', 'early_booking' => 0.0, 'idcont' => ''],
            ['price' => 600.0, 'room' => '', 'board' => 'BB', 'package' => 'P2', 'early_booking' => 0.0, 'idcont' => ''],
            ['price' => 675.0, 'room' => 'DBL', 'board' => 'HB', 'package' => 'P3', 'early_booking' => 20.0, 'idcont' => ''],
        ], $rows);
        // The positional pairing invented a DBL/BB offer at 600 (P3's room
        // on P2's price) and lost DBL/HB altogether.
        self::assertSame(675.0, RoomOfferRows::cheapest($rows, 'DBL', 'HB')['price'] ?? null);
        self::assertNull(RoomOfferRows::cheapest($rows, 'DBL', 'BB'));
    }

    /** An answer without package names can still be priced. */
    public function testARowWithoutAPackageNameMatchesAnyPackage(): void
    {
        $rows = RoomOfferRows::fromXml(new \SimpleXMLElement(
            '<room_price><Price>800</Price><IdRoom>DBL</IdRoom><IdBoard>HB</IdBoard></room_price>',
        ));

        self::assertSame(800.0, RoomOfferRows::cheapest($rows, 'DBL', 'HB', 'Any package')['price'] ?? null);
    }

    public function testAnAnswerWithoutPricesHasNoRows(): void
    {
        self::assertSame([], RoomOfferRows::fromXml(new \SimpleXMLElement('<room_price><error>No availability</error></room_price>')));
    }
}
