<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The booking form's one main button (components/booking_form_actions.tpl):
 * "Continue to payment (849 €)". The total in its label is a `.price-total`,
 * which every re-pricing script rewrites with the summary's, and a phone
 * shows only the bottom bar's button.
 */
final class BookingFormActionsTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 7) . '/' . $path);
    }

    private static function actions(): string
    {
        return self::read('addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_form_actions.tpl');
    }

    public function testTheNewBookingButtonCarriesTheTotal(): void
    {
        $tpl = self::actions();
        $newBooking = substr($tpl, (int) strpos($tpl, '{else}'));

        self::assertStringContainsString('{$fa_total = $travel_booking_sidebar.total|default:""}', $newBooking);
        self::assertStringContainsString(
            '{fn_travel_core_booking_cta_label($fa_addon)}{if $fa_total} (<span class="price-total">{$fa_total nofilter}</span>){/if} &rarr;',
            $newBooking,
        );
        // Editing a cart line saves; it names no price.
        $editStart = (int) strpos($tpl, '{if $fa_edit_mode|default:false}');
        $edit = substr($tpl, $editStart, (int) strpos($tpl, '{else}') - $editStart);
        self::assertStringContainsString('{__("travel_core.save_changes")}', $edit);
        self::assertStringNotContainsString('price-total', $edit);
    }

    /** Every re-pricing script rewrites every .price-total, the button's included. */
    public function testTheRepriceScriptsRewriteEveryTotal(): void
    {
        self::assertStringContainsString(
            "document.querySelectorAll('.price-total').forEach(",
            self::read('addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js'),
        );
        self::assertStringContainsString(
            "priceDisplay: '.price-total,",
            self::read('addon-travel-core/js/addons/travel_core/booking-form-validation.js'),
        );
    }

    public function testAPhoneShowsOneMainButton(): void
    {
        $css = self::read('addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css');

        self::assertStringContainsString('.travel-booking-page .travel-btn--primary .price-total { font-size: inherit; font-weight: inherit; color: inherit; }', $css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 900px\) \{\s*\.travel-booking-page:has\(\.travel-mobile-bar\) \.travel-form-actions--cta \.travel-btn--primary \{ display: none; \}/',
            $css,
        );
        // The bar clicks the form's own button, so the hidden one must stay a real submit button.
        self::assertStringContainsString(
            "form.querySelector('button[type=\"submit\"], input[type=\"submit\"]')",
            self::read('addon-travel-core/js/addons/travel_core/booking-conditions.js'),
        );
        self::assertStringContainsString('<button type="submit" class="travel-btn--primary', self::actions());
    }
}
