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
            ['circuit_id' => 7, 'selling_price' => 850.0, 'currency' => 'EUR', 'departure_date' => '2026-11-02'],
            $store->get('offer-1', 7, 1000 + 60),
        );
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
