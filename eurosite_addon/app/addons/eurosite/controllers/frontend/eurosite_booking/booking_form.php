<?php

declare(strict_types=1);
/**
 * eurosite_booking.booking_form — the guest booking form: the SAME shared
 * travel_core page sphinx and novoton render (summary sidebar + guest cards +
 * conditions modal), fed with eurosite data. Everything about the offer
 * comes from the server-side snapshot written at search time
 * (OfferContextStore) — the URL carries only the offer_key, so prices and
 * occupancy cannot be tampered with.
 *
 * Eurosite pax specifics (AddBookingRequest): per guest name, PaxType
 * adult/child, TGender B/F (C for children), DOB, ChildAge — passed to the
 * shared booking_guest_room_body.tpl as its opt-in gb_gender_options /
 * gb_child_gender params.
 */

use Tygh\Addons\Eurosite\Services\BookingSidebarBuilder;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;

if ($snapshot === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.search'];
}

$adults = max(1, TypeCoerce::toInt($snapshot['adults'] ?? 2));
$childrenAges = TypeCoerce::toIntList($snapshot['children_ages'] ?? []);
$hotelRow = Container::hotels()->findByProductCode(TypeCoerce::toString($snapshot['product_code']));

// Cancellation fees for the sidebar card + conditions modal (best effort —
// the card stays hidden when the API has no schedule for us).
$fees = [];
try {
    $tourop = $hotelRow !== null ? TypeCoerce::toString($hotelRow['tourop_code'] ?? '') : '';
    $rooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
    $fees = Container::getApi()->getItemFees([
        'currency'     => TypeCoerce::toString($snapshot['currency']),
        'country_code' => TypeCoerce::toString($snapshot['country_code']),
        'city_code'    => TypeCoerce::toString($snapshot['city_code']),
        'product_code' => TypeCoerce::toString($snapshot['product_code']),
        'variant_id'   => TypeCoerce::toString($snapshot['variant_id']),
        'check_in'     => TypeCoerce::toString($snapshot['check_in']),
        'check_out'    => TypeCoerce::toString($snapshot['check_out']),
        'tourop_code'  => $tourop,
        'rooms'        => [[
            'code'     => $rooms !== [] ? TypeCoerce::toString($rooms[0]['code'] ?? '') : '',
            'adults'   => $adults,
            'children' => $childrenAges,
        ]],
    ]);
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Eurosite booking_form fees unavailable: ' . $e->getMessage()]);
}

$paymentLines = BookingSidebarFactory::termLines(ConfigProvider::getPaymentTermsText());

// "Bucharest, Romania" — CS-Cart's country name when it knows the code.
$countryCode = TypeCoerce::toString($snapshot['country_code']);
$countryName = function_exists('fn_get_country_name') ? TypeCoerce::toString(fn_get_country_name($countryCode)) : '';
$locationLine = implode(', ', array_filter([
    TypeCoerce::toString($snapshot['city_name']),
    $countryName !== '' ? $countryName : $countryCode,
], static fn (string $part): bool => $part !== ''));

$productId = $hotelRow !== null ? TypeCoerce::toInt($hotelRow['product_id'] ?? 0) : 0;
$sidebar = BookingSidebarBuilder::build(
    $snapshot,
    $hotelRow,
    $fees,
    $paymentLines,
    $locationLine,
    function_exists('fn_travel_core_product_main_pair') ? fn_travel_core_product_main_pair($productId) : [],
    TypeCoerce::toString(__('eurosite.cancel_fee_line')),
    date('Y-m-d'),
    TravelCoreConfig::getDateFormat(),
);
$view->assign('travel_booking_sidebar', $sidebar->toViewArray());

// One room, in the shape the shared guest cards iterate.
$view->assign('eurosite_room', [
    'adults'       => $adults,
    'children'     => count($childrenAges),
    'childrenAges' => $childrenAges,
]);
$view->assign('eurosite_gender_options', [
    'B' => TypeCoerce::toString(__('eurosite.gender_male')),
    'F' => TypeCoerce::toString(__('eurosite.gender_female')),
]);
$view->assign('eurosite_offer_key', $offerKey);
$view->assign('eurosite_back_url', 'eurosite_booking.search?' . http_build_query([
    'country'       => $countryCode,
    'city'          => TypeCoerce::toString($snapshot['city_code']),
    'check_in'      => TypeCoerce::toString($snapshot['check_in']),
    'check_out'     => TypeCoerce::toString($snapshot['check_out']),
    'adults'        => $adults,
    'children_ages' => implode(',', $childrenAges),
]));

$pageTitle = TypeCoerce::toString(__('eurosite.complete_booking'));
$view->assign('page_title', $pageTitle);
Registry::set('navigation.dynamic.page_title', $pageTitle);
fn_add_breadcrumb($pageTitle);
