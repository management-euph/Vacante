<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Hooks;

use Tygh\Addons\SphinxHolidays\Services\BookingPayloadFactory;
use Tygh\Addons\SphinxHolidays\Services\CheckoutBookingBinder;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
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
 *
 * A Sphinx line is a booking only when it is bound to its booking row
 * (CheckoutBookingBinder, audit H2): pre_place_order removes any other line
 * and blocks the order, and place_order_post books only a bound row's own
 * offer_id.
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
     * A line not bound to its booking row (the verifier's "rejected") is
     * removed too, but its row is NEVER deleted — the line may name another
     * visitor's booking — and the order is blocked so the customer sees the
     * changed cart.
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
                // both ?:sphinx_bookings and the shared mirror. Only such a
                // row: one on an order is a live booking, never deleted here.
                $removedProduct = $products[$cartId] ?? null;
                $removedExtra = is_array($removedProduct) ? ($removedProduct['extra'] ?? null) : null;
                $strandedBookingId = is_array($removedExtra) ? TypeCoerce::toInt($removedExtra['travel_booking_id'] ?? 0) : 0;
                if ($strandedBookingId > 0 && self::isStranded(Container::getBookingRepository()->findById($strandedBookingId))) {
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

        // Lines not bound to their booking row (forged or stale extras, a
        // booking that is not this visitor's, a line that cannot be checked):
        // dropped from the cart, the row left alone, the order blocked. Not
        // the "no longer available" text — the offer may well be: it is the
        // booking that could not be confirmed, and the customer books it again.
        $rejected = $result['rejected'];
        foreach ($rejected as $cartId => $info) {
            $hotelName = TypeCoerce::toString(!empty($info['hotel_name']) ? $info['hotel_name'] : ($info['offer_id'] ?? ''));
            fn_set_notification(
                'W',
                __('warning'),
                __('sphinx_holidays.booking_not_bound', [
                    '[hotel]' => $hotelName,
                    '[default]' => 'Your booking for "' . $hotelName . '" could not be confirmed and was removed from the cart. Please book it again.',
                ]),
            );
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx pre_place_order: removed a line not bound to its booking, order blocked',
                'cart_id' => $cartId,
                'booking_id' => $info['booking_id'] ?? 0,
                'reason' => $info['reason'] ?? '',
            ]);
        }
        if ($rejected !== []) {
            $products = array_diff_key($products, $rejected);
            $cart['products'] = $products;
            $allow = false;
            if ($products === []) {
                fn_set_notification(
                    'E',
                    __('error'),
                    __('sphinx_holidays.all_offers_unavailable', [
                        '[default]' => 'All hotel offers in your cart are no longer available. Please search again.',
                    ]),
                );
            }

            return;
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
                // A deposit plan no longer offered: the line pays in full.
                if (!empty($correction['full_payment'])) {
                    $line = CheckoutBookingBinder::withoutDeposit(TypeCoerce::toStringMap($line));
                }
                // extra.total_price is in the API currency; the line is in
                // the store's primary currency (CartService converts the same
                // way). A deposit line keeps charging its deposit, rescaled.
                // The booking row's currency (the verifier sends it) over the
                // line's copy, which the request could have written.
                $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';
                $apiCurrency = TypeCoerce::toString($correction['currency'] ?? $lineExtra['currency'] ?? '');
                $currencyService = new \Tygh\Addons\TravelCore\Services\CurrencyService($apiCurrency !== '' ? $apiCurrency : 'EUR');
                // One booking line books one reservation.
                $line['amount'] = 1;
                $products[$cartId] = DepositCartLine::reprice(
                    TypeCoerce::toStringMap($line),
                    $currencyService->convertFromApiCurrency($newPrice, $primaryCurrency),
                );

                // A live-price correction: the row is what the line must
                // charge from now on (the binding check reads it), so it takes
                // the corrected price too. A correction TO the row's price
                // leaves the row as it is.
                $correctedBookingId = TypeCoerce::toInt($correction['booking_id'] ?? 0);
                if ($correctedBookingId > 0 && !empty($correction['update_row'])) {
                    $rowPrices = ['total_price' => $newPrice];
                    $newRaw = TypeCoerce::toFloat($correction['api_price_raw'] ?? 0);
                    if ($newRaw > 0) {
                        $rowPrices['base_price'] = $newRaw;
                    }
                    Container::getBookingRepository()->update($correctedBookingId, $rowPrices);
                }
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
        $binder = CheckoutBookingBinder::fromSession();
        /** @var list<int> $refused rows the cart named but may not book: the reconciler must not link them either */
        $refused = [];

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

            // A line names a booking; it is booked only when that row exists
            // and is this visitor's, for the line's offer (audit H2: with a
            // forged id, findById() is null, order_id read 0 and extra's
            // offer was booked at order creation, before any payment).
            $existing = $repo->findById($booking_id);
            if ($existing === null) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx place_order_post: line names no booking row, nothing booked',
                    'booking_id' => $booking_id,
                    'order_id' => $resolved_order_id,
                ]);
                continue;
            }

            // CS-Cart runs place_order_post again for the same cart (order
            // edits, the parent after its vendor sub-orders). A booking already
            // linked to an order was submitted then: resending would book it
            // twice and relinking would reset its confirmed status to pending.
            if (TypeCoerce::toInt($existing['order_id'] ?? 0) > 0) {
                continue;
            }

            $refusal = $binder->rejection(TypeCoerce::toStringMap($product), $existing);
            if ($refusal !== null) {
                $refused[] = $booking_id;
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx place_order_post: line not bound to its booking, nothing booked',
                    'booking_id' => $booking_id,
                    'order_id' => $resolved_order_id,
                    'reason' => $refusal,
                ]);
                continue;
            }

            // The row's own offer, travellers and booking type — never the
            // line's copies, which the request could have written.
            $offer_id = TypeCoerce::toString($existing['offer_id'] ?? '');

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
                    $guests_data = $existing['guests_data'] ?? null;
                    if (is_string($guests_data)) {
                        $guests_data = json_decode($guests_data, true);
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
                    $booking_type = CheckoutBookingBinder::bookingTypeOf($existing);
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
                    // Mark booking as failed in both sphinx_bookings and travel_bookings,
                    // keeping the supplier's answer for the admin order page.
                    $repo->update($booking_id, [
                        'status' => TravelConstants::STATUS_FAILED,
                        'api_response' => (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
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
        // always run it after submission (novoton parity). Never for a row
        // refused above: it is not this order's to carry.
        self::linkOrderBookings($resolved_order_id, $refused);
    }

    /**
     * A cart-stage row nothing else will ever reach: pending, on no order.
     *
     * @param array<string, mixed>|null $row
     */
    private static function isStranded(?array $row): bool
    {
        return $row !== null
            && TypeCoerce::toInt($row['order_id'] ?? 0) === 0
            && TypeCoerce::toString($row['status'] ?? '') === TravelConstants::STATUS_PENDING;
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

        self::rememberBoundBookings($cart);
    }

    /**
     * Remember the bookings this session's cart may carry
     * (CheckoutBookingBinder::SESSION_KEY) while the session id still matches
     * their rows: CS-Cart regenerates it when the guest logs in at checkout,
     * and the remembered ids keep those bookings theirs. Storefront only.
     *
     * @param array<array-key, mixed> $cart
     */
    private static function rememberBoundBookings(array $cart): void
    {
        $session = new SessionAccessor();
        $binder = CheckoutBookingBinder::fromSession($session);
        if ($binder->isAdminArea()) {
            return;
        }

        $ids = $binder->boundBookingIds(TypeCoerce::toStringMap($cart));
        if ($ids !== [] || $session->get(CheckoutBookingBinder::SESSION_KEY) !== null) {
            $session->set(CheckoutBookingBinder::SESSION_KEY, $ids);
        }
    }

    /**
     * Link any unlinked sphinx bookings referenced by an order's items to that
     * order. Idempotent: bookings already linked are left untouched; status is
     * NOT changed (a reconciliation must not reset a confirmed booking).
     *
     * An unlinked row is linked only when it is the item's booking (audit
     * H2): the row's offer_id is the item's, and its product too when the
     * row has one; never a row place_order_post refused ($excluded).
     *
     * @param list<int> $excluded booking ids never to link
     * @return int Number of bookings newly linked
     */
    public static function linkOrderBookings(int $order_id, array $excluded = []): int
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
            if ($booking_id <= 0 || in_array($booking_id, $excluded, true)) {
                continue;
            }

            $row = $repo->findById($booking_id);
            if ($row === null) {
                continue;
            }
            $current_order = TypeCoerce::toInt($row['order_id'] ?? 0);

            // Already linked to a DIFFERENT order — never steal it.
            if ($current_order > 0 && $current_order !== $order_id) {
                continue;
            }

            // Not linked yet: only the item's own booking.
            if ($current_order <= 0 && !self::isItemsBooking($product, $extra, $row)) {
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
     * Whether a row is the booking an order item names: the same offer, and
     * the same product when the row records one.
     *
     * @param array<array-key, mixed> $item
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $row
     */
    private static function isItemsBooking(array $item, array $extra, array $row): bool
    {
        $offerId = TypeCoerce::toString($row['offer_id'] ?? '');
        if ($offerId === '' || $offerId !== TypeCoerce::toString($extra['offer_id'] ?? '')) {
            return false;
        }
        $productId = TypeCoerce::toInt($row['product_id'] ?? 0);

        return $productId <= 0 || $productId === TypeCoerce::toInt($item['product_id'] ?? 0);
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
