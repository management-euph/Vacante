<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\CartSkipPolicy;

/**
 * Settings -> Travel Core -> "Skip the cart page for": the ticked add-ons'
 * booking buttons say "Continue booking" and go straight to checkout.checkout
 * (the one checkout page for every add-on); the others keep the cart step.
 */
final class CartSkipPolicyTest extends TestCase
{
    private static function repo(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(self::repo() . '/' . $rel);
    }

    public function testTheMultipleCheckboxesValueNamesTheTickedAddons(): void
    {
        $policy = CartSkipPolicy::fromSetting(['novoton_holidays' => 'Y', 'sphinx_holidays' => 'N', 'eurosite' => 'Y']);

        self::assertTrue($policy->skipsCart('novoton_holidays'));
        self::assertFalse($policy->skipsCart('sphinx_holidays'));
        self::assertTrue($policy->skipsCart('eurosite'));
    }

    public function testACommaListIsReadToo(): void
    {
        self::assertTrue(CartSkipPolicy::fromSetting(' sphinx_holidays , eurosite')->skipsCart('sphinx_holidays'));
    }

    public function testNothingTickedKeepsTheCartForEveryone(): void
    {
        foreach ([null, '', [], ['novoton_holidays' => 'N']] as $value) {
            $policy = CartSkipPolicy::fromSetting($value);
            foreach (['novoton_holidays', 'sphinx_holidays', 'eurosite'] as $addon) {
                self::assertSame('checkout.cart', $policy->afterAddToCart($addon));
                self::assertSame('travel_core.continue_to_checkout', $policy->ctaLabelKey($addon));
            }
        }
    }

    public function testATickedAddonGoesToCheckoutAndSaysContinueBooking(): void
    {
        $policy = CartSkipPolicy::fromSetting(['novoton_holidays' => 'Y']);

        self::assertSame('checkout.checkout', $policy->afterAddToCart('novoton_holidays'));
        self::assertSame('travel_core.continue_booking', $policy->ctaLabelKey('novoton_holidays'));
        self::assertSame('checkout.cart', $policy->afterAddToCart('sphinx_holidays'));
    }

    /** Circuit and package forms keep their "Add to Cart" label when the cart stays. */
    public function testAFormKeepsItsOwnLabelWhenTheCartStays(): void
    {
        $policy = CartSkipPolicy::fromSetting(['sphinx_holidays' => 'Y']);

        self::assertSame('sphinx_holidays.add_to_cart_btn', CartSkipPolicy::fromSetting([])->ctaLabelKey('sphinx_holidays', 'sphinx_holidays.add_to_cart_btn'));
        self::assertSame('travel_core.continue_booking', $policy->ctaLabelKey('sphinx_holidays', 'sphinx_holidays.add_to_cart_btn'));
    }

    public function testTheSettingIsDeclaredForTheThreeAddonsAndOffByDefault(): void
    {
        $xml = simplexml_load_file(self::repo() . '/addon-travel-core/app/addons/travel_core/addon.xml');
        self::assertNotFalse($xml);
        $item = $xml->xpath('//item[@id="skip_cart_for"]');
        self::assertIsArray($item);
        self::assertCount(1, $item);
        self::assertSame('multiple checkboxes', (string) $item[0]->type);
        self::assertSame('', trim((string) $item[0]->default_value));
        $variants = [];
        foreach ($item[0]->variants->item as $v) {
            $variants[] = (string) $v['id'];
        }
        self::assertSame(['novoton_holidays', 'sphinx_holidays', 'eurosite'], $variants);

        foreach (['en', 'ro'] as $lang) {
            $po = self::src("addon-travel-core/var/langs/{$lang}/addons/travel_core.po");
            foreach ($variants as $v) {
                self::assertStringContainsString("SettingsVariants::travel_core::skip_cart_for::{$v}\"", $po, $lang);
            }
            self::assertStringContainsString('Languages::travel_core.continue_booking"', $po, $lang);
        }
        self::assertStringContainsString('msgstr "Continuă rezervarea"', self::src('addon-travel-core/var/langs/ro/addons/travel_core.po'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function redirects(): iterable
    {
        yield 'novoton add to cart' => ['addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/add_to_cart.php', "fn_travel_core_after_add_to_cart_url('novoton_holidays')"];
        yield 'novoton booking edited' => ['addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/update_booking.php', "fn_travel_core_after_add_to_cart_url('novoton_holidays')"];
        yield 'sphinx hotels, circuits, packages' => ['addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/CartService.php', "CartSkipPolicy::current()->afterAddToCart('sphinx_holidays')"];
        yield 'sphinx booking edited' => ['addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/update_booking.php', "fn_travel_core_after_add_to_cart_url('sphinx_holidays')"];
        yield 'eurosite add to cart' => ['eurosite_addon/app/addons/eurosite/controllers/frontend/eurosite_booking/add_to_cart.php', "fn_travel_core_after_add_to_cart_url('eurosite')"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirects')]
    public function testEveryBookingPathAsksThePolicyWhereToGo(string $file, string $needle): void
    {
        self::assertStringContainsString($needle, self::src($file));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function buttons(): iterable
    {
        yield 'novoton responsive' => ['addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl', 'fn_travel_core_booking_cta_label("novoton_holidays")', 2];
        yield 'novoton nova_theme' => ['addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl', 'fn_travel_core_booking_cta_label("novoton_holidays")', 2];
        yield 'sphinx hotel' => ['addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl', 'fn_travel_core_booking_cta_label("sphinx_holidays")', 2];
        yield 'sphinx circuit' => ['addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/circuit_booking_form.tpl', 'fn_travel_core_booking_cta_label("sphinx_holidays", "sphinx_holidays.add_to_cart_btn")', 1];
        yield 'sphinx package' => ['addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/package_booking_form.tpl', 'fn_travel_core_booking_cta_label("sphinx_holidays", "sphinx_holidays.add_to_cart_btn")', 1];
        yield 'eurosite' => ['eurosite_addon/design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/booking_form.tpl', 'fn_travel_core_booking_cta_label("eurosite")', 2];
    }

    /** The button and the mobile bar both follow the setting (edit mode keeps its own label). */
    #[\PHPUnit\Framework\Attributes\DataProvider('buttons')]
    public function testEveryBookingButtonTakesItsLabelFromThePolicy(string $file, string $needle, int $times): void
    {
        $tpl = self::src($file);

        self::assertSame($times, substr_count($tpl, $needle), $file);
        self::assertStringNotContainsString('__("travel_core.continue_to_checkout")', $tpl);
    }

    /** Error redirects stay on the cart: that is where the customer sees what went wrong. */
    public function testErrorsStillLandOnTheCart(): void
    {
        $src = self::src('addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/update_booking.php');

        self::assertStringContainsString("fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));\n        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];", $src);
    }
}
