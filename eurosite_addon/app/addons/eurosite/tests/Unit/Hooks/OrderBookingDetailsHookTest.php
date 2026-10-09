<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/func.php';
}

namespace Tygh\Addons\Eurosite\Tests\Unit\Hooks {

    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\TestCase;

    /**
     * REGRESSION: eurosite lines showed no booking details on the order
     * pages. Novoton and sphinx printed their own text block from a hook
     * template gated on their own flag; eurosite had neither the template
     * nor a get_order_info hook, so its lines showed the SKU and nothing else.
     *
     * Every provider's line now gets travel_core's shared booking card
     * (hooks/orders/product_info.post.tpl, admin and storefront). For a
     * eurosite line it needs what eurosite registers: the stay fields its cart
     * line never stored (get_order_info), and what the booking row knows —
     * reference, failure, terms (the order-card and cart-terms resolvers).
     * Hook and resolver wiring is invisible to PHPStan, so it is pinned here.
     */
    #[CoversNothing]
    final class OrderBookingDetailsHookTest extends TestCase
    {
        private const string ADDON_ROOT = __DIR__ . '/../../..';

        public function testTheOrderPagesUseTheSharedCardNotAnEurositeBlock(): void
        {
            $design = self::ADDON_ROOT . '/../../../design';
            $core = self::ADDON_ROOT . '/../../../../addon-travel-core/design';

            self::assertFileDoesNotExist($design . '/backend/templates/addons/eurosite/hooks/orders/product_info.post.tpl');
            self::assertFileExists($core . '/backend/templates/addons/travel_core/hooks/orders/product_info.post.tpl');
            self::assertFileExists($core . '/themes/responsive/templates/addons/travel_core/hooks/orders/product_info.post.tpl');
        }

        public function testInitRegistersTheOrderInfoHookAndTheCardResolvers(): void
        {
            $init = (string) file_get_contents(self::ADDON_ROOT . '/init.php');

            self::assertMatchesRegularExpression("/fn_register_hooks\\([^)]*'get_order_info'/s", $init);
            self::assertMatchesRegularExpression("/setOrderCardResolver\\(\\s*'eurosite',\\s*static fn \\(array \\\$extra\\): array => \\(new \\\\Tygh\\\\Addons\\\\Eurosite\\\\Services\\\\OrderCardFacts\\(\\)\\)->facts\\(\\\$extra\\)/", $init);
            self::assertMatchesRegularExpression("/setCartTermsResolver\\(\\s*'eurosite',\\s*static fn \\(array \\\$extra\\): array => \\(new \\\\Tygh\\\\Addons\\\\Eurosite\\\\Services\\\\OrderCardFacts\\(\\)\\)->terms\\(\\\$extra\\)/", $init);
            self::assertTrue(function_exists('fn_eurosite_get_order_info'));
        }

        public function testNewLinesKeepThePaymentTermsTheCustomerSaw(): void
        {
            // The order shows the terms agreed to, not today's setting.
            $addToCart = (string) file_get_contents(self::ADDON_ROOT . '/controllers/frontend/eurosite_booking/add_to_cart.php');

            self::assertStringContainsString("'payment_terms'       => array_values(array_filter(", $addToCart);
            self::assertStringContainsString('ConfigProvider::getPaymentTermsText()', $addToCart);
        }

        public function testTheHookNeverThrows(): void
        {
            // It also runs inside the customer's "Place order" request
            // (place_order_post reads the order back): a failure is logged and
            // the order is returned as it came.
            $GLOBALS['eurosite_logged_events'] = [];
            $order = ['order_id' => 12, 'products' => [['extra' => ['travel_booking' => true, 'eurosite_booking_id' => 41]]]];
            $copy = $order;

            fn_eurosite_get_order_info($order, []);

            self::assertSame($copy, $order);
            self::assertIsArray($GLOBALS['eurosite_logged_events']);
            self::assertCount(1, $GLOBALS['eurosite_logged_events']);
            self::assertStringContainsString('Eurosite get_order_info', (string) json_encode($GLOBALS['eurosite_logged_events'][0]));
        }

        public function testAnOrderWithoutEurositeLinesReadsNothing(): void
        {
            $GLOBALS['eurosite_logged_events'] = [];
            $order = ['order_id' => 12, 'products' => [['extra' => ['travel_booking' => true, 'novoton_booking' => true]]]];
            $copy = $order;

            fn_eurosite_get_order_info($order, []);

            self::assertSame($copy, $order);
            self::assertSame([], $GLOBALS['eurosite_logged_events']);
        }

        public function testANonArrayOrderIsLeftAlone(): void
        {
            $order = false;

            fn_eurosite_get_order_info($order, []);

            self::assertFalse($order);
        }
    }
}
