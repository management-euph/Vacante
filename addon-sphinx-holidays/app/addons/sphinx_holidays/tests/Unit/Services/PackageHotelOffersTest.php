<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\PackageHotelOffers;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * "Camere și prețuri" on a hotel's page by plane (board 16.2): this hotel's
 * packages for one city, date and length, the flight once above its rooms,
 * the store's price on each (Rixos Downtown, live: 22 offers from Bucharest
 * on 2027-04-03, 7 nights, all its own when asked with hotel_ids).
 */
final class PackageHotelOffersTest extends TestCase
{
    /** @return array<string, mixed> one way of a flight, as packages/results gives it */
    private static function leg(string $from, string $fromAt, string $to, string $toAt, string $airline): array
    {
        return [
            'departure' => ['code' => $from, 'name' => $from . ' Airport', 'datetime' => $fromAt],
            'arrival' => ['code' => $to, 'name' => $to . ' Airport', 'datetime' => $toAt],
            'airline' => ['code' => 'XX', 'name' => $airline],
        ];
    }

    /** @return array<string, mixed> */
    private static function offer(string $id, int $hotel, string $room, string $meal, float $price, string $airline = 'Corendon Airlines', string $confirmation = 'immediate'): array
    {
        return [
            'offer_id' => $id,
            'hotel' => [
                'id' => $hotel,
                'check_in' => '2027-04-03',
                'check_out' => '2027-04-10',
                'rooms' => [['code' => 'std', 'name' => $room . '  ', 'adults' => 2, 'children_ages' => []]],
                'meal_type_name' => $meal,
            ],
            'flight' => [
                'outbound' => [self::leg('OTP', '2027-04-03T10:40:00+03:00', 'AYT', '2027-04-03T12:25:00+03:00', $airline)],
                'inbound' => [self::leg('AYT', '2027-04-10T18:55:00+03:00', 'OTP', '2027-04-10T20:45:00+03:00', $airline)],
            ],
            'confirmation' => $confirmation,
            'pricing' => ['selling_price' => $price, 'supplier_price' => $price * 0.8, 'currency' => 'EUR'],
        ];
    }

    /**
     * @param list<array<string, mixed>|null> $pages the results pages
     * @param list<array<string, mixed>> $asked filled with the search params
     */
    private function api(?array $start, array $pages, array &$asked): SphinxApi
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('searchPackages')->willReturnCallback(static function (array $params) use ($start, &$asked): ?array {
            $asked[] = $params;

            return $start;
        });
        $api->method('getPackageResults')->willReturnCallback(static function () use (&$pages): ?array {
            return array_shift($pages);
        });

        return $api;
    }

    public function testThisHotelsOffersByFlightCheapestFirst(): void
    {
        $asked = [];
        $pauses = 0;
        $api = $this->api(['search_id' => 's1', 'cursor' => 'c0'], [
            ['results' => [], 'cursor' => 'c1'],                                   // still searching
            ['results' => [
                self::offer('o1', 3612, 'STANDARD LAND VIEW - 1 x DOUBLE', 'ULTRA ALL INCLUSIVE', 1756),
                self::offer('o2', 3612, 'DELUXE SEA VIEW - 1 x DOUBLE', 'ULTRA ALL INCLUSIVE', 1990),
                self::offer('x1', 103914, 'ECONOMY ROOM', 'ULTRA ALL INCLUSIVE', 1652),     // another hotel
            ], 'cursor' => 'c2'],
            ['results' => [
                self::offer('o3', 3612, 'STANDARD LAND VIEW - 1 x DOUBLE', 'ULTRA ALL INCLUSIVE', 1700, 'Tarom', 'on_request'),
                self::offer('', 3612, 'NO ID', 'AI', 900),                                   // no offer id
                self::offer('o4', 3612, 'FREE', 'AI', 0),                                    // no price
            ], 'cursor' => null],
        ], $asked);

        $answer = (new PackageHotelOffers($api, static fn (float $price): float => $price * 1.1, null, 0, 30, static function () use (&$pauses): void {
            $pauses++;
        }))->forHotel('3612', 'flight', 10, '2027-04-03', 7, [['adults' => 2, 'children_ages' => []]]);

        self::assertFalse($answer['error']);
        self::assertSame(1, $pauses, 'a pause only after an empty page of a running search');
        self::assertSame([3612], $asked[0]['hotel_ids'], 'asked for this hotel only');
        self::assertSame(['flight'], $asked[0]['transport']);
        self::assertSame(10, $asked[0]['departure_id']);
        self::assertSame(['2027-04-03', 7], [$asked[0]['departure_date'], $asked[0]['nights']]);
        self::assertArrayNotHasKey('destination_id', $asked[0]);

        self::assertCount(2, $answer['blocks'], 'two flights that day: two blocks');
        [$tarom, $corendon] = $answer['blocks'];
        self::assertSame('Tarom', $tarom['flight']['outbound']['airline'] ?? null, 'the cheapest block first');
        self::assertSame(['o3'], array_column($tarom['offers'], 'offer_id'));
        self::assertSame('on_request', $tarom['offers'][0]['confirmation']);
        self::assertSame(['o1', 'o2'], array_column($corendon['offers'], 'offer_id'), 'another hotel, no id, no price: left out; cheapest first');
        self::assertSame(1931.6, $corendon['offers'][0]['price'], 'the store\'s price (commission), never the supplier\'s');
        self::assertSame([['name' => 'STANDARD LAND VIEW - 1 x DOUBLE', 'count' => 1]], $corendon['offers'][0]['rooms']);
        self::assertSame('ULTRA ALL INCLUSIVE', $corendon['offers'][0]['meal']);
        self::assertSame(['2027-04-03', '2027-04-10'], [$corendon['check_in'], $corendon['check_out']]);
        self::assertSame(
            ['departure_date' => '2027-04-03', 'departure_time' => '10:40', 'from' => 'OTP Airport', 'from_code' => 'OTP', 'arrival_date' => '2027-04-03', 'arrival_time' => '12:25', 'to' => 'AYT Airport', 'to_code' => 'AYT', 'airline' => 'Corendon Airlines', 'stops' => 0],
            $corendon['flight']['outbound'],
            'the airport\'s local time, as written',
        );
        self::assertStringNotContainsString('supplier', (string) json_encode($answer), 'no supplier figure leaves the server');
    }

    public function testAFlightWithAStopGoesFromTheFirstToTheLastAirport(): void
    {
        $flight = PackageHotelOffers::flight([
            'outbound' => [
                self::leg('CLJ', '2027-05-01T06:00:00+03:00', 'IST', '2027-05-01T08:00:00+03:00', 'Turkish Airlines'),
                self::leg('IST', '2027-05-01T10:00:00+03:00', 'AYT', '2027-05-01T11:20:00+03:00', 'AJet'),
            ],
            'inbound' => [],
        ]);

        self::assertSame(['CLJ', 'AYT', '06:00', '11:20', 'Turkish Airlines, AJet', 1], [
            $flight['outbound']['from_code'] ?? null,
            $flight['outbound']['to_code'] ?? null,
            $flight['outbound']['departure_time'] ?? null,
            $flight['outbound']['arrival_time'] ?? null,
            $flight['outbound']['airline'] ?? null,
            $flight['outbound']['stops'] ?? null,
        ]);
        self::assertNull($flight['inbound'] ?? null);
        self::assertNull(PackageHotelOffers::flight(['outbound' => [], 'inbound' => []]), 'a bus: no flight');
    }

    public function testNoAnswerIsAnError(): void
    {
        $asked = [];
        $offers = new PackageHotelOffers($this->api(null, [], $asked), static fn (float $p): float => $p);
        self::assertSame(['blocks' => [], 'error' => true], $offers->forHotel('3612', 'flight', 0, '2027-04-03', 7, [['adults' => 2, 'children_ages' => []]]));

        $offers = new PackageHotelOffers($this->api(['search_id' => 's'], [null], $asked), static fn (float $p): float => $p);
        self::assertSame(['blocks' => [], 'error' => true], $offers->forHotel('3612', 'flight', 0, '2027-04-03', 7, [['adults' => 2, 'children_ages' => []]]), 'the results call failed before any offer');
        self::assertArrayNotHasKey('departure_id', $asked[1], 'any city when none is chosen');
    }

    public function testNoOfferIsAnAnswer(): void
    {
        $asked = [];
        $offers = new PackageHotelOffers($this->api(['search_id' => 's', 'cursor' => 'c'], [['results' => [], 'cursor' => null]], $asked), static fn (float $p): float => $p);

        self::assertSame(['blocks' => [], 'error' => false], $offers->forHotel('3612', 'flight', 10, '2027-04-03', 7, [['adults' => 2, 'children_ages' => []]]));
    }
}
