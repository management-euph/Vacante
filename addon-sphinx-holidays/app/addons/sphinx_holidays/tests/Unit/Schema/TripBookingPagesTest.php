<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sphinx's package and circuit pages are on the shared booking page, as a
 * hotel's: the three-step bar, the guest form on the left with the airline
 * warning, the services as cards, the deposit choice and one button with
 * the total; the shared summary on the right with the trip's kind, flights
 * and inclusions.
 */
final class TripBookingPagesTest extends TestCase
{
    /** @return array<string, array{string, string, string}> kind => [mode, airline flag, sidebar variable] */
    public static function trips(): array
    {
        return [
            'package' => ['package_booking_form', '{$pkg_airline = $sphinx_package_booking.transport_type == "flight"}', 'pkg'],
            'circuit' => ['circuit_booking_form', '{$cir_airline = !empty($sphinx_circuit_booking.flight)}', 'cir'],
        ];
    }

    private static function template(string $mode): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6)
            . "/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/{$mode}.tpl");
    }

    private static function controller(string $mode): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . "/controllers/frontend/sphinx_booking/{$mode}.php");
    }

    #[DataProvider('trips')]
    public function testThePageIsTheSharedBookingPage(string $mode, string $airline, string $var): void
    {
        $tpl = self::template($mode);
        $kind = str_replace('_booking_form', '', $mode);

        $order = [
            '{include file="addons/travel_core/components/travel_i18n.tpl"}',
            '{include file="addons/travel_core/components/booking_steps.tpl"}',
            '<div class="travel-booking-layout">',
            "{include file=\"addons/travel_core/components/booking_sidebar.tpl\" tbs_deposit_addon=\"sphinx_holidays\" tbs_deposit_form=\"sphinx-{$kind}-booking-form\"}",
            '<div class="travel-booking-col-main">',
            "id=\"sphinx-{$kind}-booking-form\"",
            '<div class="travel-form-section guest-names-section">',
            '<section class="travel-form-section travel-extras"',
            "{include file=\"addons/travel_core/components/booking_pay_choice.tpl\" pc_addon=\"sphinx_holidays\" pc_form=\"sphinx-{$kind}-booking-form\"}",
            '{include file="addons/travel_core/components/booking_form_actions.tpl"',
            '{include file="addons/travel_core/components/booking_conditions_modal.tpl"}',
            "{include file=\"addons/travel_core/components/booking_mobile_bar.tpl\" mb_form_id=\"sphinx-{$kind}-booking-form\"",
        ];
        $at = -1;
        foreach ($order as $needle) {
            $pos = strpos($tpl, $needle);
            self::assertNotFalse($pos, $needle);
            self::assertGreaterThan($at, $pos, $needle . ' comes after the one before');
            $at = $pos;
        }
        // Its own summary header, flight, transfer, included and terms cards are gone: the shared summary shows them.
        foreach (['travel-booking-summary', 'sphinx-flight-info', 'travel-included-row', 'sphinx-trip-terms', 'booking_terms_timeline.tpl', 'travel-service-row'] as $gone) {
            self::assertStringNotContainsString($gone, $tpl);
        }
    }

    #[DataProvider('trips')]
    public function testTheGuestFormWarnsAboutTheAirlineAndAsksWhatTheBookingNeeds(string $mode, string $airline, string $var): void
    {
        $tpl = self::template($mode);
        $flag = (string) preg_replace('/^\{(\$\w+) = .*$/', '$1', $airline);

        self::assertStringContainsString($airline, $tpl);
        self::assertStringContainsString('{include file="addons/travel_core/components/booking_guest_hint.tpl" gh_airline=' . $flag . '}', $tpl);
        foreach (['adult_dob_required=true', 'child_dob_required=true', 'guard_expected_ages=true', 'guest_gender_options=$sphinx_gender_options', 'guest_child_gender_options=$sphinx_gender_options'] as $param) {
            self::assertStringContainsString($param, $tpl);
        }
    }

    #[DataProvider('trips')]
    public function testServicesAreWholeRowCardsAndTheButtonKeepsItsLabel(string $mode, string $airline, string $var): void
    {
        $tpl = self::template($mode);

        self::assertStringContainsString('<label class="travel-extra{if $service.type == \'mandatory\'} travel-extra--fixed{/if}">', $tpl);
        self::assertStringContainsString('<input type="checkbox" name="services[]" value="{$service.code}" checked disabled>', $tpl);
        self::assertStringContainsString('<span class="travel-extra__sel">{__("travel_core.option_selected")}</span>', $tpl);
        self::assertStringContainsString('fa_label_key="sphinx_holidays.add_to_cart_btn"', $tpl);
        self::assertStringContainsString('fa_back_url=$travel_booking_sidebar.change_url|default:""', $tpl);
        self::assertStringContainsString('fa_back_label=__("travel_core.back_to_offer")', $tpl);
    }

    #[DataProvider('trips')]
    public function testTheControllerBuildsTheTripsSummary(string $mode, string $airline, string $var): void
    {
        $php = self::controller($mode);

        self::assertStringContainsString("\${$var}Sidebar = ", $php);
        self::assertStringContainsString('SphinxBookingSidebarBuilder::build(', $php);
        self::assertStringContainsString(")->withTrip(\n", $php);
        self::assertStringContainsString('SphinxBookingSidebarBuilder::tripLegs(', $php);
        self::assertStringContainsString('SphinxBookingSidebarBuilder::tripIncludes(', $php);
        self::assertStringContainsString("\$view->assign('travel_booking_sidebar', \${$var}Sidebar->toViewArray());", $php);
    }

    public function testAPackageNamesItsKindAndLeadsBackToItsDeparture(): void
    {
        $php = self::controller('package_booking_form');

        foreach (['flight', 'bus', 'hotel'] as $kind) {
            self::assertStringContainsString("__('sphinx_holidays.trip_kind_{$kind}')", $php);
        }
        self::assertStringContainsString("'departure_date' => \$pkgCheckIn,", $php, 'back to the hotel page on this departure');
    }

    /** A circuit: "Plecare" and "Întoarcere" over its dates, its own picture when its product has none. */
    public function testACircuitNamesItsDatesAndKeepsItsPicture(): void
    {
        $php = self::controller('circuit_booking_form');

        self::assertStringContainsString("__('sphinx_holidays.trip_kind_circuit')", $php);
        self::assertStringContainsString("TypeCoerce::toString(__('sphinx_holidays.departure')),\n        TypeCoerce::toString(__('sphinx_holidays.trip_return')),\n        TypeCoerce::toString(\$quote['image'] ?? ''),", $php);
        self::assertStringContainsString("\$cirReturn = \$cirDepartTs > 0 ? date('Y-m-d', (int) strtotime('+' . \$cirNights . ' days', \$cirDepartTs)) : '';", $php);
    }
}
