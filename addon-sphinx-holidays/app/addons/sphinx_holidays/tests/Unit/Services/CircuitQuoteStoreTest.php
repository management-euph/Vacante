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

    public function testNothingIsStoredWithoutAPrice(): void
    {
        $store = new CircuitQuoteStore();
        $store->remember('offer-1', 7, 0.0, 'EUR', '2026-11-02', 1000);

        self::assertNull($store->get('offer-1', 7, 1000));
    }
}
