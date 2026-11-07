<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The circuit bar (board 16.3, travel_core CircuitBar.jsx) on a Sphinx
 * circuit's page: Sphinx claims its circuits, answers their departure
 * dates, and the quote step takes the rooms the bar sends, one occupancy
 * entry each. It used to quote every party as one room.
 */
final class CircuitBarWiringTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testSphinxClaimsItsCircuits(): void
    {
        self::assertStringContainsString(
            "TravelProviderRegistry::setCircuitProductProvider(\n        'sphinx',\n        new \\Tygh\\Addons\\SphinxHolidays\\Providers\\SphinxCircuitProductProvider(),",
            self::read('init.php'),
        );
    }

    public function testTheDatesEndpointOnlyAsksAboutTheStoresActiveCircuits(): void
    {
        $dates = self::read('controllers/frontend/sphinx_booking/circuit_dates.php');

        self::assertStringContainsString("FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active'", $dates);
        self::assertStringContainsString('->forDeparture($circuitId, $departureId, ', $dates);
        self::assertStringContainsString("header('Content-Type: application/json; charset=utf-8');", $dates);
    }

    public function testTheQuoteStepQuotesEachRoom(): void
    {
        $form = self::read('controllers/frontend/sphinx_booking/circuit_booking_form.php');

        self::assertStringContainsString("SearchOccupancyResolver::resolve(\n    \$rooms_data_json,", $form);
        self::assertStringContainsString("'occupancy' => \$occupancy,", $form);
        self::assertStringNotContainsString("'occupancy' => [['adults' => \$adults", $form);

        // The rooms go on to add-to-cart, and back to this step on a refusal.
        $template = (string) file_get_contents(dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/circuit_booking_form.tpl');
        self::assertStringContainsString('name="rooms_data" value="{$sphinx_circuit_booking.occupancy_json|escape:html}"', $template);
        self::assertStringContainsString(
            "'rooms_data' => RequestCoerce::string(\$_REQUEST, 'rooms_data'),",
            self::read('controllers/frontend/sphinx_booking/circuit_add_to_cart.php'),
        );
    }
}
