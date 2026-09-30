<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Reads the arguments of CS-Cart's 'place_order_post' hook in whichever order
 * the running core passes them.
 *
 * CS-Cart 4.20 (app/functions/fn.cart.php, fn_place_order) fires
 *
 *     fn_set_hook('place_order_post', $cart, $auth, $action, $issuer_id,
 *                 $parent_order_id, $order_id, $order_status,
 *                 $short_order_data, $notification_rules);
 *
 * with the cart FIRST and the order id SIXTH. The travel add-ons' handlers
 * were written against the argument order of the separate 'place_order' hook,
 *
 *     ($order_id, $action, $order_status, $cart, $auth)
 *
 * so on 4.20 they received the cart array as $order_id, read 0 and returned:
 * the supplier booking was never submitted nor linked to the order. The exact
 * core build of a store cannot be known from here, so every handler takes nine
 * optional by-reference slots and hands them to resolve(), which tells the two
 * orders apart by what sits in the first slot:
 *
 *  - an array keyed by name (products, user_data, ...) is a cart: 4.20 order;
 *  - an order id (int or numeric string), or a list of ids (Multi-Vendor
 *    passes the parent order followed by its vendor sub-orders): old order;
 *  - nothing usable (an empty cart, null): the second slot decides, because
 *    4.20 passes $auth (an array) there and the old order $action (a string).
 *
 * Pure and total: it never throws, and a call it cannot make sense of resolves
 * to order_id 0, which every handler treats as "nothing to do".
 */
final class PlaceOrderPostArgs
{
    /**
     * @param list<mixed> $args the hook's arguments, in the order they arrived
     * @return array{
     *     order_id: int,
     *     order_ids: list<int>,
     *     cart: array<string, mixed>|null,
     *     auth: array<string, mixed>,
     *     action: string,
     *     order_status: string
     * } order_id is the first positive id (the parent order on Multi-Vendor)
     *   and 0 when there is none; order_ids lists every positive id, parent
     *   first; cart is null when no cart array was passed (payment callbacks,
     *   order-status re-triggers).
     */
    public static function resolve(array $args): array
    {
        if (self::cartComesFirst($args)) {
            $orderIds = self::orderIds($args[5] ?? null);
            $cart = $args[0] ?? null;
            $auth = $args[1] ?? null;
            $action = $args[2] ?? null;
            $status = $args[6] ?? null;
        } else {
            $orderIds = self::orderIds($args[0] ?? null);
            $action = $args[1] ?? null;
            $status = $args[2] ?? null;
            $cart = $args[3] ?? null;
            $auth = $args[4] ?? null;
        }

        return [
            'order_id' => $orderIds[0] ?? 0,
            'order_ids' => $orderIds,
            'cart' => is_array($cart) ? TypeCoerce::toStringMap($cart) : null,
            'auth' => TypeCoerce::toStringMap($auth),
            'action' => TypeCoerce::toString($action),
            'order_status' => TypeCoerce::toString($status),
        ];
    }

    /**
     * Whether the arguments arrived in the 4.20 order (cart first).
     *
     * @param list<mixed> $args
     */
    private static function cartComesFirst(array $args): bool
    {
        $first = $args[0] ?? null;
        if (is_array($first) && $first !== []) {
            // A cart is keyed by name; the old order's Multi-Vendor id list
            // is keyed 0, 1, 2, ... A single string key is enough to tell.
            foreach (array_keys($first) as $key) {
                if (is_string($key)) {
                    return true;
                }
            }

            return false;
        }
        if (is_int($first) || is_float($first) || (is_string($first) && is_numeric($first))) {
            return false;
        }

        // Nothing usable first (an empty cart, null): 4.20 passes $auth, an
        // array, second; the old order passes $action, a string.
        return is_array($args[1] ?? null);
    }

    /**
     * Every positive order id in a hook's order-id argument: one id, or a
     * list of them. Anything else (a cart, null, garbage) yields none.
     *
     * @return list<int>
     */
    private static function orderIds(mixed $value): array
    {
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $candidate) {
            $id = TypeCoerce::toInt($candidate);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
