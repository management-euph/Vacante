<?php

declare(strict_types=1);

/**
 * Sphinx Booking Controller — Search Mode
 *
 * Initiates a hotel search via SphinxApi::searchHotels() and returns
 * the search_id to the template. Actual results are fetched by
 * client-side JS polling sphinx_booking.search_poll, avoiding the
 * previous sleep-loop that blocked the PHP thread for up to 60s.
 *
 * Cached results (if any) are rendered inline for instant display.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Helpers\SearchMetrics;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\CacheService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

try {
    $requestStartedMs = (int) round(microtime(true) * 1000);
    $api = Container::getApi();
    /** @var \Smarty $view */
    $view = Tygh::$app['view'];

    $check_in = RequestCoerce::string($_REQUEST, 'check_in');
    $check_out = RequestCoerce::string($_REQUEST, 'check_out');
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $product_id = max(0, RequestCoerce::int($_REQUEST, 'product_id'));
    $destination_id = RequestCoerce::int($_REQUEST, 'destination_id');
    $adults = max(1, RequestCoerce::int($_REQUEST, 'adults', 2));
    $children = max(0, RequestCoerce::int($_REQUEST, 'children'));
    $children_ages_str = RequestCoerce::string($_REQUEST, 'children_ages');
    $rooms = max(1, RequestCoerce::int($_REQUEST, 'rooms', 1));

    if (empty($check_out) && !empty($check_in)) {
        $nights = max(1, RequestCoerce::int($_REQUEST, 'nights', 7));
        $check_out = date('Y-m-d', (int) strtotime($check_in . " + {$nights} days"));
    }

    // Resolve the hotel record once when this is a product-page search
    // (hotel_id is set). Reused below for the destination_id and here for the
    // page heading so the results page shows which hotel is being searched
    // (mirrors the novoton results page).
    $hotelRow = $hotel_id !== ''
        ? Container::getHotelRepository()->findById($hotel_id)
        : null;

    $hotel_name = $hotelRow !== null ? TypeCoerce::toString($hotelRow['name'] ?? '') : '';
    // The hotel-header view model is gone from this surface: results render
    // inline on the product page (or headerless standalone), so search.tpl
    // no longer includes the shared hotel_header component. Only the hotel
    // NAME survives — the no-results request form posts it.

    $templateParams = [
        'hotel_id' => $hotel_id,
        'product_id' => $product_id,
        'destination_id' => $destination_id,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'rooms' => $rooms,
        'nights' => (TypeCoerce::toBool(strtotime($check_out ?: 'now')) && TypeCoerce::toBool(strtotime($check_in ?: 'now')))
            ? (int) round((strtotime($check_out) - strtotime($check_in)) / 86400) : 0,
    ];

    // Per-date "from" prices for the engine's calendar: raw values built by
    // the calendar_prices cron, commission applied at render time.
    $calendar_prices_json = '{}';
    if ($hotelRow !== null && !empty($hotelRow['calendar_prices_raw'])) {
        $rawCalendar = json_decode(TypeCoerce::toString($hotelRow['calendar_prices_raw']), true);
        if (is_array($rawCalendar) && $rawCalendar !== []) {
            $cartServiceForCalendar = Container::getCartService();
            $pricedCalendar = [];
            foreach ($rawCalendar as $calDate => $calPrice) {
                if (is_numeric($calPrice) && (float) $calPrice > 0) {
                    $pricedCalendar[(string) $calDate] = round($cartServiceForCalendar->applyCommission((float) $calPrice), 2);
                }
            }
            if ($pricedCalendar !== []) {
                $encodedCalendar = json_encode($pricedCalendar, JSON_UNESCAPED_UNICODE);
                $calendar_prices_json = is_string($encodedCalendar) ? $encodedCalendar : '{}';
            }
        }
    }

    // Render booking engine first — always needed
    $view->assign('booking_engine_html', fn_travel_core_render_booking_engine([
        'provider' => 'sphinx',
        'search_dispatch' => 'sphinx_booking.search',
        'mode' => 'search',
        'search_params' => $templateParams,
        'calendar_prices_json' => $calendar_prices_json,
        'calendar_prices_currency' => ConfigProvider::getDefaultCurrency(),
    ]));
    $view->assign('sphinx_search_params', $templateParams);

    // Client payloads for search-results.js, carried as DATA ATTRIBUTES on
    // #sphinx-results-container (search.tpl) — the same node that already
    // carries the search-id/status handshake. The old window.__sphinx*
    // globals lived in an inline <script> whose re-execution after the
    // engine's inline swap proved unreliable in the field (poll-rendered
    // cards shipped "check_in=undefined" Book URLs and English fallback
    // labels on product pages); attributes travel with the node itself, so
    // they are atomically consistent with the search they describe.
    // Same missing-key guard as the JS lbl(): CS-Cart returns "_key" for an
    // unseeded language variable — never ship that to the storefront.
    $sxLbl = static function (string $key, string $fallback, array $vars = []): string {
        $v = TypeCoerce::toString(__($key, $vars));
        return ($v === '' || str_starts_with($v, '_')) ? $fallback : $v;
    };
    $view->assign('sphinx_client_params_json', json_encode([
        'check_in' => $templateParams['check_in'],
        'check_out' => $templateParams['check_out'],
        'nights' => $templateParams['nights'],
        'currency' => ConfigProvider::getDefaultCurrency(),
        'adults' => $templateParams['adults'],
        'children' => $templateParams['children'],
        'children_ages' => $templateParams['children_ages'],
        'rooms' => $templateParams['rooms'],
        'product_id' => (string) $templateParams['product_id'],
    ], JSON_UNESCAPED_UNICODE));
    $view->assign('sphinx_client_config_json', json_encode([
        'maxPolls' => ConfigProvider::getSearchMaxPolls(),
        'pollInterval' => 250,
        'labels' => [
            'perNight' => $sxLbl('sphinx_holidays.per_night', 'night'),
            'instantConfirmation' => $sxLbl('sphinx_holidays.instant_confirmation', 'Instant confirmation'),
            'includesTaxes' => $sxLbl('sphinx_holidays.includes_taxes', 'Includes taxes and commissions'),
            'bookNow' => $sxLbl('sphinx_holidays.book_now', 'Book now'),
            'roomN' => $sxLbl('sphinx_holidays.sx_room', 'Room [num]'),
            'adultsOne' => $sxLbl('sphinx_holidays.sx_adults_one', '1 adult'),
            'adultsMany' => $sxLbl('sphinx_holidays.sx_adults_many', '[count] adults'),
            'childrenOne' => $sxLbl('sphinx_holidays.sx_children_one', '1 child'),
            'childrenMany' => $sxLbl('sphinx_holidays.sx_children_many', '[count] children'),
            'totalForRooms' => $sxLbl('sphinx_holidays.sx_total_for_rooms', 'Total for [count] rooms'),
            'nights' => $sxLbl('travel_core.nights', 'nights'),
            'starsRating' => $sxLbl('sphinx_holidays.stars_rating', '%s-star rating', ['[rating]' => '%s']),
            'cancellationAndPaymentTerms' => $sxLbl('sphinx_holidays.cancellation_and_payment_terms', 'Condiții de Plată și Anulare'),
            'paymentTerms' => $sxLbl('sphinx_holidays.payment_terms', 'Termeni de plată'),
            'cancellationPolicy' => $sxLbl('sphinx_holidays.cancellation_policy', 'Politica de anulare'),
            'freeCancellationUntil' => $sxLbl('sphinx_holidays.free_cancellation_until', 'Anulare gratuită înainte de'),
            'freeCancellation' => $sxLbl('sphinx_holidays.free_cancellation', 'Anulare gratuită'),
            'termsLoading' => $sxLbl('sphinx_holidays.terms_loading', 'Se încarcă condițiile...'),
            'termsUnavailable' => $sxLbl('sphinx_holidays.terms_unavailable', 'Condițiile nu sunt disponibile. Vă rugăm căutați din nou.'),
            'termsOutage' => $sxLbl('sphinx_holidays.terms_outage', 'Condițiile nu pot fi afișate momentan. Vă rugăm încercați din nou în câteva minute.'),
            'noTermsInfo' => $sxLbl('sphinx_holidays.no_terms_info', 'Nu există condiții specifice pentru această ofertă.'),
            'termsDueBy' => $sxLbl('sphinx_holidays.terms_due_by', 'De achitat'),
            'termsPenalty' => $sxLbl('sphinx_holidays.terms_penalty', 'Penalizare'),
            'termsNonRefundable' => $sxLbl('sphinx_holidays.terms_non_refundable', 'Nerambursabil'),
            'termsUntil' => $sxLbl('sphinx_holidays.terms_until', 'până la'),
            'termsFrom' => $sxLbl('sphinx_holidays.terms_from', 'de la'),
            'close' => $sxLbl('close', 'Close'),
            'available' => $sxLbl('sphinx_holidays.available', 'Available'),
            'room' => mb_strtolower($sxLbl('sphinx_holidays.room', 'room')),
            'rooms' => mb_strtolower($sxLbl('sphinx_holidays.rooms', 'rooms')),
            'offer' => mb_strtolower($sxLbl('sphinx_holidays.offer', 'offer')),
            'offers' => mb_strtolower($sxLbl('sphinx_holidays.offers', 'offers')),
        ],
    ], JSON_UNESCAPED_UNICODE));

    $view->assign('sphinx_hotel_name', $hotel_name);
    $view->assign('sphinx_search_results', []);
    $view->assign('sphinx_search_id', '');
    $view->assign('sphinx_search_status', 'idle');
    $view->assign('sphinx_from_price', null);

    if (empty($check_in)) {
        fn_set_notification(
            'W',
            __('warning'),
            __(
                'sphinx_holidays.please_fill_required_fields',
                ['[default]' => 'Please fill in the required search fields.'],
            ),
        );
        return;
    }

    // Per-room occupancy from the widget's rooms_data JSON (API spec:
    // occupancy is one {adults, children_ages} entry PER ROOM). The old
    // inline build replicated the totals into every room, dropped a lone
    // infant age "0" (!empty("0")), and sent an empty occupancy for rooms=0.
    $occupancy = \Tygh\Addons\SphinxHolidays\Helpers\SearchOccupancyResolver::resolve(
        RequestCoerce::string($_REQUEST, 'rooms_data'),
        $adults,
        $rooms,
        $children_ages_str,
    );

    $searchParams = [
        'check_in' => $check_in,
        'check_out' => $check_out,
        'occupancy' => $occupancy,
        'currency' => ConfigProvider::getDefaultCurrency(),
    ];

    // ── Search strategy ───────────────────────────────────────────────────
    // Product-page search (hotel_id present): query the API by the hotel's
    // destination_id, then narrow the results back to this hotel (initial
    // results below + search_poll's filter_hotel_id step).
    //
    // Sending hotel_ids does NOT work: verified against the live API, any
    // request carrying hotel_ids returns zero offers — even as an integer and
    // even alongside destination_id. Only a destination_id-only query runs the
    // live availability search. destination_id is always known for a synced
    // hotel (the hotels cron stores it on $hotelRow; findById() does SELECT *).
    //
    // Destination browse (no hotel_id): query by destination_id.
    if (!empty($hotel_id)) {
        $hotelDestinationId = $hotelRow !== null
            ? TypeCoerce::toInt($hotelRow['destination_id'] ?? 0)
            : 0;
        if ($hotelDestinationId <= 0) {
            $hotelDestinationId = $destination_id; // fall back to the request param
        }
        if ($hotelDestinationId > 0) {
            $searchParams['destination_id'] = $hotelDestinationId;
        }
    } elseif ($destination_id > 0) {
        $searchParams['destination_id'] = $destination_id;
    }

    $ignoreDomains = ConfigProvider::getIgnoreDomains();
    if (!empty($ignoreDomains)) {
        $searchParams['ignore_domains'] = $ignoreDomains;
    }

    // The API query matches $searchParams exactly. Because a product-page search
    // queries the whole destination, the client-side hotel_id narrowing (initial
    // results below + search_poll's filter_hotel_id step) is what reduces the
    // stream back to this hotel before display and caching.
    $apiSearchParams = $searchParams;

    // Cache key must be hotel-specific. The API params are destination-scoped and
    // identical for every hotel in the destination, but the cached results are
    // narrowed to one hotel, so two hotels in the same destination must not share
    // a cache entry. hotel_id goes into the KEY only — never into the API query.
    $cacheParams = $searchParams;
    if ($hotel_id !== '') {
        $cacheParams['filter_hotel_id'] = $hotel_id;
    }

    // Check cache — if hit, render results inline (no polling needed)
    $cacheEnabled = ConfigProvider::isApiCacheEnabled();
    $cacheTtl = ConfigProvider::getCacheTtlSearch();

    // refresh=1 (set by the booking form's offer-rejected redirect): the cached
    // set contained expired offer_ids — evict it and run a live search so the
    // customer gets bookable offers instead of the same stale cards.
    $forceRefresh = RequestCoerce::bool($_REQUEST, 'refresh');

    if ($cacheEnabled && $cacheTtl > 0) {
        $cacheKey = CacheService::buildSearchKey($cacheParams);
        if ($forceRefresh) {
            Container::getCacheService()->delete($cacheKey);
        }
        $cached = $forceRefresh ? null : Container::getCacheService()->get($cacheKey);
        if ($cached !== null) {
            $cachedMap = TypeCoerce::toStringMap($cached);
            $cachedResults = TypeCoerce::toRowList($cachedMap['results'] ?? []);
            // Heal the snapshots for a result set that was cached without them
            // — by a deploy that predates them, or by any path that skips the
            // write. Without this, a cache entry keeps serving cards whose
            // Book-now button falls back to the broken verify for the rest of
            // its TTL. Skipped entirely when they are already present.
            \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::rememberIfMissing($cachedResults, $cacheTtl);
            $view->assign('sphinx_search_results', $cachedResults);
            $view->assign('sphinx_search_id', TypeCoerce::toString($cachedMap['search_id'] ?? ''));
            $view->assign('sphinx_search_status', 'completed');
            SearchMetrics::record(SearchMetrics::EVENT_CACHE_HIT, [
                'hotel_id' => $hotel_id,
                'offers' => count($cachedResults),
                'elapsed_ms' => (int) round(microtime(true) * 1000) - $requestStartedMs,
            ]);
            return;
        }
    }

    // Instant "from" price: cache/hotels returns a cached minimum synchronously
    // (~1s, no cursor polling), shown in the loading state while the live offers
    // stream in. It is a general cached minimum (not the date-specific price), so
    // the template labels it "from". Only fetched on a cache miss (async path).
    $fromPriceMs = 0;
    if ($hotel_id !== '') {
        $fromPriceStart = microtime(true);
        $cacheResp = $api->cacheHotels(['hotel_ids' => [(int) $hotel_id]]);
        $fromPriceMs = (int) round((microtime(true) - $fromPriceStart) * 1000);
        if (is_array($cacheResp)) {
            $meta = TypeCoerce::toStringMap($cacheResp['meta'] ?? []);
            $stats = TypeCoerce::toStringMap($meta['stats'] ?? []);
            $minPrice = TypeCoerce::toStringMap($stats['min_price'] ?? []);
            $rawFrom = TypeCoerce::toFloat($minPrice['price'] ?? 0);
            if ($rawFrom > 0) {
                $view->assign('sphinx_from_price', [
                    'price' => Container::getCartService()->applyCommission($rawFrom),
                    'currency' => TypeCoerce::toString($minPrice['currency'] ?? ''),
                    'nights' => TypeCoerce::toInt($minPrice['nights'] ?? 0),
                ]);
            }
        }
    }

    // No cache — initiate async search, JS will poll for results
    $searchResponse = $api->searchHotels($apiSearchParams);

    // SphinxApi::searchHotels returns the canonical decoded shape — the
    // polling token is always under `cursor` (ResponseDecoder folds the
    // legacy top-level `search_id` into it).
    $searchToken = is_array($searchResponse)
        ? TypeCoerce::toString($searchResponse['cursor'] ?? '')
        : '';

    if ($searchToken === '') {
        $httpClient = $api->getHttpClient();
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx searchHotels returned no search token (cursor/search_id)',
            'http_code' => $httpClient->getLastHttpCode(),
            'api_error' => $httpClient->getLastError(),
            'raw_response' => substr($httpClient->getLastResponseRaw() ?? '', 0, 500),
            'search_params' => $searchParams,
        ]);
        fn_set_notification(
            'E',
            __('error'),
            __('sphinx_holidays.search_error', ['[default]' => 'Search failed. Please try again.']),
        );
        // 'error' (NOT the initial 'idle'): the template renders the
        // no-results block only for completed/error and the skeleton/poller
        // only for pending — leaving 'idle' here rendered a completely BLANK
        // results area, hiding the failure from the customer.
        $view->assign('sphinx_search_results', []);
        $view->assign('sphinx_search_status', 'error');
        return;
    }

    $searchIdStr = $searchToken;
    $initialStatus = TypeCoerce::toString($searchResponse['status'] ?? 'pending');
    $initialResults = TypeCoerce::toRowList($searchResponse['results'] ?? []);

    // Narrow to the requested hotel when this is a product-page (hotel_id)
    // search — searching by destination can return the whole destination.
    if ($hotel_id !== '' && !empty($initialResults)) {
        $initialResults = array_values(array_filter(
            $initialResults,
            static fn (array $r): bool => TypeCoerce::toString($r['hotel_id'] ?? $r['id'] ?? '') === $hotel_id,
        ));
    }

    // Show only offers with immediate availability (confirmation=immediate).
    // Filter raw offers here, before flatten/commission/cache, so the field is
    // still present and cached entries store the filtered set.
    if (ConfigProvider::shouldRequireImmediateAvailability() && !empty($initialResults)) {
        $preFilterResults = $initialResults;
        $initialResults = OfferAvailability::filterImmediate($initialResults);
        if ($initialResults === [] ) {
            // Filter emptied a non-empty initial set: without this line the
            // customer-facing "no availability" would be indistinguishable
            // from a genuinely empty API response in Administration → Logs.
            $confirmations = [];
            foreach ($preFilterResults as $droppedOffer) {
                $confirmations[TypeCoerce::toString($droppedOffer['confirmation'] ?? '(missing)')] = true;
            }
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx search: immediate-availability filter dropped ALL initial offers',
                'hotel_id' => $hotel_id,
                'dropped' => count($preFilterResults),
                'confirmation_values' => implode(', ', array_keys($confirmations)),
            ]);
        }
    }

    // If the API returns final results synchronously, render inline and skip polling.
    if ($initialStatus === 'completed' && !empty($initialResults)) {
        // Flatten the nested API offer shape (pricing.selling_price,
        // meal_type_name, rooms[]) to the flat keys the template expects.
        $initialResults = SearchOfferNormalizer::flattenAll($initialResults);
        $cartService = Container::getCartService();
        foreach ($initialResults as &$result) {
            if (isset($result['price'])) {
                $result['original_price'] = $result['price'];
                $result['price'] = $cartService->applyCommission(TypeCoerce::toFloat($result['price']));
            }
        }
        unset($result);

        if ($cacheEnabled && $cacheTtl > 0) {
            Container::getCacheService()->set(CacheService::buildSearchKey($cacheParams), [
                'results' => $initialResults,
                'search_id' => $searchIdStr,
                'complete' => true,
            ], $cacheTtl);
            // Per-offer snapshots, same TTL: the booking form is reached by URL
            // and cannot trust a price from it, so this is where a server-owned
            // price comes from when an offer says must_verify=false and does
            // not need the (currently 500ing) verify round trip.
            \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::remember($initialResults, $cacheTtl);
        }

        $view->assign('sphinx_search_results', $initialResults);
        $view->assign('sphinx_search_id', $searchIdStr);
        $view->assign('sphinx_search_status', 'completed');
        SearchMetrics::record(SearchMetrics::EVENT_SYNC_COMPLETE, [
            'hotel_id' => $hotel_id,
            'offers' => count($initialResults),
            'from_price_ms' => $fromPriceMs,
        ]);
        return;
    }

    // Diagnostic: log when the API immediately reports no availability
    // (helps differentiate "API has no offers" from "polling lost results").
    if ($initialStatus === 'completed' && empty($initialResults)) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx search returned completed with zero results',
            'search_params' => $searchParams,
            'api_response' => json_encode($searchResponse),
        ]);
    }

    // Store search params in session so search_poll can cache completed results
    /** @var array<string, mixed> $session */
    $session = &Tygh::$app['session'];
    $session['sphinx_search_' . $searchIdStr] = [
        'params' => $searchParams,
        'cache_key' => $cacheEnabled && $cacheTtl > 0 ? CacheService::buildSearchKey($cacheParams) : '',
        'cache_ttl' => $cacheTtl,
        'filter_hotel_id' => $hotel_id,
        // For the alternative-date probe on terminal-empty polls:
        'destination_id' => TypeCoerce::toInt($searchParams['destination_id'] ?? 0),
        'check_in' => $check_in,
        'nights' => TypeCoerce::toInt($templateParams['nights']),
        'adults' => $adults,
        // P0b metrics: search start + poll counter, read back by search_poll to
        // time first-offer/complete. docs/adr/0001-availability-early-render-and-metrics.md
        'started_at_ms' => $requestStartedMs,
        'poll_index' => 0,
    ];

    SearchMetrics::record(SearchMetrics::EVENT_SEARCH_START, [
        'hotel_id' => $hotel_id,
        'from_price_ms' => $fromPriceMs,
        'cache' => 'miss',
    ]);

    $view->assign('sphinx_search_id', $searchIdStr);
    $view->assign('sphinx_search_status', 'pending');
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Search Error: ' . $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification(
        'E',
        __('error'),
        __(
            'sphinx_holidays.search_error',
            ['[default]' => 'An error occurred while searching. Please try again later.'],
        ),
    );

    /** @var \Smarty $errorView */
    $errorView = Tygh::$app['view'];
    $errorView->assign('sphinx_search_results', []);
    $errorView->assign('sphinx_search_status', 'error');
}
