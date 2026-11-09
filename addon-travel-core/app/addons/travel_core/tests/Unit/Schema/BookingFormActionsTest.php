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
            '{fn_travel_core_booking_cta_label($fa_addon)}{/if}{if $fa_total} (<span class="price-total">{$fa_total nofilter}</span>){/if} &rarr;',
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

    /**
     * "How would you like to pay?" sits in the form, just before the
     * button, on the pages that offer a deposit, and the summary leaves its
     * own split box out there, so the page has one #travel-price-split for
     * Novoton's re-price to refill.
     */
    public function testThePaymentChoiceSitsInTheFormBeforeTheButton(): void
    {
        foreach ([
            'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl' => '{include file="addons/travel_core/components/booking_pay_choice.tpl" pc_addon="sphinx_holidays" pc_form="sphinx-booking-form"}',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl' => '{include file="addons/travel_core/components/booking_pay_choice.tpl" pc_addon="novoton_holidays" pc_form="novoton-booking-form"}',
            'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl' => '{include file="addons/travel_core/components/booking_pay_choice.tpl" pc_addon="novoton_holidays" pc_form="novoton-booking-form"}',
        ] as $form => $include) {
            $src = self::read($form);
            self::assertStringContainsString($include, $src, $form);
            self::assertLessThan(strpos($src, 'components/booking_form_actions.tpl'), strpos($src, $include), $form . ': before the button');
        }
        // Sphinx's guest section is the same form section as the other pages', so they line up.
        self::assertStringContainsString(
            '<div class="travel-form-section guest-names-section">',
            self::read('addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl'),
        );
        self::assertStringContainsString(
            "var box = document.getElementById('travel-price-split');",
            self::read('addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js'),
        );
    }

    /** A titled group of whole-row cards; the picked one green, with a "Selected" badge. */
    public function testThePaymentCardsSayWhichIsPicked(): void
    {
        $split = self::read('addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_payment_split.tpl');

        self::assertStringContainsString('<legend class="travel-paymode__legend">{__("travel_core.pay_mode_legend")}</legend>', $split);
        self::assertSame(2, substr_count($split, '<span class="travel-paymode__sel">{__("travel_core.option_selected")}</span>'));
        // The balance goes into the hint in <strong>: printed as markup, with the date escaped where it is built.
        self::assertStringContainsString('{capture assign="ps_due"}{$ps.balance_due|escape:html}{/capture}{__("travel_core.pay_deposit_hint", ["[balance]" => $ps_balance, "[date]" => $ps_due]) nofilter}', $split);

        $css = self::read('addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css');
        self::assertMatchesRegularExpression('/\.travel-paymode__opt:has\(input:checked\) \{\s*border: 2px solid #15803d;[^}]*background: #eef8f1;/', $css);
        self::assertStringContainsString('.travel-booking-page .travel-paymode__opt:has(input:checked) .travel-paymode__sel {', $css);
        self::assertStringContainsString('.travel-booking-page .travel-paychoice:not(:has(.travel-paymode)) { display: none; }', $css);

        $keys = require dirname(__DIR__, 7) . '/addon-travel-core/app/addons/travel_core/lang_keys.php';
        self::assertIsArray($keys);
        self::assertSame('Selectat', $keys['travel_core.option_selected']['ro'] ?? null);
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
