<?php

declare(strict_types=1);

/**
 * Travel Core — the booking card under a travel line of an order
 * (see ViewModels\OrderBookingCardFactory).
 *
 * The procedural face for the order pages:
 *   admin     hooks/orders/product_info.post.tpl (backend)
 *             {$tobc = fn_travel_core_order_booking_card($oi, $order_info, "admin")}
 *   customer  hooks/orders/product_info.post.tpl (themes)
 *             {$tobc = fn_travel_core_order_booking_card($product, $order_info, "customer")}
 *
 * It gathers what the pure factory is handed: the line's ?:travel_bookings
 * row, what its provider knows about it (TravelProviderRegistry::orderCardFacts,
 * registered by each provider from its init.php), the provider's terms, the
 * order's balances, the hotel record, and for the admin the provider's name,
 * the supplier price in its own currency and the provider's remedies for a
 * failed booking (BookingAdminProviderInterface::getAvailableActions).
 *
 * The admin card is built only in the admin area, whatever a template asks
 * for: the customer's card never carries a provider, a supplier reference or
 * price, or a supplier error. Nothing may escape from here — a failure is
 * logged and the line simply shows no card.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ViewModels\OrderBookingCardFactory;

defined('BOOTSTRAP') or die('Access denied');

/**
 * The card's view array for one order line; [] when it is not a travel booking.
 *
 * @return array<string, mixed>
 */
function fn_travel_core_order_booking_card(mixed $item, mixed $order_info = [], mixed $audience = 'customer'): array
{
    $line = TypeCoerce::toStringMap($item);
    $extra = TypeCoerce::toStringMap($line['extra'] ?? null);
    if (empty($extra['travel_booking'])) {
        return [];
    }
    $order = TypeCoerce::toStringMap($order_info);
    $admin = $audience === OrderBookingCardFactory::AUDIENCE_ADMIN && defined('AREA') && AREA === 'A';

    try {
        $facts = TravelProviderRegistry::orderCardFacts($extra);
        $booking = fn_travel_core_order_card_booking($extra, $facts);

        $productId = TypeCoerce::toInt($line['product_id'] ?? 0);
        $hotel = $productId > 0
            ? TravelProviderRegistry::resolveProductOwner($productId, TypeCoerce::toString($line['product_code'] ?? ''))
            : null;

        $meta = $admin ? fn_travel_core_order_card_admin_meta($facts, $booking) : [];
        $money = $admin && defined('CART_PRIMARY_CURRENCY')
            ? MoneyFormatter::forCurrency(TypeCoerce::toString(CART_PRIMARY_CURRENCY))
            : MoneyFormatter::forStore();

        $card = (new OrderBookingCardFactory($money, date('Y-m-d'), TravelCoreConfig::getDateFormat()))->build(
            $line,
            $admin ? OrderBookingCardFactory::AUDIENCE_ADMIN : OrderBookingCardFactory::AUDIENCE_CUSTOMER,
            $booking,
            $facts,
            $meta,
            TravelProviderRegistry::cartTerms($extra),
            TypeCoerce::toRowList($order['travel_balances'] ?? null),
            $hotel,
        );
        if ($card === []) {
            return [];
        }

        // Several bookings on one admin order: one summary row each, opened
        // on demand — except one that needs action.
        $card['collapsible'] = $admin && fn_travel_core_order_card_count($order) > 1;
        $card['open'] = !$card['collapsible'] || $card['alert'] !== [];

        return $card;
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Travel order booking card: ' . $e::class . ': ' . $e->getMessage(),
        ]);

        return [];
    }
}

/**
 * The line's ?:travel_bookings row: by the unified id when a provider hook
 * resolved it, else by the provider's own booking id.
 *
 * @param array<string, mixed> $extra
 * @param array<string, mixed> $facts
 * @return array<string, mixed>
 */
function fn_travel_core_order_card_booking(array $extra, array $facts): array
{
    $surrogate = TypeCoerce::toInt($extra['travel_surrogate_id'] ?? 0);
    if ($surrogate > 0) {
        $row = db_get_row('SELECT * FROM ?:travel_bookings WHERE booking_id = ?i', $surrogate);
    } else {
        $provider = TypeCoerce::toString($facts['provider'] ?? '');
        $providerBookingId = TypeCoerce::toString($facts['provider_booking_id'] ?? '');
        if ($provider === '' || $providerBookingId === '' || $providerBookingId === '0') {
            return [];
        }
        $row = db_get_row(
            'SELECT * FROM ?:travel_bookings WHERE provider = ?s AND provider_booking_id = ?s',
            $provider,
            $providerBookingId,
        );
    }

    return TypeCoerce::toStringMap($row);
}

/**
 * Admin-only extras: the provider's name, the supplier price in its own
 * currency, and — for a failed booking — the provider's remedies as links
 * (POST ones carry cm-post, which submits them with the security hash).
 *
 * @param array<string, mixed> $facts
 * @param array<string, mixed> $booking
 * @return array<string, mixed>
 */
function fn_travel_core_order_card_admin_meta(array $facts, array $booking): array
{
    $provider = TypeCoerce::toString($facts['provider'] ?? $booking['provider'] ?? '');
    $info = $provider !== '' ? TravelProviderRegistry::get($provider) : null;
    $meta = ['provider_name' => $info !== null ? TypeCoerce::toString($info['label']) : ucfirst($provider)];

    $total = TypeCoerce::toFloat($booking['total_price'] ?? 0);
    $currency = strtoupper(TypeCoerce::toString($booking['currency'] ?? ''));
    if ($total > 0 && $currency !== '') {
        $meta['supplier_price'] = MoneyFormatter::forCurrency($currency)->formatDisplay($total);
    }

    $actions = [];
    $adminProvider = $provider !== '' ? TravelProviderRegistry::getBookingAdminProvider($provider) : null;
    if ($adminProvider !== null && $booking !== [] && TypeCoerce::toString($booking['status'] ?? '') === TravelConstants::STATUS_FAILED) {
        foreach ($adminProvider->getAvailableActions($booking) as $action) {
            $params = TypeCoerce::toStringMap($action['extra_params'] ?? null);
            $query = ['provider' => $provider] + $params;
            $query['provider_action'] = TypeCoerce::toString($params['provider_action'] ?? $action['name'] ?? '');
            if (!empty($action['booking_id'])) {
                $query['booking_id'] = TypeCoerce::toString($action['booking_id']);
            }
            $url = TypeCoerce::toString($action['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $post = strtoupper(TypeCoerce::toString($action['method'] ?? 'GET')) === 'POST';
            $actions[] = [
                'label' => TypeCoerce::toString($action['label'] ?? ''),
                'href' => $post ? $url . '?' . http_build_query($query) : $url,
                'post' => $post,
                'confirm' => str_contains(TypeCoerce::toString($action['css_class'] ?? ''), 'cm-confirm'),
            ];
        }
    }
    $meta['actions'] = $actions;

    return $meta;
}

/**
 * How many travel bookings an order holds (its top-level lines).
 *
 * @param array<string, mixed> $order
 */
function fn_travel_core_order_card_count(array $order): int
{
    $count = 0;
    foreach (TypeCoerce::toRowList($order['products'] ?? null) as $product) {
        $extra = TypeCoerce::toStringMap($product['extra'] ?? null);
        if (!empty($extra['travel_booking']) && empty($extra['parent'])) {
            $count++;
        }
    }

    return $count;
}
