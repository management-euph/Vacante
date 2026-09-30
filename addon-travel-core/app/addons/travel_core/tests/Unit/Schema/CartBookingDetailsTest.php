<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shared cart/checkout booking card — ONE component for every
 * provider's cart line (ported from novoton's per-provider hooks, which are
 * deleted). Every value comes prepared from fn_travel_core_cart_booking_card()
 * (ViewModels\CartBookingCardFactory, unit-tested on its own), so the markup
 * carries no provider branch, no decoding and no inline JS or styles; a
 * detail a provider does not supply arrives empty and its block is skipped.
 */
final class CartBookingDetailsTest extends TestCase
{
    private static function tpl(string $rel): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/travel_core/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function code(string $tpl): string
    {
        return (string) preg_replace('/\{\*.*?\*\}/s', '', $tpl);
    }

    public function testCardRendersThePreparedViewArrayOnly(): void
    {
        $card = self::code(self::tpl('components/cart_booking_details.tpl'));

        self::assertStringContainsString("{\$tcc = fn_travel_core_cart_booking_card(\$product|default:[], \$key|default:'')}", $card);
        self::assertStringContainsString('{if $tcc}', $card);
        // No provider branch, labels from travel_core keys only.
        self::assertStringNotContainsString('travel_provider', $card);
        self::assertStringNotContainsString('novoton_holidays.', $card);
        self::assertStringNotContainsString('sphinx_holidays.', $card);
        // Nothing derived in the markup any more.
        self::assertStringNotContainsString('json_decode', $card);
        self::assertStringNotContainsString('date_format', $card);
        // Collapsibles are native <details>: no JS, no onclick handlers.
        self::assertStringContainsString('<details class="travel-ccard-disclosure"', $card);
        self::assertStringNotContainsString('<script', $card);
        self::assertStringNotContainsString('{script', $card);
        self::assertStringNotContainsString('onclick', $card);
        // The edit link comes built from the view model.
        self::assertStringContainsString('{$tcc.edit_url|fn_url}', $card);
        // The full terms reuse the booking page's one timeline partial.
        self::assertStringContainsString('components/booking_terms_timeline.tpl" tt=$tcc.terms', $card);
    }

    public function testGuestListEscapesNamesAndMarksTheLeadGuest(): void
    {
        $body = self::code(self::tpl('components/cart_booking_room_body.tpl'));

        self::assertStringContainsString('{$cbr_guest.name|escape:html}', $body);
        self::assertStringContainsString('travel_core.lead_guest', $body);
        self::assertStringContainsString('travel_core.years_old', $body);
        self::assertStringContainsString('{$smarty.foreach.cbr_guests.iteration}', $body);
    }

    public function testCheckoutSummaryUsesTheSidebarFormAndCartPagesTheDefault(): void
    {
        self::assertStringContainsString('tcc_context="sidebar"', self::tpl('hooks/block_checkout/product_extra.post.tpl'));
        self::assertStringNotContainsString('tcc_context', self::tpl('hooks/checkout/product_info.post.tpl'));
        self::assertStringNotContainsString('tcc_context', self::tpl('hooks/cart_content/product_info.post.tpl'));

        // The sidebar form hides the core product line above it — by the
        // card's own position, never by a theme's class names.
        $css = (string) file_get_contents(dirname(__DIR__, 6) . '/design/themes/responsive/css/addons/travel_core/booking-pages.css');
        self::assertStringContainsString(':where(li, div) > :not(li, .travel-ccard):has(~ .travel-ccard--sidebar) { display: none; }', $css);
    }

    public function testTravelCoreOwnsTheCheckoutHooksAndNovotonCopiesAreGone(): void
    {
        foreach ([
            'hooks/checkout/product_info.post.tpl',
            'hooks/block_checkout/product_extra.post.tpl',
            'hooks/cart_content/product_info.post.tpl',
        ] as $hook) {
            self::assertStringContainsString(
                'addons/travel_core/components/cart_booking_details.tpl',
                self::tpl($hook),
                $hook,
            );
        }

        $repoRoot = dirname(__DIR__, 7);
        foreach ([
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/checkout/product_info.post.tpl',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/block_checkout/product_extra.post.tpl',
            // With this twin present alongside travel_core's cart_content
            // hook, every novoton cart line rendered TWO stacked cards.
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/cart_content/product_info.post.tpl',
        ] as $gone) {
            self::assertFileDoesNotExist($repoRoot . '/' . $gone, 'the per-provider hook copy must stay deleted');
        }
    }

    public function testPriceCorrectionDisplayIsSharedAndProviderNeutral(): void
    {
        $card = self::code(self::tpl('components/cart_booking_details.tpl'));

        // Both providers' pre-order verifiers write price_before_correction;
        // the view model turns it into price_change, the card strikes the
        // old price through and says which way it moved.
        self::assertStringContainsString('{if $tcc.price_change}', $card);
        self::assertStringContainsString('travel-ccard-pricechange__old', $card);
        self::assertStringContainsString('travel_core.price_updated_badge', $card);
        self::assertStringContainsString('travel_core.price_dropped_badge', $card);
    }

    public function testGuestCardsSupportOptionalPrefillWithoutChangingDefaults(): void
    {
        // The outer component owns the prefill parameter and delegates each
        // room to the shared room body (which novoton also includes directly
        // around its own per-room banners).
        $cards = self::tpl('components/booking_guest_cards.tpl');
        self::assertStringContainsString('{$_prefill = $guest_prefill|default:[]}', $cards);
        self::assertStringContainsString('components/booking_guest_room_body.tpl', $cards);
        self::assertStringContainsString('gb_prefill=$_prefill', $cards);

        // Edit mode passes guest_prefill keyed like the input names; absent,
        // the value attributes resolve to '' (non-edit renders unchanged).
        $body = self::tpl('components/booking_guest_room_body.tpl');
        self::assertStringContainsString("value=\"{\$_prefill.\$_pf_key.last_name|default:''|escape:html}\"", $body);
        self::assertStringContainsString("value=\"{\$_prefill.\$_pf_key.first_name|default:''|escape:html}\"", $body);
        self::assertStringContainsString("value=\"{\$_prefill.\$_pf_key.dob|default:''|escape:html}\"", $body);
    }

    public function testCardLangKeysAreSeeded(): void
    {
        $vars = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($vars);
        foreach ([
            'travel_core.your_booking_details',
            'travel_core.total_stay',
            'travel_core.occupancy',
            'travel_core.guest_names',
            'travel_core.holder',
            'travel_core.meal_plan',
            'travel_core.save_changes',
            'travel_core.price_per_night',
            'travel_core.edit_guests',
            'travel_core.lead_guest',
            'travel_core.guest_n',
            'travel_core.how_you_pay',
            'travel_core.split_today',
            'travel_core.cancel_then_pay',
            'travel_core.cancel_now_costs',
            'travel_core.cancel_now_full',
        ] as $key) {
            self::assertArrayHasKey($key, $vars, $key);
        }
    }
}
