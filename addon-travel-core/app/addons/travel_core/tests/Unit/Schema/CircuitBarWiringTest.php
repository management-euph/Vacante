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

        self::assertStringContainsString('? TravelProviderRegistry::resolveCircuit($productId, $productCode)', $hooks);
        self::assertStringContainsString('$isCircuit = $circuit !== null;', $hooks);
        self::assertStringContainsString('($hotelSeoData !== null || $isCircuit)', $hooks);
    }

    /**
     * Under the rooms, the circuit's program, hotels, things to know and
     * inclusions (board 16.3): assigned for a circuit whether or not the
     * booking form shows, and drawn after the bar (the tabs hook's pre-hook)
     * and before the product tabs.
     */
    public function testTheCircuitsProgramIsOnItsPage(): void
    {
        $hooks = self::read('hooks/cart_hooks.php');
        $assign = (int) strpos($hooks, "\$view->assign('travel_circuit', \$circuitView->view(\$circuitDetails));");
        self::assertGreaterThan(0, $assign);
        self::assertStringContainsString('$circuitDetails = TravelProviderRegistry::circuitDetails($circuit);', $hooks);
        self::assertGreaterThan($assign, (int) strpos($hooks, '_travel_core_prepare_hotel_seo_data($productId);'), 'outside the booking form switch');

        foreach (['responsive', 'nova_theme'] as $theme) {
            $page = (string) file_get_contents(dirname(__DIR__, 6) . "/design/themes/{$theme}/templates/addons/travel_core/blocks/product_templates/travelcore_design_a.tpl");
            $hook = (int) strpos($page, '{hook name="products:product_tabs"}');
            $details = (int) strpos($page, '{include file="addons/travel_core/components/circuit_details.tpl" travel_circuit=$travel_circuit}');
            self::assertGreaterThan($hook, $details, $theme . ': inside the tabs hook, after its pre-hook (the bar)');
            self::assertLessThan((int) strpos($page, '{include file="views/tabs/components/product_tabs.tpl"'), $details, $theme . ': before the tabs');
        }

        $component = (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/travel_core/components/circuit_details.tpl');
        // Supplier texts are escaped; the line breaks kept come after it.
        self::assertStringContainsString('{$travel_circuit_text|escape:html|nl2br nofilter}', $component);
        self::assertSame(0, preg_match('/\{\$travel_circuit_(?:day\.(?:title|hotel|meals)|stay\.hotel|note\.title|item|text)\}/', $component), 'every supplier text goes through escape');
        // Under its title: its route in the location line, then its facts;
        // "Alege data plecării" for a circuit (its bar has no calendar).
        self::assertStringContainsString("\$view->assign('travel_circuit_facts', \$circuitView->facts(\$circuit, \$circuitDetails));", $hooks);
        foreach (['responsive', 'nova_theme'] as $theme) {
            $page = (string) file_get_contents(dirname(__DIR__, 6) . "/design/themes/{$theme}/templates/addons/travel_core/blocks/product_templates/travelcore_design_a.tpl");
            $head = (int) strpos($page, '{* #10. Title row *}');
            $facts = (int) strpos($page, '{include file="addons/travel_core/components/circuit_facts.tpl" travel_circuit_facts=$travel_circuit_facts}');
            self::assertGreaterThan($head, $facts, $theme);
            self::assertLessThan((int) strpos($page, '{* /Title row *}'), $facts, $theme . ': in the title row');
            self::assertStringContainsString('{$travel_circuit_facts.route_line|escape:html}', $page, $theme);
            self::assertStringContainsString('{if $travel_circuit_facts}{__("travel_core.choose_departure_date")}{else}{__("travel_core.pdpa_choose_dates")}{/if}', $page, $theme);
        }
        $facts = (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/travel_core/components/circuit_facts.tpl');
        self::assertStringContainsString('["[city]" => $travel_circuit_facts.from_city|escape:html]', $facts, 'the city is supplier text');

        $icons = (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/travel_core/components/design_a_icon.tpl');
        foreach (['route', 'cal', 'plane', 'bus'] as $icon) {
            self::assertStringContainsString('{elseif $icon == "' . $icon . '"}', $icons);
        }
        self::assertStringContainsString('{elseif $icon == "bed"}', $icons);
        self::assertStringContainsString('{elseif $icon == "nocheck"}', $icons);
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

    /** "Camera pentru tot circuitul" (board 16.3): where its rooms come from, the hotels' stars, and its texts. */
    public function testBookingConfigAnswersTheCircuitsRooms(): void
    {
        $config = self::read('controllers/frontend/travel_booking.php');

        self::assertStringContainsString("'quoteDispatch' => \$providerName . '_booking.circuit_quote',", $config);
        self::assertStringContainsString("'hotelStars' => \$circuit->hotelStars(),", $config);
        $keys = [
            'circuit_modify', 'circuit_rooms_title', 'circuit_rooms_departure', 'circuit_outbound', 'circuit_inbound',
            'circuit_hotels_stars', 'circuit_hotels', 'circuit_nights_one', 'circuit_nights_few', 'circuit_nights_many',
            'circuit_per_person', 'circuit_for_one_adult', 'circuit_book', 'circuit_loading_rooms', 'circuit_rooms_error',
            'circuit_no_rooms', 'circuit_rooms_ages', 'circuit_terms_at_booking',
        ];
        foreach ($keys as $key) {
            self::assertStringContainsString("'{$key}'", $config, 'the rooms text ' . $key . ' is sent');
        }
    }

    /** The button shows the rooms under the bar (no longer the next page), then turns secondary: "Modifică". */
    public function testTheBarShowsTheRoomsUnderIt(): void
    {
        // The mount hands the bar where the rooms come from and the hotels' stars.
        $index = self::engine('index.jsx');
        self::assertStringContainsString("quoteDispatch:      serverConfig.quoteDispatch || '',", $index);
        self::assertStringContainsString('hotelStars:         serverConfig.hotelStars || null,', $index);

        $bar = self::engine('CircuitBar.jsx');

        self::assertStringContainsString('<CircuitRooms quote={quote} hotelStars={hotelStars} bookHref={bookHref} />', $bar);
        self::assertStringContainsString("fetch(quoteUrl(base, quoteDispatch, choice)", $bar);
        self::assertStringContainsString("{asked ? t('circuitModify', 'Change') : t('circuitSeePrice', 'See the price')}", $bar);
        self::assertStringNotContainsString('window.location.href = quoteUrl(', $bar);
        // Each room's "Rezervă" opens the booking form on its own offer, where
        // the terms are shown (board 16.3: the note, once, on the first room).
        $rooms = self::engine('CircuitRooms.jsx');
        self::assertStringContainsString('href={bookHref(offer)}', $rooms);
        self::assertStringContainsString("{b === 0 && o === 0 && (", $rooms);
        self::assertStringContainsString("t('circuitTermsAtBooking',", $rooms);
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
