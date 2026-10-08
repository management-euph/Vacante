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
     * REGRESSION: eurosite lines showed no booking details on the admin order
     * page. Novoton and sphinx render theirs from an orders:product_info hook
     * template gated on their own flag; eurosite shipped no such template and
     * no get_order_info hook, so its lines showed the SKU and nothing else.
     *
     * Templates and hook registration are invisible to PHPStan, so the wiring
     * is pinned here.
     */
    #[CoversNothing]
    final class OrderBookingDetailsHookTest extends TestCase
    {
        private const string ADDON_ROOT = __DIR__ . '/../../..';
        private const string TEMPLATE = '/../../../design/backend/templates/addons/eurosite/hooks/orders/product_info.post.tpl';

        private static function template(): string
        {
            $path = self::ADDON_ROOT . self::TEMPLATE;
            self::assertFileExists($path, 'the admin order page needs the eurosite product_info hook template');

            return (string) file_get_contents($path);
        }

        /** The template without its {* … *} comment. */
        private static function code(): string
        {
            return (string) preg_replace('/\{\*.*?\*\}/s', '', self::template());
        }

        public function testTheBlockRendersForEveryEurositeLine(): void
        {
            // Orders placed so far carry eurosite_booking_id and no provider
            // flag: gating on a new flag would leave them blank.
            self::assertMatchesRegularExpression('/\{if !empty\(\$oi\.extra\.eurosite_booking_id\)\}/', self::code());
        }

        public function testItDrawsTheSharedTravelCoreBlock(): void
        {
            $code = self::code();

            self::assertStringContainsString('{include file="addons/travel_core/components/order_booking_details.tpl"', $code);
            self::assertStringContainsString('booking_extra=$oi.extra', $code);
            self::assertStringContainsString('show_terms=true', $code);
            self::assertStringContainsString('booking_ref_line=$oi.extra.eurosite_ref_line', $code);
        }

        public function testTheViewBookingLinkUsesTheUnifiedIdOnly(): void
        {
            $code = self::code();

            self::assertStringContainsString('booking_view_id=$oi.extra.travel_surrogate_id|default:0', $code);
            // extra.booking_id / extra.eurosite_booking_id are eurosite_bookings
            // ids: in a travel_bookings link they open someone else's booking.
            self::assertDoesNotMatchRegularExpression('/booking_view_id=\$oi\.extra\.(?:eurosite_)?booking_id/', $code);
            self::assertStringNotContainsString('travel_bookings.view', $code);
        }

        public function testNoRoomsBreakdown(): void
        {
            // Eurosite rooms carry no meal or price: the breakdown would print
            // an empty column and "0 RON" for every room.
            self::assertStringNotContainsString('show_rooms_breakdown', self::code());
        }

        public function testCoreSmartyOnly(): void
        {
            // A compile error aborts the admin {capture name="mainbox"}.
            $code = self::code();
            preg_match_all('/\|([a-z_]+)/', $code, $m);

            self::assertSame([], array_diff(array_unique($m[1]), ['default', 'escape']));
            self::assertStringNotContainsString('json_decode(', $code);
            self::assertStringNotContainsString('{capture', $code);
        }

        public function testInitRegistersTheOrderInfoHook(): void
        {
            $init = (string) file_get_contents(self::ADDON_ROOT . '/init.php');

            self::assertMatchesRegularExpression("/fn_register_hooks\\([^)]*'get_order_info'/s", $init);
            self::assertTrue(function_exists('fn_eurosite_get_order_info'));
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

        public function testANonArrayOrderIsLeftAlone(): void
        {
            $order = false;

            fn_eurosite_get_order_info($order, []);

            self::assertFalse($order);
        }
    }
}
