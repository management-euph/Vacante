<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore;

/**
 * Circuit add-to-cart took the price from the form (total_price), so a guest
 * could change it. The booking form now keeps the provider's quote on the
 * server; add-to-cart prices only from it, and refuses without one.
 */
final class CircuitQuoteStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTheStoredQuoteComesBackForItsOfferAndCircuit(): void
    {
        $store = new CircuitQuoteStore();
        $store->remember('offer-1', 7, 850.0, 'EUR', '2026-11-02', 1000);

        self::assertSame(
            ['circuit_id' => 7, 'selling_price' => 850.0, 'currency' => 'EUR', 'departure_date' => '2026-11-02', 'meal_type' => '', 'departure_name' => '', 'pricing' => [], 'rooms' => []],
            $store->get('offer-1', 7, 1000 + 60),
        );
    }

    /** circuits/book sends the quote's own price and room codes for the offer, never the form's. */
    public function testTheQuoteKeepsItsPricingAndRoomsForTheBooking(): void
    {
        $pricing = ['marketing_price' => 1030.0, 'discount' => 103.0, 'selling_price' => 927.0, 'currency' => 'EUR'];
        $rooms = [['code' => 'standard-double', 'name' => 'Standard - Double', 'adults' => 2, 'children_ages' => []]];
        $store = new CircuitQuoteStore();
        $store->remember('offer-3', 1, 927.0, 'EUR', '2027-07-05', 1000, 'Mic Dejun', 'Bucharest', $pricing, $rooms);

        $quote = $store->get('offer-3', 1, 1000);

        self::assertNotNull($quote);
        self::assertSame($pricing, $quote['pricing']);
        self::assertSame($rooms, $quote['rooms']);
    }

    public function testTheQuoteKeepsItsMealPlanAndDepartureCityForTheOrder(): void
    {
        $store = new CircuitQuoteStore();
        $store->remember('offer-2', 9, 640.0, 'EUR', '2026-11-08', 1000, 'Half Board', 'Bucharest');

        $quote = $store->get('offer-2', 9, 1000);

        self::assertNotNull($quote);
        self::assertSame('Half Board', $quote['meal_type']);
        self::assertSame('Bucharest', $quote['departure_name']);
    }

    public function testAnotherOfferOrCircuitOrAnOldQuoteIsNotTrusted(): void
    {
        $store = new CircuitQuoteStore();
        $store->remember('offer-1', 7, 850.0, 'EUR', '2026-11-02', 1000);

        self::assertNull($store->get('offer-2', 7, 1000), 'no quote for that offer');
        self::assertNull($store->get('offer-1', 8, 1000), 'the offer was quoted for another circuit');
        self::assertNull($store->get('offer-1', 7, 1000 + CircuitQuoteStore::TTL + 1), 'too old');
        self::assertNull($store->get('', 7, 1000));
    }

    /**
     * The rooms on the circuit's page keep each offer: its price and rooms
     * in this session, what the booking form shows in the cache table. The
     * cache copy is only read for an offer this session keeps.
     */
    public function testThePagesOffersAreKeptForThisVisitorOnly(): void
    {
        $store = CircuitRoomOffersTest::store();
        $store->rememberQuotes([CircuitRoomOffersTest::quote('offer-9', 927.0)], 17, '2027-07-05', 1000);

        $kept = $store->get('offer-9', 17, 1000);
        self::assertNotNull($kept);
        self::assertSame([927.0, 'EUR', '2027-07-05', 'Mic Dejun', 'București'], [$kept['selling_price'], $kept['currency'], $kept['departure_date'], $kept['meal_type'], $kept['departure_name']]);
        self::assertSame('Creta clasică', $store->quote('offer-9', 17, 1000)['title'] ?? null);

        $_SESSION = [];
        self::assertNull(CircuitRoomOffersTest::store()->quote('offer-9', 17, 1000), "another visitor's session keeps no such offer");
        self::assertNull($store->quote('offer-9', 18, 1000), 'nor another circuit');
    }

    /** The booking form checks the offer against its date: the date it was quoted for, even when Sphinx does not repeat it. */
    public function testAKeptOfferCarriesTheDateItWasQuotedFor(): void
    {
        $quote = CircuitRoomOffersTest::quote('offer-10', 927.0);
        unset($quote['departure_date']);
        $store = CircuitRoomOffersTest::store();
        $store->rememberQuotes([$quote], 17, '2027-07-12', 1000);

        self::assertSame('2027-07-12', $store->quote('offer-10', 17, 1000)['departure_date'] ?? null);
    }

    public function testNothingIsStoredWithoutAPrice(): void
    {
        $store = new CircuitQuoteStore();
        $store->remember('offer-1', 7, 0.0, 'EUR', '2026-11-02', 1000);

        self::assertNull($store->get('offer-1', 7, 1000));
    }
}
