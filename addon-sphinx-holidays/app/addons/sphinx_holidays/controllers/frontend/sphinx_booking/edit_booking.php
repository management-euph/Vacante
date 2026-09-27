<?php

declare(strict_types=1);

/**
 * Sphinx Booking Controller — Edit Booking Mode
 *
 * Re-opens the guest booking form for a cart-stage booking so the customer
 * can correct tourist names (the "Edit guest details" link on the shared
 * cart booking-details card). Ownership-checked (user OR session), NO offer
 * re-verify — the price and offer stay exactly as carted; only guest data
 * is editable. Renders views/sphinx_booking/edit_booking.tpl (a thin
 * wrapper around booking_form.tpl, novoton's edit pattern).
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory;
use Tygh\Registry;
use Tygh\Tygh;

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$booking_id = RequestCoerce::int($_REQUEST, 'booking_id');
$cart_id = RequestCoerce::string($_REQUEST, 'cart_id');

if (empty($booking_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid booking.']));
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

// Ownership check — user_id or session_id must match, order_id must be 0.
$session = Tygh::$app['session'];
$auth = (is_array($session) || $session instanceof \ArrayAccess) ? TypeCoerce::toStringMap($session['auth'] ?? []) : [];
$current_user_id = TypeCoerce::toInt($auth['user_id'] ?? 0);
$current_session_id = TypeCoerce::toString(session_id());

$booking_record = Container::getBookingRepository()->findByIdWithOwnership($booking_id, $current_user_id, $current_session_id);
if ($booking_record === null) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid booking.']));
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

// Prefer the live cart extras (fresher than the booking row); fall back to
// the booking row's stored fields.
$cart = (is_array($session) || $session instanceof \ArrayAccess) ? ($session['cart'] ?? null) : null;
// toArrayMap, NOT toStringMap: cart product ids are numeric (int keys) and
// toStringMap drops int-keyed entries — the live-cart preference below would
// silently never fire and every field would come from the stale booking row.
$cart_products = is_array($cart) ? TypeCoerce::toArrayMap($cart['products'] ?? null) : [];
$cart_extra = [];
if ($cart_id !== '' && is_array($cart_products[$cart_id] ?? null)) {
    $cart_item = TypeCoerce::toStringMap($cart_products[$cart_id]);
    $cart_extra = is_array($cart_item['extra'] ?? null) ? $cart_item['extra'] : [];
}

$src = static fn (string $key, mixed $default = '') => $cart_extra[$key] ?? $booking_record[$key] ?? $default;

$hotel_id = TypeCoerce::toString($src('hotel_id'));
$product_id = TypeCoerce::toInt($src('product_id', 0));
$hotelName = TypeCoerce::toString($src('hotel_name'));

// rooms_data: array in cart extras, JSON string on the booking row.
$roomsDataRaw = $src('rooms_data', []);
if (is_string($roomsDataRaw)) {
    $decoded = json_decode($roomsDataRaw, true);
    $roomsDataRaw = is_array($decoded) ? $decoded : [];
}
$roomsData = TypeCoerce::toRowList($roomsDataRaw);

// Prefill map keyed exactly like the shared guest-card input names
// (room{N}_adult_{i} / room{N}_child_{i}) — the shared builder handles the
// JSON-string vs array shapes and the birthday->DOB conversion.
$guestsRaw = $src('guests_data', []);
$guest_prefill = \Tygh\Addons\TravelCore\Services\GuestPrefillBuilder::build(
    is_string($guestsRaw) || is_array($guestsRaw) ? $guestsRaw : [],
);

// Header identity (same derivation as booking_form — repository row + the
// shared factory; bare header when the hotel row is missing).
$hotelRow = $hotel_id !== '' ? Container::getHotelRepository()->findById($hotel_id) : null;
$headerVm = null;
if ($hotelRow !== null) {
    $addressCity = trim(TypeCoerce::toString($hotelRow['address_city'] ?? ''));
    $addressCountry = trim(TypeCoerce::toString($hotelRow['address_country'] ?? ''));
    $headerVm = HotelHeaderFactory::fromSeo(
        new HotelSeoData(
            hotelId: $hotel_id,
            providerName: 'sphinx',
            name: $hotelName !== '' ? $hotelName : TypeCoerce::toString($hotelRow['name'] ?? ''),
            city: $addressCity !== '' ? $addressCity : TypeCoerce::toString($hotelRow['destination_name'] ?? ''),
            region: TypeCoerce::toString($hotelRow['region_name'] ?? ''),
            country: $addressCountry !== '' ? $addressCountry : TypeCoerce::toString($hotelRow['country_name'] ?? ''),
            latitude: TypeCoerce::toFloat($hotelRow['latitude'] ?? 0),
            longitude: TypeCoerce::toFloat($hotelRow['longitude'] ?? 0),
            address: TypeCoerce::toString($hotelRow['address'] ?? ''),
        ),
        TypeCoerce::toInt($hotelRow['classification'] ?? 0),
        $product_id,
    );
}
$header = $headerVm ?? HotelHeaderFactory::bare($hotelName, 0, $product_id);
$view->assign('travel_hotel_header', $header->toViewArray());

$editBookingData = [
    'offer_id' => TypeCoerce::toString($src('offer_id')),
    'num_rooms' => 1,
    'rooms_data' => $roomsData,
    'hotel_id' => $hotel_id,
    'product_id' => $product_id,
    'hotel_name' => $hotelName,
    'hotel_stars' => $hotelRow !== null ? min(5, max(0, TypeCoerce::toInt($hotelRow['classification'] ?? 0))) : 0,
    'hotel_location_line' => $header->locationLine,
    'hotel_map_url' => $header->mapUrl,
    'room_name' => TypeCoerce::toString($cart_extra['room_name'] ?? $booking_record['room_type'] ?? ''),
    'board_name' => TypeCoerce::toString($cart_extra['board_name'] ?? $booking_record['board_id'] ?? ''),
    'check_in' => TypeCoerce::toString($src('check_in')),
    'check_out' => TypeCoerce::toString($src('check_out')),
    'nights' => TypeCoerce::toInt($src('nights', 0)),
    'adults' => TypeCoerce::toInt($src('adults', 2)),
    'children' => TypeCoerce::toInt($src('children', 0)),
    'children_ages' => TypeCoerce::toString($src('children_ages')),
    'total_price' => TypeCoerce::toFloat($src('total_price', 0)),
    'base_price' => TypeCoerce::toFloat($booking_record['base_price'] ?? 0),
    'currency' => TypeCoerce::toString($src('currency', 'EUR')),
    // NO re-verify in edit mode: the carted offer/price stays untouched.
    'verified' => true,
    'payment_terms' => TypeCoerce::toList($cart_extra['payment_terms'] ?? []),
    'cancellation_fees' => TypeCoerce::toList($cart_extra['cancellation_fees'] ?? []),
];
$view->assign('sphinx_booking_data', $editBookingData);
$view->assign('sphinx_provider', 'sphinx');

// ── The same page, fully ──────────────────────────────────────────────────
// edit_booking.tpl is a one-line include of booking_form.tpl, so this mode
// renders the identical 2-column layout — but only if it assigns the identical
// view variables. It never assigned the sidebar, and since
// components/booking_sidebar.tpl is wrapped in `{if $tbs}`, the entire left
// column rendered as NOTHING: an isolated bare form with no hotel, no dates,
// no price, and an empty booking-conditions modal (which reads the same
// variable). Same builder as booking_form, so the two cannot diverge again.
//
// Built from the STORED cart line, never a live offer: this mode must not
// re-verify the offer (pinned by SphinxEditBookingTest), and a guest fixing a
// misspelled name must not have the price move under them.
$editCurrency = TypeCoerce::toString($editBookingData['currency']);
// The stored terms (raw since add_to_cart keeps them) rebuild the same
// timeline the booking form showed; the amount is the stored cart price.
$editTerms = json_decode(TypeCoerce::toString($cart_extra['terms_raw'] ?? ''), true);
$editTerms = is_array($editTerms) ? $editTerms : [];
$editPrimary = (new \Tygh\Addons\TravelCore\Services\CurrencyService($editCurrency))->convertFromApiCurrency(
    TypeCoerce::toFloat($editBookingData['total_price']),
    defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $editCurrency,
);
$editSidebar = SphinxBookingSidebarBuilder::build(
    $editBookingData,
    $header,
    $hotelRow,
    number_format(TypeCoerce::toFloat($editBookingData['total_price']), 2, ',', '.')
        . ' ' . ($editCurrency === 'EUR' ? '€' : $editCurrency),
    $editTerms['cancellation'] ?? $cart_extra['cancellation_fees'] ?? null,
    true,
    defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
    \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
    $editPrimary,
    0.0,
    '',
    TypeCoerce::toString($cart_extra['confirmation'] ?? ''),
    $editTerms['payment'] ?? null,
    date('Y-m-d'),
    \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
    \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getBookingSidebarMaxFeatures(),
);
$view->assign('travel_booking_sidebar', $editSidebar->toViewArray());

$view->assign('is_edit_mode', true);
$view->assign('edit_booking_id', $booking_id);
$view->assign('edit_cart_id', $cart_id);
$view->assign('guest_prefill', $guest_prefill);

$page_title = TypeCoerce::toString(__('sphinx_holidays.edit_booking', ['[default]' => 'Edit booking']));
$view->assign('page_title', $page_title);
Registry::set('navigation.dynamic.page_title', $page_title);
fn_add_breadcrumb($page_title);

// views/sphinx_booking/edit_booking.tpl (booking_form wrapper) renders automatically.
