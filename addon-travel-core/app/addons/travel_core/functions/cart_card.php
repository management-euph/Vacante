<?php

declare(strict_types=1);

/**
 * Travel Core — the booking card on cart and checkout lines
 * (see ViewModels\CartBookingCardFactory).
 *
 * The procedural face for components/cart_booking_details.tpl:
 *   {$tcc = fn_travel_core_cart_booking_card($product, $key|default:'')}
 *
 * It gathers what the pure factory is handed: the storefront money format,
 * today, the store date format, the hotel record of the provider that owns
 * the product (stars, destination), the product image, and the provider's
 * cancellation / payment terms. Terms stay provider business: each provider
 * registers a resolver for its own cart extras from its init.php
 * (TravelProviderRegistry::setCartTermsResolver), returning cancel_windows /
 * payment_rows (TermsTimelineFactory input) and, where it has no structured
 * terms, cancel_lines / payment_lines. A provider that registers none
 * (eurosite: fees are quoted, never stored on the line) gets no terms block.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\ViewModels\CartBookingCardFactory;

defined('BOOTSTRAP') or die('Access denied');

/**
 * The card's view array for one cart line; [] when it is not a travel booking.
 *
 * @return array<string, mixed>
 */
function fn_travel_core_cart_booking_card(mixed $product, mixed $cart_id = ''): array
{
    $line = TypeCoerce::toStringMap($product);
    $extra = TypeCoerce::toStringMap($line['extra'] ?? null);
    if (empty($extra['travel_booking'])) {
        return [];
    }

    $productId = TypeCoerce::toInt($line['product_id'] ?? 0);
    $hotel = $productId > 0
        ? TravelProviderRegistry::resolveProductOwner($productId, TypeCoerce::toString($line['product_code'] ?? ''))
        : null;

    // Cart lines usually carry their icon already; the checkout sidebar's
    // may not.
    $imagePair = TypeCoerce::toStringMap($line['main_pair'] ?? null);
    if ($imagePair === [] && function_exists('fn_travel_core_product_main_pair')) {
        $imagePair = fn_travel_core_product_main_pair($productId);
    }

    $factory = new CartBookingCardFactory(
        MoneyFormatter::forStore(),
        date('Y-m-d'),
        TravelCoreConfig::getDateFormat(),
    );

    return $factory->build(
        $line,
        TypeCoerce::toString($cart_id),
        $hotel,
        $imagePair,
        TravelProviderRegistry::cartTerms($extra),
    );
}
