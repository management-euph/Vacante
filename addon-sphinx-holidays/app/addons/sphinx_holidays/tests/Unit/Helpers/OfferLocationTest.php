<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\OfferLocation;

/**
 * The offer card's location line names the country, not just the resort:
 * "Dubrovnik, Croatia".
 */
#[CoversClass(OfferLocation::class)]
final class OfferLocationTest extends TestCase
{
    public function testTheCountryFollowsTheResort(): void
    {
        self::assertSame('Dubrovnik, Croatia', OfferLocation::label('Dubrovnik', 'Croatia'));
    }

    public function testACountryAlreadyNamedIsNotRepeated(): void
    {
        self::assertSame('Makarska, Croatia', OfferLocation::label('Makarska, Croatia', 'Croatia'));
        self::assertSame('Makarska, CROATIA', OfferLocation::label('Makarska, CROATIA', 'Croatia'));
    }

    public function testEitherPartAlone(): void
    {
        self::assertSame('Dubrovnik', OfferLocation::label('Dubrovnik', ''));
        self::assertSame('Croatia', OfferLocation::label('', 'Croatia'));
        self::assertSame('', OfferLocation::label(' ', ' '));
    }

    public function testEveryOfferGetsItsHotelsCountry(): void
    {
        $offers = [
            ['hotel_id' => '59833', 'destination' => 'Dubrovnik'],
            ['hotel_id' => '12', 'destination' => 'Antalya'],
            ['hotel_id' => '59833', 'destination' => 'Dubrovnik'],
            ['hotel_id' => '404', 'destination' => 'Nowhere'],
        ];

        self::assertSame(['59833', '12', '404'], OfferLocation::hotelIds($offers));

        $out = OfferLocation::applyCountry($offers, ['59833' => 'Croatia', '12' => 'Turkey']);

        self::assertSame(
            ['Dubrovnik, Croatia', 'Antalya, Turkey', 'Dubrovnik, Croatia', 'Nowhere'],
            array_column($out, 'destination'),
        );
    }

    /** Both result paths add the country; the poll keeps room_lines for the card. */
    public function testTheSearchAndPollResultsCarryCountryAndRooms(): void
    {
        $dir = dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/';
        foreach (['search.php', 'search_poll.php'] as $file) {
            $src = (string) file_get_contents($dir . $file);
            self::assertStringContainsString('OfferLocation::applyCountry(', $src, $file);
            self::assertStringContainsString('findCountryNames(OfferLocation::hotelIds(', $src, $file);
        }
        $poll = (string) file_get_contents($dir . 'search_poll.php');
        $start = strpos($poll, '$templateFields = [');
        self::assertNotFalse($start);
        $fields = substr($poll, $start, (int) strpos($poll, '];', $start) - $start);
        self::assertStringContainsString("'room_lines'", $fields);
    }
}
