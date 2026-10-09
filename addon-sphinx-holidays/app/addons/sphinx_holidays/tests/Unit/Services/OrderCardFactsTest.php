<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\OrderCardFacts;

/**
 * What the order booking card learns from a sphinx booking row and line:
 * the confirmation number, the API's answer when booking failed, what Sphinx
 * charges, and what kind of trip the line is.
 */
#[CoversClass(OrderCardFacts::class)]
final class OrderCardFactsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 31,
            'status' => 'confirmed',
            'api_booking_ref' => 'SPX-77352',
            'api_response' => '{"booking_confirmation_number":"SPX-77352"}',
            'base_price' => '1810.00',
            'total_price' => '2000.00',
            'currency' => 'EUR',
        ];
    }

    public function testAHotelLineCarriesItsConfirmationAndSupplierPrice(): void
    {
        $facts = OrderCardFacts::fromRow(['sphinx_booking' => true], 31, self::row());

        self::assertSame('hotel', $facts['kind']);
        self::assertSame('SPX-77352', $facts['reference']);
        self::assertSame('confirmed', $facts['status']);
        self::assertSame(['amount' => 1810.0, 'currency' => 'EUR'], $facts['supplier_price'], 'before our commission');
        self::assertArrayNotHasKey('error', $facts);
    }

    public function testAFailureReadsAsTheApisAnswer(): void
    {
        $failed = OrderCardFacts::fromRow([], 31, self::row(['status' => 'failed', 'api_booking_ref' => null, 'api_response' => '{"error":"Room type no longer available."}']));
        // Failed before the answer was kept: the verify response, no error.
        $older = OrderCardFacts::fromRow([], 31, self::row(['status' => 'failed', 'api_response' => '{"offer_id":"x"}']));

        self::assertSame('Room type no longer available.', $failed['error']);
        self::assertSame('', $failed['reference']);
        self::assertSame('', $older['error']);
    }

    public function testACircuitCarriesItsTransportMealsAndDeparture(): void
    {
        $new = OrderCardFacts::fromRow(['booking_type' => 'circuit', 'board_id' => 'bus', 'transport_type' => 'bus', 'meal_name' => 'Half Board', 'departure_name' => 'Bucharest'], 34, null);
        $old = OrderCardFacts::fromRow(['booking_type' => 'circuit', 'board_id' => 'flight', 'board_name' => 'Flight'], 34, null);

        self::assertSame(['circuit', 'bus', 'Half Board', 'Bucharest'], [$new['kind'], $new['transport'], $new['meals'], $new['departure']]);
        self::assertSame(['circuit', 'flight', '', ''], [$old['kind'], $old['transport'], $old['meals'], $old['departure']]);
    }

    public function testAPackageCarriesItsTransport(): void
    {
        $facts = OrderCardFacts::fromRow(['booking_type' => 'package', 'transport_type' => 'flight', 'board_id' => 'All Inclusive'], 35, null);

        self::assertSame(['package', 'flight'], [$facts['kind'], $facts['transport']]);
    }

    public function testOnlySphinxLinesAreClaimed(): void
    {
        self::assertSame([], (new OrderCardFacts())->facts(['novoton_booking' => true]));
    }
}
