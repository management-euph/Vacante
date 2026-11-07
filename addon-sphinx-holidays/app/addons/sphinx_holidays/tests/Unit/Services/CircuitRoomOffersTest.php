<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore;
use Tygh\Addons\SphinxHolidays\Services\CircuitRoomOffers;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;

/**
 * "Camera pentru tot circuitul" on a circuit's page (board 16.3): the rooms
 * of one departure for the customer's group, from one circuits/quote
 * answer, each kept on the server so its "Rezervă" opens the booking form
 * on that very offer. The page used to have no rooms: its bar sent the
 * customer to the booking form, which took the first quote whatever it was.
 */
#[CoversClass(CircuitRoomOffers::class)]
final class CircuitRoomOffersTest extends TestCase
{
    /** @var list<array<string, mixed>> the quote params asked, in order */
    private static array $asked = [];

    /** @var array<string, string> the cache table: key => JSON */
    private static array $cached = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        self::$asked = [];
        self::$cached = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param array<string, mixed> $params */
    public static function record(array $params): void
    {
        self::$asked[] = $params;
    }

    /** @param array<string, string> $entries */
    public static function cacheMany(array $entries): void
    {
        self::$cached = $entries + self::$cached;
    }

    /** @return array<string, mixed>|null */
    public static function cached(string $key): ?array
    {
        $value = isset(self::$cached[$key]) ? json_decode(self::$cached[$key], true) : null;

        return is_array($value) ? $value : null;
    }

    public static function store(): CircuitQuoteStore
    {
        $cache = new class extends CacheService {
            public function __construct()
            {
            }

            #[\Override]
            public function setMany(array $entries, int $ttl): int
            {
                CircuitRoomOffersTest::cacheMany($entries);

                return count($entries);
            }

            #[\Override]
            public function get(string $key): ?array
            {
                return CircuitRoomOffersTest::cached($key);
            }
        };

        return new CircuitQuoteStore(new SessionAccessor(), $cache);
    }

    /** @param list<array<string, mixed>>|null $quotes what circuits/quote answers (null: no answer) */
    private static function offers(?array $quotes): CircuitRoomOffers
    {
        $api = new class ($quotes) extends SphinxApi {
            /** @param list<array<string, mixed>>|null $quotes */
            public function __construct(private ?array $quotes)
            {
            }

            #[\Override]
            public function findCircuitQuotes(array $params): ?array
            {
                CircuitRoomOffersTest::record($params);

                return $this->quotes;
            }
        };

        // A 10 % commission, as CartService would add it.
        return new CircuitRoomOffers($api, static fn (float $price): float => $price * 1.1, self::store());
    }

    /**
     * One quote as the doc shows it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function quote(string $offerId, float $price, array $overrides = []): array
    {
        return $overrides + [
            'offer_id' => $offerId,
            'circuit_id' => 17,
            'title' => 'Creta clasică',
            'departure_date' => '2027-07-05',
            'transport_type' => 'flight',
            'flight' => [
                'outbound' => [
                    'departure' => ['code' => 'OTP', 'name' => 'Bucharest Henri Coanda International Airport', 'datetime' => '2027-07-05T10:40:00+03:00'],
                    'arrival' => ['code' => 'HER', 'name' => 'Heraklion International Airport', 'datetime' => '2027-07-05T12:25:00+03:00'],
                    'airline' => ['code' => 'RO', 'name' => 'Tarom'],
                    'flight_number' => '5131',
                ],
                'inbound' => [
                    'departure' => ['code' => 'HER', 'name' => 'Heraklion International Airport', 'datetime' => '2027-07-09T18:55:00+03:00'],
                    'arrival' => ['code' => 'OTP', 'name' => 'Bucharest Henri Coanda International Airport', 'datetime' => '2027-07-09T20:45:00+03:00'],
                    'airline' => ['code' => 'RO', 'name' => 'Tarom'],
                    'flight_number' => '5132',
                ],
            ],
            'duration' => ['days' => 5, 'nights' => 4],
            'summary' => 'Lorem ipsum',
            'departure' => ['id' => 111, 'name' => 'București'],
            'destinations' => [['id' => 222, 'name' => 'Creta']],
            'image' => 'https://example.test/creta.jpg',
            'rooms' => [['code' => 'standard-double', 'name' => 'Standard  - Double', 'adults' => 2, 'children_ages' => []]],
            'meal_type_category_id' => 'mic-dejun',
            'meal_type_name' => 'Mic Dejun',
            'additional_services' => [['code' => '456', 'title' => 'Defileul Samaria', 'type' => 'optional', 'selected' => false]],
            'pricing' => ['marketing_price' => $price, 'discount' => 0, 'selling_price' => $price, 'commission' => 0, 'supplier_price' => $price, 'currency' => 'EUR'],
            'payment_terms' => ['is_loaded' => true, 'rules' => []],
            'cancellation_fees' => ['is_loaded' => false, 'is_free' => false, 'rules' => []],
            'tags' => [],
        ];
    }

    private const array TWO_ADULTS = [['adults' => 2, 'children_ages' => []]];

    public function testEachRoomComesWithItsPriceForTheGroupCheapestFirst(): void
    {
        $answer = self::offers([
            self::quote('double', 1000.0),
            self::quote('twin', 900.0, ['rooms' => [['code' => 'standard-twin', 'name' => 'Standard - Twin', 'adults' => 2, 'children_ages' => []]]]),
        ])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000);

        self::assertFalse($answer['error']);
        self::assertCount(1, $answer['blocks'], 'one flight, one block');
        $block = $answer['blocks'][0];
        self::assertSame(4, $block['nights']);
        self::assertSame([
            ['offer_id' => 'twin', 'key' => 'standard-twin|mic-dejun', 'rooms' => [['name' => 'Standard - Twin', 'count' => 1]], 'meal' => 'Mic Dejun', 'price' => 990.0, 'currency' => 'EUR'],
            ['offer_id' => 'double', 'key' => 'standard-double|mic-dejun', 'rooms' => [['name' => 'Standard - Double', 'count' => 1]], 'meal' => 'Mic Dejun', 'price' => 1100.0, 'currency' => 'EUR'],
        ], $block['offers'], 'the store price (commission included), the names as the supplier writes them');
    }

    public function testSphinxIsAskedForTheDepartureAndEveryRoom(): void
    {
        $rooms = [['adults' => 2, 'children_ages' => [6]], ['adults' => 1, 'children_ages' => []]];
        self::offers([])->forDeparture(17, 111, '2027-07-05', $rooms, 1000);
        self::offers([])->forDeparture(17, 0, '2027-07-05', self::TWO_ADULTS, 1000);

        self::assertSame(['circuit_id' => 17, 'departure_date' => '2027-07-05', 'occupancy' => $rooms, 'departure_id' => 111], self::$asked[0]);
        self::assertArrayNotHasKey('departure_id', self::$asked[1], "no city: the supplier's own");
    }

    public function testTheFlightIsInTheAirportsOwnTime(): void
    {
        $answer = self::offers([self::quote('double', 1000.0)])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000);

        self::assertSame([
            'outbound' => [
                'departure_date' => '2027-07-05', 'departure_time' => '10:40',
                'from' => 'Bucharest Henri Coanda International Airport', 'from_code' => 'OTP',
                'arrival_date' => '2027-07-05', 'arrival_time' => '12:25',
                'to' => 'Heraklion International Airport', 'to_code' => 'HER',
                'airline' => 'Tarom',
            ],
            'inbound' => [
                'departure_date' => '2027-07-09', 'departure_time' => '18:55',
                'from' => 'Heraklion International Airport', 'from_code' => 'HER',
                'arrival_date' => '2027-07-09', 'arrival_time' => '20:45',
                'to' => 'Bucharest Henri Coanda International Airport', 'to_code' => 'OTP',
                'airline' => 'Tarom',
            ],
        ], $answer['blocks'][0]['flight']);
    }

    public function testAnotherFlightMakesItsOwnBlockAndABusCircuitHasNoFlight(): void
    {
        $later = self::quote('late', 800.0)['flight'];
        $later['outbound']['departure']['datetime'] = '2027-07-05T21:10:00+03:00';
        $answer = self::offers([
            self::quote('early', 1000.0),
            self::quote('late', 800.0, ['flight' => $later]),
            self::quote('bus', 1200.0, ['flight' => null, 'transport_type' => 'bus']),
        ])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000);

        self::assertSame(['late', 'early', 'bus'], array_map(static fn (array $block): string => $block['offers'][0]['offer_id'], $answer['blocks']), 'the cheapest block first');
        self::assertSame('21:10', $answer['blocks'][0]['flight']['outbound']['departure_time'] ?? null);
        self::assertNull($answer['blocks'][2]['flight']);
    }

    public function testAnOfferWithoutIdPriceOrOfAnotherCircuitIsLeftOut(): void
    {
        $answer = self::offers([
            self::quote('', 1000.0),
            self::quote('free', 0.0),
            self::quote('other', 900.0, ['circuit_id' => 18]),
            self::quote('ok', 1000.0),
        ])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000);

        self::assertSame(['ok'], array_column($answer['blocks'][0]['offers'], 'offer_id'));
    }

    /** No answer is not "no rooms": the page says the rooms cannot be loaded, not that none are left. */
    public function testNoAnswerFromSphinxIsAnError(): void
    {
        self::assertSame(['blocks' => [], 'error' => true], self::offers(null)->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000));
        self::assertSame(['blocks' => [], 'error' => false], self::offers([])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000));
    }

    public function testOnlyWhatThePageShowsLeavesTheServer(): void
    {
        $json = (string) json_encode(self::offers([self::quote('double', 1000.0)])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000));

        foreach (['supplier_price', 'marketing_price', 'pricing', 'commission', 'flight_number', 'additional_services'] as $field) {
            self::assertStringNotContainsString($field, $json);
        }
    }

    /** "Rezervă" opens the booking form on the offer the page showed, as it was quoted, without quoting again. */
    public function testEachOfferIsKeptForTheBookingForm(): void
    {
        self::offers([self::quote('double', 1000.0)])->forDeparture(17, 111, '2027-07-05', self::TWO_ADULTS, 1000);

        $store = self::store();
        $kept = $store->get('double', 17, 1000 + 60);
        self::assertNotNull($kept);
        self::assertSame(1000.0, $kept['selling_price'], 'the supplier price: the cart adds commission once');
        self::assertSame('standard-double', $kept['rooms'][0]['code'] ?? null);

        $quote = $store->quote('double', 17, 1000 + 60);
        self::assertNotNull($quote);
        self::assertSame('Creta clasică', $quote['title']);
        self::assertSame('Defileul Samaria', $quote['additional_services'][0]['title'] ?? null);
        self::assertArrayNotHasKey('summary', $quote, 'only what the booking form shows is kept');
        self::assertArrayNotHasKey('tags', $quote);
    }

    public function testTheRoomsTheBarMayAskAbout(): void
    {
        self::assertTrue(CircuitRoomOffers::validOccupancy(self::TWO_ADULTS, 12));
        self::assertTrue(CircuitRoomOffers::validOccupancy([['adults' => 9, 'children_ages' => [0, 5, 11, 17]]], 12));
        self::assertFalse(CircuitRoomOffers::validOccupancy([], 12), 'no room');
        self::assertFalse(CircuitRoomOffers::validOccupancy(array_fill(0, 13, ['adults' => 1, 'children_ages' => []]), 12), 'more rooms than the picker offers');
        self::assertFalse(CircuitRoomOffers::validOccupancy([['adults' => 10, 'children_ages' => []]], 12));
        self::assertFalse(CircuitRoomOffers::validOccupancy([['adults' => 0, 'children_ages' => [5]]], 12));
        self::assertFalse(CircuitRoomOffers::validOccupancy([['adults' => 2, 'children_ages' => [1, 2, 3, 4, 5]]], 12));
        self::assertFalse(CircuitRoomOffers::validOccupancy([['adults' => 2, 'children_ages' => [18]]], 12));
        self::assertFalse(CircuitRoomOffers::validOccupancy([['adults' => 2, 'children_ages' => [-1]]], 12));
    }

    /** A new quote mints new offer ids: the key finds the same room and meal in it. */
    public function testTheSameRoomIsFoundInANewQuoteByItsKey(): void
    {
        $double = self::quote('new-double', 1000.0);
        $halfBoard = self::quote('new-hb', 1200.0, ['meal_type_category_id' => 'demipensiune', 'meal_type_name' => 'Demipensiune']);

        self::assertSame($halfBoard, CircuitRoomOffers::withKey([$double, $halfBoard], 'standard-double|demipensiune'));
        self::assertSame($double, CircuitRoomOffers::withKey([$double, $halfBoard], CircuitRoomOffers::key(self::quote('old-double', 990.0))));
        self::assertNull(CircuitRoomOffers::withKey([$double], 'standard-single|mic-dejun'), 'that room is gone');
        self::assertNull(CircuitRoomOffers::withKey([$double], ''));
    }

    /** The booking form takes the offer's own rooms: it is priced for them, whatever a link says. */
    public function testAnOfferIsPricedForItsOwnRooms(): void
    {
        self::assertSame(
            [['adults' => 2, 'children_ages' => [6]], ['adults' => 1, 'children_ages' => []]],
            CircuitRoomOffers::occupancyOf(['rooms' => [
                ['code' => 'dbl', 'adults' => 2, 'children_ages' => ['6']],
                ['code' => 'sgl', 'adults' => 1, 'children_ages' => []],
            ]]),
        );
        self::assertSame([], CircuitRoomOffers::occupancyOf(['rooms' => [['code' => 'dbl']]]), 'a room without its adults says nothing');
    }
}
