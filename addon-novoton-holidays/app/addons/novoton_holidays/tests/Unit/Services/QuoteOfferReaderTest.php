<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\QuoteOfferReader;

/**
 * The offer behind the booking page's re-priced quote: read row by row, so
 * a row without the optional <early_booking> / <extras> never lends its
 * neighbour's values.
 */
final class QuoteOfferReaderTest extends TestCase
{
    private const QUOTE = <<<'XML'
        <room_prices>
            <room_price><IdRoom>DBL%2bSEA</IdRoom><Board>AI</Board><Price>1200</Price><early_booking>10</early_booking></room_price>
            <room_price><IdRoom>DBL%2bSEA</IdRoom><Board>AI</Board><Price>1030</Price><extras>7 = 6</extras><early_booking>5</early_booking></room_price>
            <room_price><IdRoom>DBL%2bSEA</IdRoom><Board>BB</Board><Price>900</Price></room_price>
            <room_price><IdRoom>FAM</IdRoom><Board>AI</Board><Price>1500</Price></room_price>
        </room_prices>
        XML;

    public function testThePromoRowGivesItsOwnOfferAndTheStandardPrice(): void
    {
        self::assertSame(
            ['early_booking' => 5.0, 'extras' => '7 = 6', 'standard' => 1200.0],
            QuoteOfferReader::fromXml(new \SimpleXMLElement(self::QUOTE), 'DBL+SEA', 'AI', 1030.0),
        );
    }

    /** No optional elements: nothing borrowed from the rows around it. */
    public function testARowWithoutOptionalElementsHasNoOffer(): void
    {
        self::assertSame(
            ['early_booking' => 0.0, 'extras' => '', 'standard' => 900.0],
            QuoteOfferReader::fromXml(new \SimpleXMLElement(self::QUOTE), 'DBL+SEA', 'bb', 900.0),
        );
    }

    public function testASingleResultIsItsOwnRow(): void
    {
        $xml = new \SimpleXMLElement('<room_price><IdRoom>DBL</IdRoom><IdBoard>HB</IdBoard><Price>800</Price><early_booking>15</early_booking></room_price>');

        self::assertSame(
            ['early_booking' => 15.0, 'extras' => '', 'standard' => 800.0],
            QuoteOfferReader::fromXml($xml, 'DBL', 'HB', 800.0),
        );
    }

    /** Promo and standard pair within ONE package, as on the search card. */
    public function testTheStandardPriceComesFromTheSamePackage(): void
    {
        $xml = new \SimpleXMLElement('<r>'
            . '<room_price><IdRoom>DBL</IdRoom><Board>AI</Board><Price>1030</Price><extras>7 = 6</extras><PackageName>Summer%20A</PackageName></room_price>'
            . '<room_price><IdRoom>DBL</IdRoom><Board>AI</Board><Price>1100</Price><PackageName>Summer%20B</PackageName></room_price>'
            . '</r>');

        self::assertSame(
            ['early_booking' => 0.0, 'extras' => '7 = 6', 'standard' => 0.0],
            QuoteOfferReader::fromXml($xml, 'DBL', 'AI', 1030.0, 'Summer A'),
        );
        self::assertSame(1100.0, QuoteOfferReader::fromXml($xml, 'DBL', 'AI', 1030.0)['standard'] ?? null);
    }

    public function testUnknownPriceOrFlatListsGiveNothing(): void
    {
        self::assertNull(QuoteOfferReader::fromXml(new \SimpleXMLElement(self::QUOTE), 'DBL+SEA', 'AI', 999.0));
        $flat = new \SimpleXMLElement('<r><IdRoom>A</IdRoom><Board>AI</Board><Price>1</Price><IdRoom>B</IdRoom><Board>AI</Board><Price>2</Price></r>');
        self::assertNull(QuoteOfferReader::fromXml($flat, 'A', 'AI', 1.0));
    }
}
