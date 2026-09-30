<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Hooks;

use Tygh\Addons\SphinxHolidays\Services\BookingPayloadFactory;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Order-lifecycle hook bodies for sphinx_holidays.
 *
 * func.php keeps the fn_sphinx_holidays_* shells CS-Cart's hook dispatch
 * calls by name and delegates here — the extraction that took func.php off
 * the god-file list. Behavior contracts (pinned by OrderLinkSelfHealTest):
 * pre_place_order removes unavailable offers (deleting their stranded
 * booking rows) instead of blocking mixed-provider orders; place_order_post
 * self-heals booking–order links on BOTH its paths.
 */
final class OrderHooks
{
    /**
     * Hook body: pre_place_order — re-verify sphinx offer prices before the
     * order is placed.
     *
     * If a sphinx offer is no longer available, the item is removed from the
     * cart instead of blocking the entire order, so mixed-provider orders
     * (novoton + sphinx) proceed with the available items. The order is only
     * blocked if the cart would become empty, or a price correction exceeded
     * the absorb allowance (EU CRD: the amount charged must be the amount
     * shown at the order button).
     *
     * @param mixed $cart Cart array (by ref — items may be removed, prices corrected)
     * @param mixed $allow Set to false to block order placement
     */
    public static function prePlaceOrder(mixed &$cart, mixed &$allow): void
    {
        if (!is_array($cart)) {
            return;
        }

        $verifier = Container::getPreOrderPriceVerifier();
        $result = $verifier->verify(TypeCoerce::toStringMap($cart));

        // Mutations happen on a typed copy of the products map and are
        // written back wholesale — same end state as in-place edits, but
        // without offset-writes through the mixed by-ref cart.
        $products = is_array($cart['products'] ?? null) ? $cart['products'] : [];

        // Remove unavailable Sphinx offers from cart instead of blocking the entire order
        $unavailable = $result['unavailable'];
        if ($unavailable !== []) {
            foreach ($unavailable as $cartId => $info) {
                $hotelName = TypeCoerce::toString(!empty($info['hotel_name']) ? $info['hotel_name'] : ($info['offer_id'] ?? ''));

                fn_set_notification(
                    'W',
                    __('warning'),
                    __('sphinx_holidays.offer_removed_from_order', [
                        '[hotel]' => $hotelName,
                        '[default]' => 'The Sphinx offer for "' . $hotelName . '" is no longer available and has been removed from your order.',
                    ]),
                );

                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx pre_place_order: removed unavailable item from cart',
                    'cart_id' => $cartId,
                    'hotel_name' => $hotelName,
                ]);

                // The pending booking row created at add-to-cart would otherwise
                // be stranded forever (order_id=0, unreachable by the order-link
                // reconcilers since the item leaves the order) and show as a
                // permanent "Order ID -" row in the admin grid. delete() clears
                // both ?:sphinx_bookings and the shared mirror.
                $removedProduct = $products[$cartId] ?? null;
                $removedExtra = is_array($removedProduct) ? ($removedProduct['extra'] ?? null) : null;
                $strandedBookingId = is_array($removedExtra) ? TypeCoerce::toInt($removedExtra['travel_booking_id'] ?? 0) : 0;
                if ($strandedBookingId > 0) {
                    Container::getBookingRepository()->delete($strandedBookingId);
                }

                unset($products[$cartId]);
            }

            $cart['products'] = $products;

            // If the cart is now empty (all items were Sphinx and all unavailable), block the order
            if ($products === []) {
                fn_set_notification(
                    'E',
                    __('error'),
                    __('sphinx_holidays.all_offers_unavailable', [
                        '[default]' => 'All hotel offers in your cart are no longer available. Please search again.',
                    ]),
                );
                $allow = false;
                return;
            }
        }

        $corrections = $result['corrections'];
        if ($corrections !== []) {
            foreach ($corrections as $cartId => $correction) {
                $line = is_array($products[$cartId] ?? null) ? $products[$cartId] : null;
                if ($line === null) {
                    continue;
                }
                $newPrice = TypeCoerce::toFloat($correction['api_price'] ?? 0);
                $lineExtra = is_array($line['extra'] ?? null) ? $line['extra'] : [];
                // Keep the old price for "Old vs New" display before overwriting —
                // the old FULL price, in the same (API) currency as total_price
                // (the line price is converted, and only the deposit on a deposit line).
                $lineExtra['price_before_correction'] = $lineExtra['total_price'] ?? $line['price'] ?? null;
                $lineExtra['total_price'] = $newPrice;
                $line['extra'] = $lineExtra;
                // extra.total_price is in the API currency; the line is in
                // the store's primary currency (CartService converts the same
                // way). A deposit line keeps charging its deposit, rescaled.
                $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';
                $apiCurrency = TypeCoerce::toString($lineExtra['currency'] ?? '');
                $currencyService = new \Tygh\Addons\TravelCore\Services\CurrencyService($apiCurrency !== '' ? $apiCurrency : 'EUR');
                $products[$cartId] = DepositCartLine::reprice(
                    TypeCoerce::toStringMap($line),
                    $currencyService->convertFromApiCurrency($newPrice, $primaryCurrency),
                );
            }
            $cart['products'] = $products;
        }

        // A correction exceeded the absorb allowance: the cart now shows the new
        // price — block this click so the customer re-confirms the updated total
        // (same policy as novoton; EU CRD: the amount charged must be the amount
        // shown at the order button).
        if (!empty($result['reconfirm'])) {
            fn_set_notification(
                'W',
                __('travel_core.price_change', ['[default]' => 'Price update']),
                __('travel_core.price_changed_reconfirm', [
                    '[default]' => 'The price of a booking in your cart has changed. Please review the updated total and place your order again.',
                ]),
            );
            $allow = false;
        }
    }

    /**
     * Hook body: place_order_post — after an order is placed, submit the
     * booking to the Sphinx API.
     *
     * Status flow: pending → (API call) → confirmed on success, failed on
     * error. On API failure the booking is marked STATUS_FAILED and logged.
     *
     * @param mixed $order_id Order id (Multi-Vendor passes an array of parent + child ids)
     * @param mixed $cart Cart array; null/empty on payment callbacks and re-triggers
     */
    public static function placeOrderPost(mixed $order_id, mixed $cart): void
    {
        // CS-Cart Multi-Vendor passes $order_id as array (parent + child order IDs).
        // Normalize to the parent (first) order ID for booking submission.
        $resolved_order_id = TypeCoerce::toInt(is_array($order_id) ? reset($order_id) : $order_id);

        if (empty($resolved_order_id)) {
            return;
        }

        // Fallback path: $cart is null/empty (payment callbacks, order-status
        // re-triggers) — nothing to submit, but the bookings referenced by the
        // order's items may still be unlinked; link them from the stored order
        // (novoton parity: its place_order_post handles this the same way).
        $cartArr = is_array($cart) ? $cart : [];
        $cartProducts = is_array($cartArr['products'] ?? null) ? $cartArr['products'] : [];
        if ($cartProducts === []) {
            self::linkOrderBookings($resolved_order_id);
            return;
        }

        $repo = Container::getBookingRepository();

        // Booking contact comes from the ORDER's checkout profile — the guest form
        // no longer duplicates the email/phone questions. Legacy cart extras
        // (contact_email/contact_phone, from forms submitted before the removal)
        // remain as a fallback for in-flight carts only.
        $orderContact = BookingPayloadFactory::contactFromUserData(
            TypeCoerce::toStringMap($cartArr['user_data'] ?? []),
        );

        foreach ($cartProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
            if (empty($extra['sphinx_booking']) || empty($extra['travel_booking_id'])) {
                continue;
            }

            $booking_id = TypeCoerce::toInt($extra['travel_booking_id']);
            $offer_id = TypeCoerce::toString($extra['offer_id'] ?? '');

            // CS-Cart runs place_order_post again for the same cart (order
            // edits, the parent after its vendor sub-orders). A booking already
            // linked to an order was submitted then: resending would book it
            // twice and relinking would reset its confirmed status to pending.
            $existing = $repo->findById($booking_id);
            if (TypeCoerce::toInt($existing['order_id'] ?? 0) > 0) {
                continue;
            }

            $contact = [
                'email' => $orderContact['email'] !== ''
                    ? $orderContact['email']
                    : TypeCoerce::toString($extra['contact_email'] ?? ''),
                'phone' => $orderContact['phone'] !== ''
                    ? $orderContact['phone']
                    : TypeCoerce::toString($extra['contact_phone'] ?? ''),
            ];

            // Link booking to order with PENDING status (not confirmed yet — API call hasn't happened)
            $repo->linkToOrder($booking_id, $resolved_order_id, TravelConstants::STATUS_PENDING);

            // Backfill the booking row so admin views and the retry path see the
            // same contact the API is given (add_to_cart stores it empty now).
            if ($contact['email'] !== '' || $contact['phone'] !== '') {
                $repo->update($booking_id, [
                    'guest_email' => $contact['email'],
                    'guest_phone' => $contact['phone'],
                ]);
            }

            // Submit booking to Sphinx API
            if (!empty($offer_id)) {
                try {
                    $api = Container::getApi();
                    $guests_data = [];
                    if (!empty($extra['guests_data'])) {
                        $guests_data = is_string($extra['guests_data'])
                            ? json_decode($extra['guests_data'], true)
                            : $extra['guests_data'];
                    }

                    $payload = BookingPayloadFactory::build(
                        $offer_id,
                        TypeCoerce::toStringMap($guests_data),
                        $contact,
                        $resolved_order_id,
                    );

                    // Dispatch by booking type — packages/circuits/experiences have
                    // their own book endpoints (previously EVERY sphinx product was
                    // sent to bookHotel, so non-hotel bookings hit the wrong one).
                    $booking_type = TypeCoerce::toString($extra['booking_type'] ?? 'hotel');
                    $bookResult = match ($booking_type) {
                        'circuit' => $api->bookCircuit($payload),
                        'package' => $api->bookPackage($payload),
                        'experience' => $api->bookExperience($payload),
                        default => $api->bookHotel($payload),
                    };

                    // The documented book response carries the voucher code as
                    // booking_confirmation_number (order_id/contract_id/
                    // reference_code/status alongside) — there is NO
                    // booking_reference field. Reading the wrong key meant
                    // api_booking_ref was never stored and the admin grid's
                    // confirmation column stayed "-" for every sphinx booking.
                    $confirmationNumber = TypeCoerce::toString(
                        $bookResult['booking_confirmation_number'] ?? $bookResult['booking_reference'] ?? '',
                    );
                    if ($confirmationNumber !== '') {
                        $repo->updateApiResponse(
                            $booking_id,
                            $confirmationNumber,
                            (string) json_encode($bookResult),
                        );
                    } else {
                        fn_log_event('general', 'runtime', [
                            'message' => 'Sphinx: booking confirmed but no confirmation number returned',
                            'booking_id' => $booking_id,
                            'order_id' => $resolved_order_id,
                        ]);
                    }

                    // API call succeeded — now set confirmed status
                    $repo->update($booking_id, [
                        'status' => TravelConstants::STATUS_CONFIRMED,
                    ]);
                } catch (\Throwable $e) {
                    // Mark booking as failed in both sphinx_bookings and travel_bookings
                    $repo->update($booking_id, [
                        'status' => TravelConstants::STATUS_FAILED,
                    ]);

                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx book API call failed: ' . $e->getMessage(),
                        'booking_id' => $booking_id,
                        'order_id' => $resolved_order_id,
                    ]);
                }
            }
        }

        // Self-heal: a cart item skipped by the guard above (missing
        // sphinx_booking/travel_booking_id extras, pre-persist throw) would leave
        // its booking orphaned forever — the admin grid then shows "Order ID: -"
        // although the order exists. The reconciler is idempotent and cheap, so
        // always run it after submission (novoton parity).
        self::linkOrderBookings($resolved_order_id);
    }

    /**
     * Hook body: calculate_cart_items — preserve the stored price for sphinx
     * booking lines (CS-Cart recalculates from product data; a booking's
     * price is the verified offer price, not the catalog price).
     *
     * @param mixed $cart Cart array (by ref — prices rewritten in place)
     */
    public static function preserveStoredPrices(mixed &$cart): void
    {
        if (!is_array($cart) || empty($cart['products']) || !is_array($cart['products'])) {
            return;
        }

        // Mutate a typed copy and write it back wholesale — same end state
        // as a by-ref foreach, without offset-writes through the mixed cart.
        $products = $cart['products'];
        $changed = false;
        foreach ($products as $cartId => $product) {
            if (!is_array($product)) {
                continue;
            }
            $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
            if (empty($extra['sphinx_booking']) || empty($product['stored_price'])) {
                continue;
            }
            $product['price'] = $product['base_price'] ?? $product['price'];
            $products[$cartId] = $product;
            $changed = true;
        }
        if ($changed) {
            $cart['products'] = $products;
        }
    }

    /**
     * Link any unlinked sphinx bookings referenced by an order's items to that
     * order. Idempotent: bookings already linked are left untouched; status is
     * NOT changed (a reconciliation must not reset a confirmed booking).
     *
     * @return int Number of bookings newly linked
     */
    public static function linkOrderBookings(int $order_id): int
    {
        if ($order_id <= 0) {
            return 0;
        }

        $order_info = fn_get_order_info($order_id);
        /** @var array<string, mixed> $order_info */
        $order_info = is_array($order_info) ? $order_info : [];
        $oiProducts = is_array($order_info['products'] ?? null) ? $order_info['products'] : [];
        if (empty($oiProducts)) {
            return 0;
        }

        $repo = Container::getBookingRepository();
        $linked = 0;

        foreach ($oiProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            /** @var array<string, mixed> $extra */
            $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
            if (empty($extra['sphinx_booking']) || empty($extra['travel_booking_id'])) {
                continue;
            }

            $booking_id = TypeCoerce::toInt($extra['travel_booking_id']);
            if ($booking_id <= 0) {
                continue;
            }

            $current_order = TypeCoerce::toInt(db_get_field(
                'SELECT order_id FROM ?:sphinx_bookings WHERE booking_id = ?i',
                $booking_id,
            ));

            // Already linked to a DIFFERENT order — never steal it.
            if ($current_order > 0 && $current_order !== $order_id) {
                continue;
            }

            // Write when the booking is unlinked. Also write when it is already
            // linked to THIS order but the shared travel_bookings mirror drifted
            // to a different value (typically 0) — that drift is what shows
            // "Order ID: -" in the admin grid for an already-linked booking.
            // linkToOrder() -> update() re-mirrors order_id into travel_bookings.
            $needs_write = $current_order <= 0;
            if (!$needs_write) {
                $mirror_order = TypeCoerce::toInt(db_get_field(
                    "SELECT order_id FROM ?:travel_bookings WHERE provider = 'sphinx' AND provider_booking_id = ?s",
                    (string) $booking_id,
                ));
                $needs_write = $mirror_order !== $order_id;
            }

            if ($needs_write) {
                $repo->linkToOrder($booking_id, $order_id);
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * Hook body: get_order_info — admin-panel decoration for orders that
     * contain sphinx bookings.
     *
     * Resolves the unified travel_bookings surrogate id for each sphinx
     * booking product (the per-line "View Booking" link must not mix the
     * sphinx_bookings PK into travel_bookings' id-space) and shows a warning
     * notification when the order carries a failed sphinx booking.
     *
     * @param mixed $order Order info array (by ref — product extras are decorated)
     */
    public static function orderInfoLoaded(mixed &$order): void
    {
        // Admin only: the per-line "View Booking" link resolved below and the
        // failed-booking notification are both admin-panel concerns.
        if (!defined('AREA') || AREA !== 'A' || !is_array($order) || empty($order['order_id'])) {
            return;
        }

        if (!empty($order['products']) && is_array($order['products'])) {
            foreach ($order['products'] as &$sxProduct) {
                if (!is_array($sxProduct)) {
                    continue;
                }
                $sxExtra = is_array($sxProduct['extra'] ?? null) ? $sxProduct['extra'] : [];
                if (empty($sxExtra['sphinx_booking']) || empty($sxExtra['travel_booking_id'])) {
                    continue;
                }
                $sxExtra['travel_surrogate_id'] = TypeCoerce::toInt(db_get_field(
                    "SELECT booking_id FROM ?:travel_bookings WHERE provider = 'sphinx' AND provider_booking_id = ?s",
                    TypeCoerce::toString($sxExtra['travel_booking_id']),
                ));
                $sxProduct['extra'] = $sxExtra;
            }
            unset($sxProduct);
        }

        $repo = Container::getBookingRepository();
        $bookings = $repo->findByOrderId(TypeCoerce::toInt($order['order_id']));

        foreach ($bookings as $booking) {
            if (($booking['status'] ?? '') === TravelConstants::STATUS_FAILED) {
                $hotelName = TypeCoerce::toString($booking['hotel_name'] ?? '');
                fn_set_notification(
                    'W',
                    __('warning'),
                    __('sphinx_holidays.booking_api_failed', [
                        '[hotel]' => $hotelName,
                        '[order_id]' => $order['order_id'],
                        '[default]' => 'Sphinx booking failed for hotel "' . $hotelName . '" in order #'
                            . TypeCoerce::toString($order['order_id']) . '. Please verify and resubmit.',
                    ]),
                );
                break; // One notification per order is enough
            }
        }
    }
}
