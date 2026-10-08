<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * Keeps the request out of the cart-line keys the travel add-ons trust.
 *
 * Booking, deposit and balance lines carry their meaning in `extra`
 * (travel_booking_id, novoton_booking, sphinx_booking, travel_balance_id,
 * total_price, rooms_data, offer_id, parent_order_id …), and the order hooks
 * act on those keys. Every add-on writes such a line into the session cart
 * itself (novoton add_to_cart.php, sphinx fn_sphinx_holidays_write_cart_row,
 * eurosite add_to_cart.php, travel_balance.pay), never through
 * fn_add_product_to_cart(). That function is what a storefront
 * checkout.add?product_data[..][extra][..] reaches, so on the storefront its
 * pre_add_to_cart hook drops these keys from every line before core copies
 * `extra` into the cart.
 *
 * A server-side add that does build its own extra and goes through
 * fn_add_product_to_cart() wraps the call in trusted(): a flag in this
 * request's memory, which no request parameter can set.
 *
 * A cart update (fn_add_product_to_cart(…, $update = true), checkout.update)
 * re-posts lines already in the cart: there a protected key keeps the value
 * the cart already holds for that line, and the request's own is dropped.
 */
final class RequestExtraGuard
{
    /** @var list<string> */
    public const PREFIXES = ['travel_', 'novoton_', 'sphinx_', 'eurosite_'];

    /** @var list<string> */
    public const KEYS = ['total_price', 'rooms_data', 'offer_id', 'parent_order_id'];

    private static int $trusted = 0;

    /**
     * Run a server-side fn_add_product_to_cart() whose extra this add-on
     * built itself; the guard leaves it alone for the duration.
     *
     * @template T
     * @param callable(): T $add
     * @return T
     */
    public static function trusted(callable $add): mixed
    {
        self::$trusted++;
        try {
            return $add();
        } finally {
            self::$trusted--;
        }
    }

    public static function isTrusted(): bool
    {
        return self::$trusted > 0;
    }

    public static function isProtectedKey(string $key): bool
    {
        if (in_array($key, self::KEYS, true)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * pre_add_to_cart: strip the protected extra keys of every line of a
     * storefront add. Returns what was dropped ("<line>.<key>"), for the log.
     *
     * @param array<array-key, mixed> $productData fn_add_product_to_cart()'s $product_data
     * @param array<array-key, mixed> $cart
     * @param string $area CS-Cart AREA: only the customer area ('C') is filtered
     * @return list<string>
     */
    public static function strip(array &$productData, array $cart, bool $update, string $area): array
    {
        if ($area !== 'C' || self::isTrusted()) {
            return [];
        }
        $cartLines = is_array($cart['products'] ?? null) ? $cart['products'] : [];
        $removed = [];
        foreach ($productData as $lineKey => $line) {
            if (!is_array($line) || !is_array($line['extra'] ?? null)) {
                continue;
            }
            $existing = $cartLines[$lineKey] ?? null;
            $held = $update && is_array($existing) && is_array($existing['extra'] ?? null) ? $existing['extra'] : [];
            $extra = $line['extra'];
            foreach (array_keys($extra) as $key) {
                if (!is_string($key) || !self::isProtectedKey($key)) {
                    continue;
                }
                if (array_key_exists($key, $held)) {
                    $extra[$key] = $held[$key];
                } else {
                    unset($extra[$key]);
                    $removed[] = $lineKey . '.' . $key;
                }
            }
            $line['extra'] = $extra;
            $productData[$lineKey] = $line;
        }

        return $removed;
    }
}
