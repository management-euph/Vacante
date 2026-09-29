<?php

declare(strict_types=1);

/*
 * Test-only stand-in for CS-Cart's fn_get_order_info(), returning the orders a
 * test puts in $GLOBALS['fgo_test_orders'] (order_id => order_info).
 *
 * Load it ONLY from a #[RunInSeparateProcess] test: a global function cannot
 * be unloaded, and in-process it would change the path InvoiceIssuer takes in
 * every later test of the run.
 */

if (!function_exists('fn_get_order_info')) {
    /**
     * @return array<string, mixed>|false
     */
    function fn_get_order_info(int $orderId): array|false
    {
        $orders = $GLOBALS['fgo_test_orders'] ?? [];

        return is_array($orders) && isset($orders[$orderId]) && is_array($orders[$orderId]) ? $orders[$orderId] : false;
    }
}
