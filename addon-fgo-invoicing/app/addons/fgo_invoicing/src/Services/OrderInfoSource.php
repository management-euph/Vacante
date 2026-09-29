<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

/**
 * Where services get an order's $order_info from: CS-Cart's
 * fn_get_order_info(), as a Closure so the issuer, the mailer and the bulk
 * runner can be handed a fixed set of orders in tests instead.
 */
final class OrderInfoSource
{
    private function __construct()
    {
    }

    /**
     * Production source. Null for an order that does not exist (CS-Cart
     * returns false or an empty array) and on a partial bootstrap where the
     * core function is missing.
     *
     * @return \Closure(int): (array<string, mixed>|null)
     */
    public static function core(): \Closure
    {
        return static function (int $orderId): ?array {
            if ($orderId <= 0 || !function_exists('fn_get_order_info')) {
                return null;
            }
            $info = fn_get_order_info($orderId);
            if (!is_array($info) || $info === []) {
                return null;
            }
            /** @var array<string, mixed> $info */
            return $info;
        };
    }
}
