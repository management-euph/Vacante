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

    /** The rooms of a departure (board 16.3): only for the store's active circuits, a date to come and rooms the picker allows. */
    public function testTheRoomsEndpointOnlyRelaysWhatTheBarMayAsk(): void
    {
        $quote = self::read('controllers/frontend/sphinx_booking/circuit_quote.php');

        self::assertStringContainsString("FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active'", $quote);
        self::assertStringContainsString("&& \$departureDate >= date('Y-m-d')", $quote);
        self::assertStringContainsString("CircuitRoomOffers::validOccupancy(\$occupancy, ProviderRoomLimit::maxRooms('sphinx'))", $quote);
        self::assertStringContainsString('->forDeparture($circuitId, $departureId, $departureDate, $occupancy, time())', $quote);
        self::assertStringContainsString("header('Content-Type: application/json; charset=utf-8');", $quote);
        self::assertStringContainsString("    'circuit_quote',\n", self::read('controllers/frontend/sphinx_booking.php'));
    }

    /** "Rezervă" on a room: the form shows that offer as quoted on the page, or the same room in a new quote, never another. */
    public function testTheBookingFormOpensOnTheRoomChosenOnThePage(): void
    {
        $form = self::read('controllers/frontend/sphinx_booking/circuit_booking_form.php');

        self::assertStringContainsString('$quote = $chosen_offer !== \'\' ? $quoteStore->quote($chosen_offer, $circuit_id, time()) : null;', $form);
        self::assertStringContainsString('$quotedOccupancy = CircuitRoomOffers::occupancyOf($quote);', $form);
        self::assertStringContainsString('$quote = $chosen_key !== \'\' ? CircuitRoomOffers::withKey($quotes, $chosen_key) : $quotes[0];', $form);
        // A fresh quote is kept as before; the page's offer is kept already.
        self::assertStringContainsString("if (\$fresh) {\n        \$quoteStore->rememberQuotes([\$quote], \$circuit_id, \$departure_date, time());", $form);
    }

    /** details_json reaches fresh installs, upgrades and the installs SchemaMigrator converges. */
    public function testTheCircuitDetailsColumnReachesEveryInstall(): void
    {
        $xml = self::read('addon.xml');
        $definition = "JSON DEFAULT NULL COMMENT 'Included, not included, things to know, group size and age (static API)'";

        self::assertStringContainsString("`details_json`      {$definition},", $xml, 'the CREATE TABLE');
        self::assertStringContainsString("ALTER TABLE `?:sphinx_circuits`\n                ADD COLUMN `details_json` JSON DEFAULT NULL", $xml, 'the upgrade item');
        self::assertStringContainsString("'details_json' => \"ADD COLUMN `details_json` {$definition} AFTER `itinerary_json`\"", self::read('src/Install/SchemaMigrator.php'));
        self::assertStringContainsString("'details_json' => self::details(\$raw),", self::read('src/Services/CircuitSyncService.php'));
    }

    /**
     * A package's or circuit's terms: its booking form shows a hotel page's
     * timeline, and its cart line keeps them (raw and as lines) with the
     * booking row, so the cart, checkout and order cards show them too. They
     * kept none before. With services chosen, the customized offer's terms.
     */
    public function testATripsTermsAreShownAndKept(): void
    {
        foreach (['circuit' => 'circuit_booking_form', 'package' => 'package_booking_form'] as $kind => $mode) {
            self::assertStringContainsString("\$view->assign('sphinx_trip_terms', \\Tygh\\Addons\\SphinxHolidays\\ViewModels\\SphinxBookingSidebarBuilder::tripTerms(", self::read("controllers/frontend/sphinx_booking/{$mode}.php"), $kind);
            $template = (string) file_get_contents(dirname(__DIR__, 6) . "/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/{$mode}.tpl");
            // Both trips are on the shared booking page: the summary carries the terms (folded card).
            self::assertStringContainsString("{include file=\"addons/travel_core/components/booking_sidebar.tpl\" tbs_deposit_addon=\"sphinx_holidays\" tbs_deposit_form=\"sphinx-{$kind}-booking-form\"}", $template, $kind);
            $controller = self::read("controllers/frontend/sphinx_booking/{$mode}.php");
            $source = $kind === 'package' ? 'offer' : 'quote';
            self::assertStringContainsString("        \${$source}['cancellation_fees'] ?? null,\n        true,", $controller, $kind);
            self::assertStringContainsString("        \${$source}['payment_terms'] ?? null,\n        date('Y-m-d'),", $controller, $kind);
            self::assertStringContainsString("\$view->assign('travel_booking_sidebar', \$" . ($kind === 'package' ? 'pkg' : 'cir') . 'Sidebar->toViewArray());', $controller, $kind);

            $cart = self::read("controllers/frontend/sphinx_booking/{$kind}_add_to_cart.php");
            self::assertStringContainsString("\$payment_terms_raw = TypeCoerce::toStringMap(\$customizedMap['payment_terms'] ?? null);", $cart, $kind);
            self::assertStringContainsString("\$booking_record['cancellation_fees_json'] = json_encode(\$cancellation_fees_raw, JSON_UNESCAPED_UNICODE);", $cart, $kind);
            self::assertStringContainsString("'terms_raw' => (string) json_encode(['payment' => \$payment_terms_raw, 'cancellation' => \$cancellation_fees_raw], JSON_UNESCAPED_UNICODE),", $cart, $kind);
            self::assertLessThan(strpos($cart, '$booking_id = $cartService->upsertBooking('), strpos($cart, "\$booking_record['payment_terms_json']"), $kind . ': before the booking row is written');
        }
        self::assertStringContainsString(": \$storedQuote['payment_terms'];", self::read('controllers/frontend/sphinx_booking/circuit_add_to_cart.php'));
        self::assertStringContainsString(": TypeCoerce::toStringMap(\$verified['payment_terms'] ?? null);", self::read('controllers/frontend/sphinx_booking/package_add_to_cart.php'));
    }

    /**
     * Paying a deposit, as for a hotel: a package's or circuit's booking form
     * offers the choice where the store offers deposits and the offer's
     * terms allow one, and its add-to-cart charges the deposit those terms
     * give (CartService::chosenDeposit(), the hotel's too). Both paid in
     * full before, whatever the terms.
     */
    public function testATripMayBePaidWithADeposit(): void
    {
        $chosen = '$deposit_plan = CartService::chosenDeposit(TypeCoerce::toStringMap($_REQUEST), $cancellation_fees_raw, $payment_terms_raw);';
        foreach (['circuit' => 'circuit_booking_form', 'package' => 'package_booking_form'] as $kind => $mode) {
            $template = (string) file_get_contents(dirname(__DIR__, 6) . "/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/{$mode}.tpl");
            // As on a hotel's page: the shared choice in the form, from the summary's split.
            self::assertStringContainsString("{include file=\"addons/travel_core/components/booking_pay_choice.tpl\" pc_addon=\"sphinx_holidays\" pc_form=\"sphinx-{$kind}-booking-form\"}", $template, $kind);
            self::assertStringContainsString("id=\"sphinx-{$kind}-booking-form\"", $template, $kind . ': the form the radios post with');

            $cart = self::read("controllers/frontend/sphinx_booking/{$kind}_add_to_cart.php");
            self::assertStringContainsString($chosen, $cart, $kind);
            self::assertStringContainsString("        null,\n        \$deposit_plan,\n    );", $cart, $kind);
            self::assertLessThan(strpos($cart, $chosen), strpos($cart, '$cancellation_fees_raw = $cancellation_fees_raw !== []'), $kind . ': the terms of what is booked');
        }
        self::assertStringContainsString($chosen, self::read('controllers/frontend/sphinx_booking/add_to_cart.php'), 'a hotel the same way');
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
