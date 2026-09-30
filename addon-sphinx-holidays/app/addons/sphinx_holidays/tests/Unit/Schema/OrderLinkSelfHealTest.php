<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the order-link self-heal in the place_order_post hook body
 * (Hooks\OrderHooks — func.php keeps the fn_* shell CS-Cart dispatches to):
 * the admin grid's Order ID must appear WITHOUT the manual "Reconcile
 * booking–order links" tool. That requires the idempotent reconciler
 * linkOrderBookings() to run on BOTH hook paths —
 *  - after the submission loop (items skipped by the per-item guard would
 *    otherwise stay orphaned forever), and
 *  - as the whole body of the empty-cart fallback (payment callbacks and
 *    order-status re-triggers fire place_order_post without a cart).
 * Regression history: sphinx bookings kept showing "Order ID: -" until the
 * operator pressed reconcile.
 */
final class OrderLinkSelfHealTest extends TestCase
{
    private static function orderHooks(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/Hooks/OrderHooks.php');
    }

    public function testPlaceOrderPostSelfHealsOnBothPaths(): void
    {
        $source = self::orderHooks();

        $start = strpos($source, 'public static function placeOrderPost');
        self::assertNotFalse($start, 'placeOrderPost hook body exists');
        $end = strpos($source, 'public static function linkOrderBookings', $start);
        self::assertNotFalse($end, 'reconciler helper exists after the hook body');
        $hookBody = substr($source, $start, $end - $start);

        self::assertSame(
            2,
            substr_count($hookBody, 'self::linkOrderBookings($resolved_order_id)'),
            'place_order_post must self-heal on both paths: the empty-cart '
                . 'fallback AND after the submission loop (novoton parity — '
                . 'without these, the grid shows "Order ID: -" until manual reconcile)',
        );

        // The empty-cart fallback must stay a separate early path — a
        // combined guard would swallow callback invocations entirely.
        self::assertStringNotContainsString(
            'empty($resolved_order_id) ||',
            $hookBody,
            'the guards must stay split so empty-cart invocations still link',
        );

        // func.php's shell must still dispatch here — CS-Cart calls the fn_*
        // name; a body quietly restored into func.php would fork the logic.
        $shells = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        self::assertStringContainsString('OrderHooks::placeOrderPost($order_id, $cart)', $shells);
        self::assertStringContainsString('OrderHooks::linkOrderBookings($order_id)', $shells);
        self::assertStringContainsString('OrderHooks::prePlaceOrder($cart, $allow)', $shells);
        self::assertStringContainsString('OrderHooks::orderInfoLoaded($order)', $shells);
    }

    public function testABookingAlreadyLinkedToAnOrderIsNotSubmittedAgain(): void
    {
        // place_order_post fires again for the same cart (order edits, the
        // parent after its vendor sub-orders): the guard must skip a linked
        // booking BEFORE it is relinked as pending and re-sent to the API.
        $source = self::orderHooks();
        $start = strpos($source, 'public static function placeOrderPost');
        self::assertNotFalse($start);
        $body = substr($source, $start);

        $guard = strpos($body, "TypeCoerce::toInt(\$existing['order_id'] ?? 0) > 0");
        $relink = strpos($body, '$repo->linkToOrder($booking_id, $resolved_order_id, TravelConstants::STATUS_PENDING)');
        $submit = strpos($body, '$api->bookHotel($payload)');
        self::assertNotFalse($guard, 'the already-linked guard exists');
        self::assertNotFalse($relink);
        self::assertNotFalse($submit);
        self::assertLessThan($relink, $guard, 'the guard runs before the pending relink');
        self::assertLessThan($submit, $guard, 'the guard runs before the API submission');
    }

    public function testPrePlaceOrderDeletesTheBookingOfARemovedUnavailableOffer(): void
    {
        // When pre_place_order drops an unavailable offer from the cart, its
        // add-to-cart booking row leaves the order forever — no reconciler
        // can ever link it, so it would sit in the admin grid as a permanent
        // "Order ID -" row. The hook must delete it (repo delete() clears
        // ?:sphinx_bookings AND the shared ?:travel_bookings mirror).
        $source = self::orderHooks();

        $start = strpos($source, 'public static function prePlaceOrder');
        self::assertNotFalse($start, 'prePlaceOrder hook body exists');
        $end = strpos($source, 'unset($products[$cartId]);', $start);
        self::assertNotFalse($end, 'the unavailable-offer removal exists');
        $beforeRemoval = substr($source, $start, $end - $start);

        self::assertStringContainsString("['travel_booking_id']", $beforeRemoval);
        self::assertStringContainsString('getBookingRepository()->delete(', $beforeRemoval);
    }

    public function testCleanupPurgesTheSharedMirrorViaTheRepository(): void
    {
        // The inline DELETE only purged ?:sphinx_bookings and left
        // mirror-only "Order ID -" rows in the unified grid forever;
        // deleteOrphans() removes both.
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Cron/Commands/CleanupCommand.php',
        );

        self::assertStringContainsString('getBookingRepository()', $source);
        self::assertStringContainsString('->deleteOrphans(48)', $source);
        self::assertStringNotContainsString(
            'DELETE FROM ?:sphinx_bookings WHERE order_id = 0',
            $source,
            'orphan cleanup must go through the repository so the travel_bookings mirror is purged too',
        );
    }
}
