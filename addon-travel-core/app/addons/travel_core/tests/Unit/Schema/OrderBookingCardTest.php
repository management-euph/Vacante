<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the booking card on the order pages — ONE card for every provider:
 *
 *  - admin (orders:product_info, backend): components/order_booking_card.tpl,
 *    with status, supplier reference, supplier price and the failure alert;
 *  - customer (orders:product_info, both themes): the checkout's card in its
 *    "order" form, built for the customer — no provider name, supplier
 *    reference or supplier price, which the view model never hands over and
 *    the template never reads.
 *
 * The per-provider text blocks it replaces (novoton, sphinx, eurosite) are
 * gone, so a line never shows two blocks. Templates are invisible to PHPStan;
 * the view model itself is unit-tested (OrderBookingCardFactoryTest).
 */
final class OrderBookingCardTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function read(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function code(string $tpl): string
    {
        return (string) preg_replace('/\{\*.*?\*\}/s', '', $tpl);
    }

    public function testTheAdminHookDrawsTheAdminCard(): void
    {
        $hook = self::code(self::read('design/backend/templates/addons/travel_core/hooks/orders/product_info.post.tpl'));

        self::assertStringContainsString('{$tobc = fn_travel_core_order_booking_card($oi, $order_info|default:[], "admin")}', $hook);
        self::assertStringContainsString('{include file="addons/travel_core/components/order_booking_card.tpl" tobc=$tobc}', $hook);
    }

    public function testTheCustomerHookDrawsTheCustomerCardInBothThemes(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $hook = self::code(self::read("design/themes/{$theme}/templates/addons/travel_core/hooks/orders/product_info.post.tpl"));

            self::assertStringContainsString('{$tobc = fn_travel_core_order_booking_card($product, $order_info|default:[], "customer")}', $hook, $theme);
            self::assertStringContainsString('tcc_card=$tobc tcc_context="order"', $hook, $theme);
            self::assertStringNotContainsString('"admin"', $hook, $theme);
        }
    }

    public function testTheCustomerCardNeverReadsProviderData(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $card = self::code(self::read("design/themes/{$theme}/templates/addons/travel_core/components/cart_booking_details.tpl"));

            foreach (['$tcc.provider', '$tcc.reference', '$tcc.our_reference', '$tcc.supplier_price', '$tcc.note', '$tcc.alert', '$tcc.booking_id', '$tcc.booked_at', '$tcc.updated_at'] as $key) {
                self::assertStringNotContainsString($key, $card, "{$theme}: {$key}");
            }
            // Supplier per-room prices are blanked in the order card's view model.
            self::assertStringContainsString('{if $tcc_room.price}', $card, $theme);
        }
    }

    public function testTheAdminCardIsBuiltOnlyInTheAdminArea(): void
    {
        $fn = self::read('app/addons/travel_core/functions/order_card.php');

        self::assertStringContainsString(
            "\$admin = \$audience === OrderBookingCardFactory::AUDIENCE_ADMIN && fn_travel_core_order_card_full_admin();",
            $fn,
        );
        // travel_bookings' own guard (Functions\OrderCardAudienceTest runs it).
        self::assertStringContainsString("if (!defined('AREA') || AREA !== 'A' || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {", $fn);
        self::assertStringContainsString('$meta = $admin ? fn_travel_core_order_card_admin_meta($facts, $booking) : [];', $fn);
    }

    public function testTheAdminCardEscapesEverySuppliedText(): void
    {
        $card = self::code(self::read('design/backend/templates/addons/travel_core/components/order_booking_card.tpl'));

        foreach ([
            '$tobc.provider.name', '$tobc.reference', '$tobc.our_reference', '$tobc.alert.error', '$tobc.note',
            '$tobc.supplier_price', '$tobc.check_in.date', '$tobc.hotel.location', '$tobc.departure', '$tobc.package',
            '$tobc_room.name', '$tobc_room.code', '$tobc_room.board', '$tobc_guest.name', '$tobc_action.label',
        ] as $field) {
            preg_match_all('/\{' . preg_quote($field, '/') . '(\|[^}]*)?\}/', $card, $m);
            self::assertNotEmpty($m[0], $field);
            foreach ($m[1] as $pipes) {
                self::assertStringContainsString('escape:html', $pipes, $field);
            }
        }
        self::assertStringNotContainsString('<form', $card, 'the card sits inside the order form');
        self::assertStringContainsString('cm-post', $card);
    }

    public function testThePerProviderOrderBlocksAreGone(): void
    {
        foreach ([
            'addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/hooks/orders/product_info.post.tpl',
            'addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/hooks/orders/order_product_info.post.tpl',
            'addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/hooks/orders/product_info.post.tpl',
            'addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/hooks/orders/order_product_info.post.tpl',
            'eurosite_addon/design/backend/templates/addons/eurosite/hooks/orders/product_info.post.tpl',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/orders/product_info.post.tpl',
            'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/hooks/orders/product_info.post.tpl',
            'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/hooks/orders/product_info.post.tpl',
            // their separate terms boxes: the card shows the terms per line
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/hooks/orders/details.post.tpl',
            'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/hooks/orders/details.post.tpl',
            'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/hooks/orders/details.post.tpl',
        ] as $rel) {
            self::assertFileDoesNotExist(dirname(self::root()) . '/' . $rel, $rel);
        }
    }

    public function testTheCopyButtonsHaveTheirScript(): void
    {
        $scripts = self::read('design/backend/templates/addons/travel_core/hooks/index/scripts.post.tpl');
        $js = self::read('js/addons/travel_core/order-booking-card.js');
        $card = self::read('design/backend/templates/addons/travel_core/components/order_booking_card.tpl');

        self::assertStringContainsString('{script src="js/addons/travel_core/order-booking-card.js"}', $scripts);
        self::assertStringContainsString("'[data-ca-travel-copy]'", $js);
        self::assertStringContainsString('data-ca-travel-copied', $js);
        self::assertSame(2, substr_count($card, 'data-ca-travel-copy="'));
    }

    public function testCheckStatusChecksTheProvidersOwnBooking(): void
    {
        // REGRESSION: the unified booking page's "Check Status" handed the
        // travel_bookings id to the provider, which checked whichever of its
        // own bookings shared that number.
        $controller = self::read('app/addons/travel_core/controllers/backend/travel_bookings.php');

        self::assertStringContainsString("call_user_func(\$providerInfo['single_status_callback'], \$providerBookingId)", $controller);
        self::assertStringContainsString('$adminProvider->checkStatus((string) $providerBookingId)', $controller);
        self::assertStringNotContainsString("call_user_func(\$providerInfo['single_status_callback'], \$booking_id)", $controller);
    }
}
