<?php

declare(strict_types=1);

namespace {
    // Load the procedural helpers in the GLOBAL namespace, as CS-Cart does.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/functions/helpers.php';
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;

    /**
     * fn_novoton_min_price_from_xml — the booking page re-price and
     * add_to_cart read the price through it — delegates to RoomOfferRows:
     * the booked package pins the price, and the return shape keeps
     * price/room/board (plus the package the price belongs to).
     */
    final class MinPriceFromXmlTest extends TestCase
    {
        private static function fixture(): \SimpleXMLElement
        {
            $xml = simplexml_load_file(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
            self::assertInstanceOf(\SimpleXMLElement::class, $xml);

            return $xml;
        }

        public function testTheBookedPackageKeepsItsOwnPrice(): void
        {
            self::assertSame(
                ['price' => 795.0, 'room' => 'DBL 2+0 DELUXE NO BALCONY', 'board' => 'ULTRA ALL INCL', 'package' => 'ADMIRAL ***** +BEACH'],
                fn_novoton_min_price_from_xml(self::fixture(), 'DBL 2+0 DELUXE NO BALCONY', 'ULTRA ALL INCL', 'ADMIRAL ***** +BEACH'),
            );
        }

        /** Without a package: the cheapest room + board row, as before. */
        public function testWithoutAPackageTheCheapestRowWins(): void
        {
            self::assertSame(
                ['price' => 600.0, 'room' => 'DBL 2+0 DELUXE NO BALCONY', 'board' => 'ULTRA ALL INCL', 'package' => 'ADMIRAL ***** +BEACH [STAY 18.09 - 31.10]'],
                fn_novoton_min_price_from_xml(self::fixture(), 'DBL 2+0 DELUXE NO BALCONY', 'ULTRA ALL INCL'),
            );
        }

        public function testAPackageNotOfferedIsNoPrice(): void
        {
            self::assertNull(fn_novoton_min_price_from_xml(self::fixture(), 'DBL 2+0 DELUXE NO BALCONY', 'ULTRA ALL INCL', 'ADMIRAL ***** HB ONLY'));
            self::assertNull(fn_novoton_min_price_from_xml(self::fixture(), 'DBL 2+0 DELUXE NO BALCONY', 'HB'));
        }
    }
}
