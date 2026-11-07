<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * A circuit's product page gets the circuit bar (board 16.3): the page
 * mounts the engine for it as for a hotel, booking_config answers kind
 * "circuit" with what the bar needs, and the engine renders CircuitBar.
 * A circuit's page used to get no bar at all: only hotels were recognised.
 */
final class CircuitBarWiringTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    private static function engine(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/react-src/src/' . $file);
    }

    public function testTheCircuitPageMountsTheEngine(): void
    {
        $hooks = self::read('hooks/cart_hooks.php');

        self::assertStringContainsString('TravelProviderRegistry::resolveCircuit($productId, $productCode) !== null', $hooks);
        self::assertStringContainsString('($hotelSeoData !== null || $isCircuit)', $hooks);
    }

    public function testBookingConfigAnswersTheCircuitBar(): void
    {
        $config = self::read('controllers/frontend/travel_booking.php');

        self::assertStringContainsString("'kind' => 'circuit',", $config);
        foreach (["'departures' => \$circuit->departures,", "'departureId' => \$circuit->defaultDepartureId(),", "'datesDispatch' => \$providerName . '_booking.circuit_dates',", "'bookingDispatch' => \$providerName . '_booking.circuit_booking_form',"] as $field) {
            self::assertStringContainsString($field, $config);
        }
        foreach (['departure_from', 'departure_date', 'departures_from', 'circuit_prices_note', 'no_departures', 'loading_departures', 'departures_error', 'choose_departure_date', 'circuit_see_price'] as $key) {
            self::assertStringContainsString("'{$key}'", $config, 'the bar text ' . $key . ' is sent');
        }
    }

    public function testTheEngineRendersTheCircuitBarForACircuit(): void
    {
        $index = self::engine('index.jsx');

        self::assertStringContainsString("serverConfig.kind === 'circuit'", $index);
        self::assertStringContainsString('<CircuitBar config={circuitConfig} />', $index);
        // The same guest picker as the hotel bar.
        self::assertStringContainsString("import GuestPicker from './GuestPicker';", self::engine('CircuitBar.jsx'));
    }
}
