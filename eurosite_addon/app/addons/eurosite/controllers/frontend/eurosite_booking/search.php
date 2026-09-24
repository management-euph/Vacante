<?php

declare(strict_types=1);
/**
 * eurosite_booking.search — destination-driven hotel search.
 *
 * Unlike novoton/sphinx (whose searches start from a CS-Cart hotel product),
 * Eurosite search is country/city-driven: the visitor picks a whitelisted
 * destination next to the shared travel_core booking engine, the engine
 * appends {country, city} via its data-extra-params contract, and this
 * controller fans the query out to every tourop present in the city's synced
 * hotel rows (live data: "LA").
 */

use Tygh\Addons\Eurosite\Exception\EurositeApiException;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$country = strtoupper((string) preg_replace('/[^A-Za-z]/', '', RequestCoerce::string($_REQUEST, 'country')));
$city = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', RequestCoerce::string($_REQUEST, 'city')));
$checkIn = RequestCoerce::string($_REQUEST, 'check_in');
$checkOut = RequestCoerce::string($_REQUEST, 'check_out');
$adults = max(1, TypeCoerce::toInt($_REQUEST['adults'] ?? 2));
$childrenAges = [];
foreach (explode(',', RequestCoerce::string($_REQUEST, 'children_ages')) as $age) {
    if ($age !== '' && is_numeric($age)) {
        $childrenAges[] = (int) $age;
    }
}

// ── From a hotel's product page ──
// travel_core's booking form sends the hotel's code (hotel_id) instead of a
// destination: search that hotel's city and show that hotel only.
$onlyHotel = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', RequestCoerce::string($_REQUEST, 'hotel_id')));
if ($onlyHotel !== '') {
    $hotelRow = Container::hotels()->findByProductCode($onlyHotel);
    if ($hotelRow === null) {
        $onlyHotel = '';
    } else {
        $country = $country !== '' ? $country : strtoupper(TypeCoerce::toString($hotelRow['country_code'] ?? ''));
        $city = $city !== '' ? $city : strtoupper(TypeCoerce::toString($hotelRow['city_code'] ?? ''));
    }
}

// ── Destination pickers: whitelisted countries + their allowed cities ──
$whitelist = Container::whitelist();
$cityRepo = Container::cities();
$countryRepo = Container::countries();

$countryNames = [];
foreach ($countryRepo->findAll() as $row) {
    $countryNames[TypeCoerce::toString($row['country_code'] ?? '')] = TypeCoerce::toString($row['name'] ?? '');
}

$destinations = [];
foreach ($whitelist->getCountryCodes() as $cc) {
    $cities = [];
    $allowedCodes = array_flip($whitelist->getAllowedCityCodes($cc));
    foreach ($cityRepo->getByCountry($cc) as $cityRow) {
        $code = TypeCoerce::toString($cityRow['city_code'] ?? '');
        if ($code === '' || !isset($allowedCodes[$code])) {
            continue;
        }
        $cities[] = [
            'code'   => $code,
            'name'   => TypeCoerce::toString($cityRow['name'] ?? ''),
            'is_own' => TypeCoerce::toString($cityRow['is_own'] ?? 'N') === 'Y',
        ];
    }
    if ($cities !== []) {
        $destinations[] = [
            'code'   => $cc,
            'name'   => $countryNames[$cc] ?? $cc,
            'cities' => $cities,
        ];
    }
}

// ── Render the shared booking engine BEFORE any heavy assigns (Smarty 5) ──
$searchParams = [
    'check_in'      => $checkIn,
    'check_out'     => $checkOut,
    'adults'        => $adults,
    'children'      => count($childrenAges),
    'children_ages' => implode(',', $childrenAges),
];
$bookingEngineHtml = function_exists('fn_travel_core_render_booking_engine')
    ? fn_travel_core_render_booking_engine([
        'provider'        => 'eurosite',
        'search_dispatch' => 'eurosite_booking.search',
        'mode'            => 'search',
        'search_params'   => $searchParams,
    ])
    : '';

// ── Search ──
$results = [];
$searchError = '';
$searched = false;

if ($country !== '' && $city !== '' && $checkIn !== '' && $checkOut !== '') {
    $searched = true;

    if (!$whitelist->isCityAllowed($country, $city)) {
        $searchError = __('eurosite.destination_not_available', [
            '[default]' => 'This destination is not available for booking.',
        ]);
    } else {
        // Room-type GCode by occupancy (base codes; children ride as ages).
        $roomCode = match (true) {
            $adults <= 1 => 'SB',
            $adults === 2 => 'DB',
            $adults === 3 => 'TR',
            default => 'Q',
        };
        $roomsPayload = [['code' => $roomCode, 'adults' => $adults, 'children' => $childrenAges]];

        $tourops = Container::hotels()->getTouropCodesForCity($city);
        if ($tourops === []) {
            $tourops = [ConfigProvider::getTourOpCode()];
        }

        $offers = [];
        try {
            foreach ($tourops as $tourop) {
                $found = Container::getApi()->searchHotels([
                    'country_code' => $country,
                    'city_code'    => $city,
                    'tourop_code'  => $tourop,
                    'check_in'     => $checkIn,
                    'check_out'    => $checkOut,
                    'currency'     => ConfigProvider::getDefaultCurrency(),
                    'language'     => ConfigProvider::getDefaultLanguage(),
                    'rooms'        => $roomsPayload,
                ]);
                foreach ($found as $offer) {
                    $offers[] = $offer;
                }
            }
        } catch (EurositeApiException $e) {
            $searchError = __('eurosite.search_failed', [
                '[default]' => 'The Eurosite search service did not answer. Please try again later.',
            ]);
            fn_log_event('general', 'runtime', ['message' => 'Eurosite search failed: ' . $e->getMessage()]);
        }

        if ($searchError === '' && $offers !== []) {
            $offerKeys = OfferContextStore::remember($offers, [
                'adults'        => $adults,
                'children_ages' => $childrenAges,
            ]);

            // Group offers per hotel; enrich the card from the product-info
            // cache (lazy-fill a handful per request, spec-mandated cache).
            $cache = Container::productInfoCache();
            $hotelRepo = Container::hotels();
            $lazyBudget = 8;
            foreach ($offers as $i => $offer) {
                $pc = $offer->productCode;
                if ($onlyHotel !== '' && strtoupper($pc) !== $onlyHotel) {
                    continue;
                }
                if (!isset($results[$pc])) {
                    $hotelRow = $hotelRepo->findByProductCode($pc);
                    $tourop = $hotelRow !== null ? TypeCoerce::toString($hotelRow['tourop_code'] ?? '') : '';
                    $info = $tourop !== '' ? $cache->get($tourop, $pc) : null;
                    if ($info === null && $tourop !== '' && $lazyBudget > 0) {
                        $lazyBudget--;
                        try {
                            $fetched = Container::getApi()->getProductInfo($country, $city, $pc, 'hotel', $tourop);
                            $cache->put($tourop, $pc, $country, $city, $fetched);
                            $info = $cache->get($tourop, $pc);
                        } catch (\Throwable $e) {
                            $info = null;
                        }
                    }
                    $pictures = [];
                    if ($info !== null && !empty($info['pictures_json'])) {
                        $decoded = json_decode(TypeCoerce::toString($info['pictures_json']), true);
                        $pictures = is_array($decoded) ? $decoded : [];
                    }
                    $results[$pc] = [
                        'product_code' => $pc,
                        'name'         => $offer->productName,
                        'category'     => $offer->category,
                        'city_name'    => $offer->cityName,
                        'image'        => $offer->firstImage !== ''
                            ? $offer->firstImage
                            : TypeCoerce::toString($pictures[0] ?? ''),
                        'description'  => $info !== null ? TypeCoerce::toString($info['description'] ?? '') : '',
                        'offers'       => [],
                    ];
                }
                $results[$pc]['offers'][] = [
                    'key'          => $offerKeys[$i] ?? '',
                    'row_id'       => count($results[$pc]['offers']) + 1,
                    'offer_type'   => $offer->offerType,
                    'availability' => $offer->availability,
                    'availability_code' => $offer->availabilityCode,
                    'bookable'     => $offer->isBookable() && ($offerKeys[$i] ?? '') !== '',
                    'check_in'     => $offer->checkIn,
                    'check_out'    => $offer->checkOut,
                    'price'        => number_format($offer->price, 2),
                    'price_raw'    => $offer->price,
                    'currency'     => $offer->currency,
                    'grila'        => $offer->grila,
                    'rooms'        => $offer->rooms,
                    'meals'        => $offer->meals,
                ];
            }
            $results = array_values($results);
        }
    }
}

$view->assign('booking_engine_html', $bookingEngineHtml);
$view->assign('eurosite_destinations', $destinations);
$view->assign('eurosite_results', $results);
$view->assign('eurosite_searched', $searched);
$view->assign('eurosite_search_error', $searchError);
$view->assign('eurosite_params', [
    'country'       => $country,
    'city'          => $city,
    'check_in'      => $checkIn,
    'check_out'     => $checkOut,
    'adults'        => $adults,
    'children_ages' => implode(',', $childrenAges),
]);
