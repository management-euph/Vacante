<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Sphinx's package page is on the shared booking page, as a hotel's: the
 * three-step bar, the guest form on the left with the airline warning, the
 * services as cards, the deposit choice and one button with the total; the
 * shared summary on the right with the trip's kind, flights and inclusions.
 */
final class PackageBookingPageTest extends TestCase
{
    private static function template(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/package_booking_form.tpl');
    }

    private static function controller(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/package_booking_form.php');
    }

    public function testThePageIsTheSharedBookingPage(): void
    {
        $tpl = self::template();

        $order = [
            '{include file="addons/travel_core/components/travel_i18n.tpl"}',
            '{include file="addons/travel_core/components/booking_steps.tpl"}',
            '<div class="travel-booking-layout">',
            '{include file="addons/travel_core/components/booking_sidebar.tpl" tbs_deposit_addon="sphinx_holidays" tbs_deposit_form="sphinx-package-booking-form"}',
            '<div class="travel-booking-col-main">',
            'id="sphinx-package-booking-form"',
            '<div class="travel-form-section guest-names-section">',
            '<section class="travel-form-section travel-extras"',
            '{include file="addons/travel_core/components/booking_pay_choice.tpl" pc_addon="sphinx_holidays" pc_form="sphinx-package-booking-form"}',
            '{include file="addons/travel_core/components/booking_form_actions.tpl"',
            '{include file="addons/travel_core/components/booking_conditions_modal.tpl"}',
            '{include file="addons/travel_core/components/booking_mobile_bar.tpl" mb_form_id="sphinx-package-booking-form"',
        ];
        $at = -1;
        foreach ($order as $needle) {
            $pos = strpos($tpl, $needle);
            self::assertNotFalse($pos, $needle);
            self::assertGreaterThan($at, $pos, $needle . ' comes after the one before');
            $at = $pos;
        }
        // Its own summary header, flight, transfer, included and terms cards are gone: the shared summary shows them.
        foreach (['travel-booking-summary', 'sphinx-flight-info', 'travel-included-row', 'sphinx-trip-terms', 'booking_terms_timeline.tpl'] as $gone) {
            self::assertStringNotContainsString($gone, $tpl);
        }
    }

    public function testTheGuestFormWarnsAboutTheAirlineAndAsksWhatTheBookingNeeds(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('{$pkg_airline = $sphinx_package_booking.transport_type == "flight"}', $tpl);
        self::assertStringContainsString('{include file="addons/travel_core/components/booking_guest_hint.tpl" gh_airline=$pkg_airline}', $tpl);
        foreach (['adult_dob_required=true', 'child_dob_required=true', 'guard_expected_ages=true', 'guest_gender_options=$sphinx_gender_options', 'guest_child_gender_options=$sphinx_gender_options'] as $param) {
            self::assertStringContainsString($param, $tpl);
        }
    }

    public function testServicesAreWholeRowCardsAndTheButtonKeepsItsLabel(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('<label class="travel-extra{if $service.type == \'mandatory\'} travel-extra--fixed{/if}">', $tpl);
        self::assertStringContainsString('<input type="checkbox" name="services[]" value="{$service.code}" checked disabled>', $tpl);
        self::assertStringContainsString('<span class="travel-extra__sel">{__("travel_core.option_selected")}</span>', $tpl);
        self::assertStringContainsString('fa_label_key="sphinx_holidays.add_to_cart_btn"', $tpl);
        self::assertStringContainsString('fa_back_url=$travel_booking_sidebar.change_url|default:""', $tpl);
        self::assertStringContainsString('fa_back_label=__("travel_core.back_to_offer")', $tpl);
    }

    public function testTheControllerBuildsTheTripsSummary(): void
    {
        $php = self::controller();

        self::assertStringContainsString('$pkgSidebar = SphinxBookingSidebarBuilder::build(', $php);
        self::assertStringContainsString(")->withTrip(\n", $php);
        foreach (['flight', 'bus', 'hotel'] as $kind) {
            self::assertStringContainsString("__('sphinx_holidays.trip_kind_{$kind}')", $php);
        }
        self::assertStringContainsString('SphinxBookingSidebarBuilder::tripLegs(', $php);
        self::assertStringContainsString('SphinxBookingSidebarBuilder::tripIncludes(', $php);
        self::assertStringContainsString("'departure_date' => \$pkgCheckIn,", $php, 'back to the hotel page on this departure');
        self::assertStringContainsString("\$view->assign('travel_booking_sidebar', \$pkgSidebar->toViewArray());", $php);
    }
}
