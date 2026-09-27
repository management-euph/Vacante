<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Booking Form Mode
 *
 * Verifies the selected offer via SphinxApi::verifyHotelOffer(),
 * then displays the guest entry form with verified pricing.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory;

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
$hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
$product_id = RequestCoerce::int($_REQUEST, 'product_id');

if (empty($offer_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer selected.']));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

try {
    $api = Container::getApi();

    // Verify only when the API says it is required.
    //
    // Sphinx marks every offer with `must_verify`, and its docs are explicit:
    // "You must use this endpoint whenever the chosen offer has the
    // must_verify property set to true", and "this call can be an expensive
    // operation and should be used only when necessary". Calling it
    // unconditionally was always wasteful; it became a fault when the
    // provider's verify started 500ing ("Undefined array key 0" in their
    // EtripV2Client), because offers that never needed verifying became
    // unbookable. Measured live: all four Porto Bello Hotel Resort&Spa offers
    // carry must_verify=false, and every verify call for them 500s.
    //
    // The skip is driven by a SERVER-side snapshot written when the search
    // results were cached — never by the request, whose price could be edited.
    // No snapshot, or any doubt in it, means we verify exactly as before.
    $snapshot = \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::get($offer_id);
    $skipVerify = \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::isBookableWithoutVerify($snapshot);

    if ($skipVerify) {
        // Shaped like an unwrapped verify payload so everything downstream is
        // unchanged — including `price` being the PRE-commission figure, which
        // line 140 then commissions exactly once. The book endpoint
        // re-verifies server-side anyway and only proceeds when pricing is
        // unchanged, so this cannot commit a stale price to an order.
        $verifyResult = \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::toVerifyShape($snapshot ?? []);
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx booking_form: verify SKIPPED — offer declares must_verify=false',
            'offer_id' => $offer_id,
            'price' => TypeCoerce::toString($snapshot['price'] ?? ''),
            'confirmation' => TypeCoerce::toString($snapshot['confirmation'] ?? ''),
        ]);
    } else {
        $verifyResult = $api->verifyHotelOffer($offer_id);
    }

    if (!$skipVerify && !\Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::isVerifiedAvailable(
        $verifyResult,
        ConfigProvider::shouldRequireImmediateAvailability(),
    )) {
        // A 5xx / transport failure is the VERIFY SERVICE being down, not the
        // offer being gone (field case: the staging API 500ing inside its
        // supplier client on every verify) — telling the guest to "search
        // again" would send them chasing fresh offers that cannot verify
        // either. Say what is true and skip the pointless cache eviction.
        $verifyHttp = $api->getHttpClient();
        $verifyHttpCode = $verifyHttp->getLastHttpCode();
        $verifyOutage = $verifyHttpCode === 0 || $verifyHttpCode >= 500;

        // Diagnosability: record WHY the offer was rejected (outage vs shape
        // drift vs genuine expiry) — this rejection previously logged nothing.
        //
        // The RAW body matters more than the decoded one: a 5xx decodes to
        // null, so 'verify_response' recorded the literal string "null" and
        // discarded the provider's actual error message — which is exactly what
        // has to be forwarded to them to get the outage fixed.
        fn_log_event('general', 'runtime', [
            'message' => $verifyOutage
                ? 'Sphinx booking_form: verify OUTAGE (HTTP ' . $verifyHttpCode . ') — offer not rejected on merit'
                : 'Sphinx booking_form: offer rejected by verify (HTTP ' . $verifyHttpCode . ')',
            'offer_id' => $offer_id,
            'transport_error' => $verifyHttp->getLastError(),
            'raw_response' => substr((string) $verifyHttp->getLastResponseRaw(), 0, 1000),
            'verify_response' => substr((string) json_encode($verifyResult, JSON_UNESCAPED_UNICODE), 0, 500),
        ]);
        if ($verifyOutage) {
            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.booking_system_unavailable', ['[default]' => 'The booking system is temporarily unavailable. Please try again in a few minutes.']));
        } else {
            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available. Please search again.']));
        }
        // refresh=1 → the next search bypasses + evicts the cached result set,
        // so the customer lands on a LIVE re-search instead of the same stale
        // offers — only meaningful for a genuine expiry. With a known product
        // the customer came from (or belongs on) the product page, where the
        // booking engine auto-runs the search inline; the headerless
        // standalone results page is only the fallback for searches with no
        // product context.
        $redirectParams = [
            'check_in' => RequestCoerce::string($_REQUEST, 'check_in'),
            'check_out' => RequestCoerce::string($_REQUEST, 'check_out'),
            'adults' => RequestCoerce::int($_REQUEST, 'adults', 2),
            'children' => RequestCoerce::int($_REQUEST, 'children'),
        ];
        if (!$verifyOutage) {
            $redirectParams['refresh'] = 1;
        }
        if ($product_id > 0) {
            return [CONTROLLER_STATUS_REDIRECT, 'products.view?' . http_build_query(
                ['product_id' => $product_id] + $redirectParams,
            )];
        }
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.search?' . http_build_query(
            ['hotel_id' => $hotel_id, 'product_id' => $product_id] + $redirectParams,
        )];
    }

    // Read offer fields from the unwrapped payload — the live verify endpoint
    // wraps the offer in an envelope (see OfferAvailability::unwrapOffer).
    $verifiedOffer = \Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::unwrapOffer($verifyResult) ?? [];

    $verifiedPrice = \Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::extractPrice($verifiedOffer);
    $basePrice = $verifiedPrice;
    $verifiedPrice = Container::getCartService()->applyCommission($verifiedPrice);

    $hotelName = TypeCoerce::toString($verifiedOffer['hotel_name'] ?? '');
    // Room/board names: verify response first; when it omits them (shape
    // drift) fall back to what the search offer showed — the results card
    // passes them on the Book-now URL. Display-only: price/ids stay
    // verify-sourced.
    $roomName = TypeCoerce::toString($verifiedOffer['room_name'] ?? $verifiedOffer['room_type'] ?? '');
    if ($roomName === '') {
        $roomName = RequestCoerce::string($_REQUEST, 'room_name');
    }
    $boardName = TypeCoerce::toString($verifiedOffer['board_name'] ?? $verifiedOffer['board_type'] ?? '');
    if ($boardName === '') {
        $boardName = RequestCoerce::string($_REQUEST, 'board_name');
    }
    $checkIn = TypeCoerce::toString($verifiedOffer['check_in'] ?? RequestCoerce::string($_REQUEST, 'check_in'));
    $checkOut = TypeCoerce::toString($verifiedOffer['check_out'] ?? RequestCoerce::string($_REQUEST, 'check_out'));
    $nights = 0;
    if (!empty($checkIn) && !empty($checkOut)) {
        $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    }
    $adults = TypeCoerce::toInt($verifiedOffer['adults'] ?? RequestCoerce::int($_REQUEST, 'adults', 2));
    $children = TypeCoerce::toInt($verifiedOffer['children'] ?? RequestCoerce::int($_REQUEST, 'children'));
    $childrenAges = TypeCoerce::toString($verifiedOffer['children_ages'] ?? RequestCoerce::string($_REQUEST, 'children_ages'));

    // Load the hotel row for the location line + map link (same source as the
    // search card and PDP). The verify response gives the display name but no
    // address/coordinates, so read them from ?:sphinx_hotels.
    $hotelRow = $hotel_id !== ''
        ? Container::getHotelRepository()->findById($hotel_id)
        : null;

    if (empty($product_id) && !empty($hotel_id)) {
        $product_id = $hotelRow !== null
            ? TypeCoerce::toInt($hotelRow['product_id'] ?? 0)
            : TypeCoerce::toInt(db_get_field(
                "SELECT product_id FROM ?:sphinx_hotels WHERE hotel_id = ?s",
                $hotel_id
            ));
    }

    $hotelLocationLine = '';
    $hotelMapUrl = '';
    $bfHeaderVm = null;
    if ($hotelRow !== null) {
        // Field mapping mirrors the sphinx search page: city =
        // COALESCE(address_city, destination_name), country =
        // COALESCE(address_country, country_name).
        $bfAddressCity = trim(TypeCoerce::toString($hotelRow['address_city'] ?? ''));
        $bfAddressCountry = trim(TypeCoerce::toString($hotelRow['address_country'] ?? ''));
        $bookingHotelSeo = new HotelSeoData(
            hotelId: $hotel_id,
            providerName: 'sphinx',
            name: $hotelName !== '' ? $hotelName : TypeCoerce::toString($hotelRow['name'] ?? ''),
            city: $bfAddressCity !== '' ? $bfAddressCity : TypeCoerce::toString($hotelRow['destination_name'] ?? ''),
            region: TypeCoerce::toString($hotelRow['region_name'] ?? ''),
            country: $bfAddressCountry !== '' ? $bfAddressCountry : TypeCoerce::toString($hotelRow['country_name'] ?? ''),
            latitude: TypeCoerce::toFloat($hotelRow['latitude'] ?? 0),
            longitude: TypeCoerce::toFloat($hotelRow['longitude'] ?? 0),
            address: TypeCoerce::toString($hotelRow['address'] ?? ''),
        );
        // Shared header derivation via the travel_core factory.
        $bfHeaderVm = HotelHeaderFactory::fromSeo(
            $bookingHotelSeo,
            TypeCoerce::toInt($hotelRow['classification'] ?? 0),
            TypeCoerce::toInt($product_id),
        );
        $hotelLocationLine = $bfHeaderVm->locationLine;
        $hotelMapUrl = $bfHeaderVm->mapUrl;
    }

    // Build the single-room rooms_data the guest-card template iterates over
    // (the hotel booking form is always one room). Without this the guest-card
    // {foreach $sphinx_booking_data.rooms_data} loop had nothing to iterate and
    // rendered ZERO guest name fields. Shape matches the shared guest-cards
    // markup: adults / children / childrenAges / room_name / board_name / price.
    $childrenAgesList = [];
    if ($childrenAges !== '') {
        foreach (explode(',', $childrenAges) as $ageToken) {
            $ageToken = trim($ageToken);
            if ($ageToken !== '') {
                $childrenAgesList[] = (int) $ageToken;
            }
        }
    }
    $roomsData = [[
        'adults'       => $adults,
        'children'     => $children,
        'childrenAges' => $childrenAgesList,
        'room_name'    => $roomName,
        'board_name'   => $boardName,
        'price'        => $verifiedPrice,
    ]];

    // Shared hotel-identity header component (travel_core
    // components/hotel_header.tpl) — same variable name and shape on all
    // four provider surfaces. The factory VM's name comes from the SEO DTO
    // (verify-name with row-name fallback); bare header when no hotel row.
    $view->assign('travel_hotel_header', ($bfHeaderVm ?? HotelHeaderFactory::bare(
        $hotelName,
        0,
        TypeCoerce::toInt($product_id),
    ))->toViewArray());

    $bfBookingData = [
        'offer_id' => $offer_id,
        'num_rooms' => 1,
        'rooms_data' => $roomsData,
        'hotel_id' => $hotel_id,
        'product_id' => $product_id,
        'hotel_name' => $hotelName,
        // Star rating (classification column) → gold ★ glyphs in the header,
        // parity with the search card.
        'hotel_stars' => $hotelRow !== null
            ? min(5, max(0, TypeCoerce::toInt($hotelRow['classification'] ?? 0)))
            : 0,
        'hotel_location_line' => $hotelLocationLine,
        'hotel_map_url' => $hotelMapUrl,
        'room_name' => $roomName,
        'board_name' => $boardName,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $childrenAges,
        'total_price' => $verifiedPrice,
        'base_price' => $basePrice,
        'currency' => ConfigProvider::getDefaultCurrency(),
        'verified' => true,
        // Payment & cancellation terms straight from the verified offer
        // (formatted display lines; [] when the API sends none).
        'payment_terms' => \Tygh\Addons\SphinxHolidays\Services\TermsFormatter::lines($verifiedOffer['payment_terms'] ?? null),
        'cancellation_fees' => \Tygh\Addons\SphinxHolidays\Services\TermsFormatter::lines($verifiedOffer['cancellation_fees'] ?? null),
    ];
    $view->assign('sphinx_booking_data', $bfBookingData);

    $view->assign('sphinx_provider', 'sphinx');

    // ── Shared 2-column summary sidebar (travel_core component) ───────────
    // Same builder as edit_booking: one page, one summary, whichever mode
    // rendered it. The raw cancellation payload goes in too — the free-until
    // date can only be derived from the rules, not from the formatted lines.
    $bfCurrency = ConfigProvider::getDefaultCurrency();
    $bfFormattedTotal = number_format($verifiedPrice, 2, ',', '.') . ' ' . ($bfCurrency === 'EUR' ? '€' : $bfCurrency);

    // Money on the cart-line scale: the SAME conversion CartService applies
    // when it stores the line (API currency → store primary), so the booking
    // page, the mobile bar and the cart all show one amount — in the
    // shopper's selected currency (MoneyFormatter).
    $bfToPrimary = static fn (float $amount): float => (new \Tygh\Addons\TravelCore\Services\CurrencyService($bfCurrency))
        ->convertFromApiCurrency($amount, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $bfCurrency);
    $bfPricing = TypeCoerce::toStringMap($verifiedOffer['pricing'] ?? null);
    $bfMarketing = TypeCoerce::toFloat($bfPricing['marketing_price'] ?? 0);
    $bfOld = $bfMarketing > $basePrice ? Container::getCartService()->applyCommission($bfMarketing) : 0.0;
    // The offer's own labels ("Early Booking") name the reduction.
    $bfLabels = [];
    foreach (TypeCoerce::toRowList($verifiedOffer['labels'] ?? null) as $bfLabel) {
        $bfName = trim(TypeCoerce::toString($bfLabel['name'] ?? ''));
        if ($bfName !== '') {
            $bfLabels[] = $bfName;
        }
    }

    $bfSidebar = SphinxBookingSidebarBuilder::build(
        $bfBookingData,
        $bfHeaderVm ?? HotelHeaderFactory::bare($hotelName, 0, TypeCoerce::toInt($product_id)),
        $hotelRow,
        $bfFormattedTotal,
        $verifiedOffer['cancellation_fees'] ?? null,
        true,
        defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        $bfToPrimary($verifiedPrice),
        $bfOld > 0 ? $bfToPrimary($bfOld) : 0.0,
        implode(' · ', $bfLabels),
        TypeCoerce::toString($verifiedOffer['confirmation'] ?? ''),
        $verifiedOffer['payment_terms'] ?? null,
        date('Y-m-d'),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getBookingSidebarMaxFeatures(),
    );
    $view->assign('travel_booking_sidebar', $bfSidebar->toViewArray());

    // Same page title + breadcrumb as the other providers' booking pages.
    $bfTitle = TypeCoerce::toString(__('sphinx_holidays.complete_booking', ['[default]' => 'Complete Your Booking']));
    $view->assign('page_title', $bfTitle);
    \Tygh\Registry::set('navigation.dynamic.page_title', $bfTitle);
    // No breadcrumb: the page title and the progress bar share one row
    // (booking_steps.tpl), so the form starts higher. <title> still uses page_title.

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}
