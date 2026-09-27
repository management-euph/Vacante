<?php

declare(strict_types=1);

/**
 * Travel Core — "Skip the cart page for" (see Services\CartSkipPolicy).
 *
 * The procedural face of the policy for the provider add-ons' controllers
 * and booking-form templates, e.g.
 *   return [CONTROLLER_STATUS_REDIRECT, fn_travel_core_after_add_to_cart_url('novoton_holidays')];
 *   {fn_travel_core_booking_cta_label("novoton_holidays")} &rarr;
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CartSkipPolicy;

defined('BOOTSTRAP') or die('Access denied');

/** checkout.checkout when the add-on skips the cart, else checkout.cart. */
function fn_travel_core_after_add_to_cart_url(string $addon): string
{
    return CartSkipPolicy::current()->afterAddToCart($addon);
}

/**
 * The booking button's text: "Continue booking" when the add-on skips the
 * cart, else the form's own label ($default_key).
 */
function fn_travel_core_booking_cta_label(string $addon, string $default_key = CartSkipPolicy::LABEL_DEFAULT): string
{
    return TypeCoerce::toString(__(CartSkipPolicy::current()->ctaLabelKey($addon, $default_key)));
}
