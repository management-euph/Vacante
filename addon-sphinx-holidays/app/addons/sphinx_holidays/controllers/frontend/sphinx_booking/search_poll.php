<?php

declare(strict_types=1);

/**
 * Sphinx Booking Controller — Search Poll Mode
 *
 * AJAX endpoint called by the search results page JS to fetch
 * incremental hotel search results. Replaces the previous
 * synchronous server-side polling loop.
 *
 * Request: GET sphinx_booking.search_poll?search_id=X&cursor=Y&nights=N
 * Response: JSON {status: pending|completed, results: [...], next_cursor: string|null}
 *
 * @package SphinxHolidays
 * @since   1.3.0
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Helpers\SearchMetrics;
use Tygh\Addons\SphinxHolidays\Helpers\OfferLocation;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\AlternativeDateProber;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

header('Content-Type: application/json; charset=utf-8');

try {
    $searchId = RequestCoerce::string($_REQUEST, 'search_id');
    $cursor = RequestCoerce::string($_REQUEST, 'cursor');
    $finalize = RequestCoerce::bool($_REQUEST, 'finalize');

    if ($searchId === '') {
        echo json_encode(['status' => 'error', 'error' => 'Missing search_id']);
        exit;
    }

    $api = Container::getApi();
    // $searchId carries the opaque polling token from search.php (a `cursor` JWT
    // under the current API). Poll by cursor — use the evolving cursor the client
    // sends on follow-up polls, otherwise the initial token. Mirrors the proven
    // board-discovery poll loop (DiscoverBoardsCommand::pollResults).
    $pollResponse = $api->getHotelResults('', $cursor !== '' ? $cursor : $searchId);

    if ($pollResponse === null) {
        echo json_encode(['status' => 'error', 'error' => 'API returned null']);
        exit;
    }

    // Canonical decoded shape: the list is always under `results`.
    $results = TypeCoerce::toRowList($pollResponse['results'] ?? []);
    $status = TypeCoerce::toString($pollResponse['status'] ?? 'completed');

    // Narrow to the requested hotel for product-page searches. search.php stores
    // the hotel_id in the search session meta; because we search by destination
    // the API can return the whole destination, so drop other hotels' offers
    // before commission/slimming. No-op when the API already filtered by hotel.
    // CS-Cart's session is an ArrayAccess container object; a plain (non-reference)
    // local binds the same object handle, so offset reads/writes below operate on
    // the live session exactly as direct `Tygh::$app['session'][...]` access would.
    $session = Tygh::$app['session'];
    if (!is_array($session) && !$session instanceof \ArrayAccess) {
        $session = [];
    }
    $searchMeta = TypeCoerce::toStringMap($session['sphinx_search_' . $searchId] ?? null);
    $filterHotelId = TypeCoerce::toString($searchMeta['filter_hotel_id'] ?? '');
    if ($filterHotelId !== '' && !empty($results)) {
        $results = array_values(array_filter(
            $results,
            // Match on hotel_id, falling back to id: the results endpoint is
            // not always consistent about which key carries the hotel id, and
            // matching the wrong key drops every offer for the hotel.
            static fn (array $r): bool => TypeCoerce::toString($r['hotel_id'] ?? $r['id'] ?? '') === $filterHotelId,
        ));
    }

    // P0b metrics: time this poll against the search start stamped by search.php
    // and track the poll index across requests (both live in the session meta).
    // docs/adr/0001-availability-early-render-and-metrics.md
    $startedAtMs = TypeCoerce::toInt($searchMeta['started_at_ms'] ?? 0);
    $pollIndex = TypeCoerce::toInt($searchMeta['poll_index'] ?? 0) + 1;
    $elapsedMs = $startedAtMs > 0 ? (int) round(microtime(true) * 1000) - $startedAtMs : 0;

    // Canonical decoded shape: the continuation token is always under
    // `cursor` (ResponseDecoder folds next_cursor|cursor|search_id), null
    // when the search is exhausted.
    $rawCursor = $pollResponse['cursor'] ?? null;
    $nextCursor = $rawCursor === null ? null : TypeCoerce::toString($rawCursor);

    // Diagnostic: log when the poll completes with zero results, so the
    // CS-Cart event log shows whether the API genuinely has no availability
    // or returned an unexpected shape we failed to parse.
    if ($status === 'completed' && empty($results) && $cursor === '') {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx search_poll completed with zero results',
            'search_id' => $searchId,
            'api_response' => json_encode($pollResponse),
        ]);
    }

    // Show only offers with immediate availability (confirmation=immediate).
    // Filter the raw offers before flatten/commission/slim/cache so downstream
    // consumers (and the cached set) contain only immediately-bookable offers.
    if (ConfigProvider::shouldRequireImmediateAvailability() && !empty($results)) {
        $beforeCount = count($results);
        $droppedConfirmations = [];
        $results = OfferAvailability::filterImmediate($results);
        $droppedCount = $beforeCount - count($results);
        if ($droppedCount > 0 && count($results) === 0) {
            // The filter emptied a non-empty batch — the ONE case where the
            // customer sees "no availability" although the API returned
            // offers. Log UNCONDITIONALLY (the zero-results diagnostic above
            // runs pre-filter and stays silent here), naming the confirmation
            // values dropped so Administration → Logs states the cause
            // outright. require_immediate_availability is the relaxing lever.
            foreach (TypeCoerce::toRowList($pollResponse['results'] ?? []) as $droppedOffer) {
                $droppedConfirmations[TypeCoerce::toString($droppedOffer['confirmation'] ?? '(missing)')] = true;
            }
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx search_poll: immediate-availability filter dropped ALL offers in this batch',
                'search_id' => $searchId,
                'dropped' => $droppedCount,
                'confirmation_values' => implode(', ', array_keys($droppedConfirmations)),
            ]);
        } elseif ($droppedCount > 0 && ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx search_poll filtered non-immediate offers',
                'search_id' => $searchId,
                'kept' => count($results),
                'dropped' => $droppedCount,
            ]);
        }
    }

    // The Sphinx search results endpoint returns a nested offer shape
    // (pricing.selling_price, meal_type_name, rooms[], destination_name).
    // Flatten each offer to the keys the commission step, slimming list and
    // template expect (price, currency, board_name, room_name, destination) —
    // otherwise every offer renders with a 0,00 price and blank room/board.
    $results = SearchOfferNormalizer::flattenAll($results);
    // The offer names only the resort/city: add the hotel's country.
    $results = OfferLocation::applyCountry(
        $results,
        Container::getHotelRepository()->findCountryNames(OfferLocation::hotelIds($results)),
    );

    // Apply commission to each result
    $cartService = Container::getCartService();
    foreach ($results as &$result) {
        if (isset($result['price'])) {
            $result['original_price'] = $result['price'];
            $result['price'] = $cartService->applyCommission(TypeCoerce::toFloat($result['price']));
        }
        if (!empty($result['offers'])) {
            $offers = TypeCoerce::toRowList($result['offers']);
            foreach ($offers as &$offer) {
                if (isset($offer['price'])) {
                    $offer['original_price'] = $offer['price'];
                    $offer['price'] = $cartService->applyCommission(TypeCoerce::toFloat($offer['price']));
                }
            }
            unset($offer);
            $result['offers'] = $offers;
        }
    }
    unset($result);

    // Strip to display-relevant fields only (API payloads can be multi-MB)
    $templateFields = [
        'offer_id', 'hotel_id', 'product_id',
        'hotel_name', 'hotel_image', 'star_rating', 'destination',
        'room_name', 'room_type', 'room_lines', 'board_name', 'board_type',
        'price', 'original_price', 'currency', 'confirmation',
    ];
    $slimResults = [];
    foreach ($results as $result) {
        $slim = [];
        foreach ($templateFields as $f) {
            if (isset($result[$f])) {
                $slim[$f] = $result[$f];
            }
        }
        $slimResults[] = $slim;
    }

    // Accumulate this hotel's offers across polls in the session (a per-user,
    // in-flight buffer). The cross-user cache is written ONLY when the stream
    // completes (cursor exhausted) or the client finalizes — never mid-stream —
    // so a concurrent visitor can never read a half-filled set as authoritative.
    // The client renders offers as they arrive but keeps polling to the end to
    // drive this completion. docs/adr/0001-availability-early-render-and-metrics.md
    $accumulated = TypeCoerce::toRowList($session['sphinx_results_' . $searchId] ?? null);
    $priorCount = count($accumulated);
    $accumulated = array_merge($accumulated, $slimResults);
    $offerCount = count($accumulated);
    $endOfStream = $nextCursor === null || $nextCursor === '';
    $terminal = $endOfStream || $finalize;

    // P0b: first poll that produced this hotel's offers, and stream completion.
    if ($priorCount === 0 && $offerCount > 0) {
        SearchMetrics::record(SearchMetrics::EVENT_FIRST_OFFER, [
            'search_id' => $searchId,
            'hotel_id' => $filterHotelId,
            'elapsed_ms' => $elapsedMs,
            'poll' => $pollIndex,
            'offers' => $offerCount,
        ]);
    }
    if ($terminal) {
        SearchMetrics::record(SearchMetrics::EVENT_COMPLETE, [
            'search_id' => $searchId,
            'hotel_id' => $filterHotelId,
            'elapsed_ms' => $elapsedMs,
            'polls' => $pollIndex,
            'offers' => $offerCount,
        ]);
    }

    // Terminal poll with zero offers for a specific hotel: probe nearby date
    // windows so the no-results panel can offer "try these dates instead"
    // links (novoton's alternative-dates UX). Cached briefly per
    // hotel+dates so page refreshes don't re-probe the API.
    $alternatives = [];
    if ($terminal && $offerCount === 0 && $filterHotelId !== '') {
        $altDestinationId = TypeCoerce::toInt($searchMeta['destination_id'] ?? 0);
        $altCheckIn = TypeCoerce::toString($searchMeta['check_in'] ?? '');
        $altNights = TypeCoerce::toInt($searchMeta['nights'] ?? 0);
        $altAdults = TypeCoerce::toInt($searchMeta['adults'] ?? 2);

        if ($altDestinationId > 0 && $altCheckIn !== '' && $altNights > 0) {
            $altCacheKey = 'alt:v1:' . md5((string) json_encode([
                $altDestinationId, $filterHotelId, $altCheckIn, $altNights, $altAdults,
            ]));
            $cachedAlts = Container::getCacheService()->get($altCacheKey);
            if (is_array($cachedAlts)) {
                $alternatives = TypeCoerce::toRowList($cachedAlts['alternatives'] ?? []);
            } else {
                try {
                    $prober = new AlternativeDateProber($api);
                    $currencyForAlts = ConfigProvider::getDefaultCurrency();
                    foreach ($prober->probe($altDestinationId, $filterHotelId, $altCheckIn, $altNights, $altAdults, $currencyForAlts) as $alt) {
                        $alternatives[] = [
                            'check_in' => $alt['check_in'],
                            'check_out' => $alt['check_out'],
                            'price' => round($cartService->applyCommission($alt['price']), 2),
                            'currency' => $currencyForAlts,
                        ];
                    }
                } catch (\Throwable $altError) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx alternative-date probe failed: ' . $altError->getMessage(),
                    ]);
                }
                Container::getCacheService()->set($altCacheKey, ['alternatives' => $alternatives], 600);
            }
        }
    }

    // Never cache an empty set, so a hotel with no availability is not cached as
    // "no offers". `complete` marks the entry as the full, authoritative set.
    if ($terminal && !empty($accumulated) && !empty($searchMeta['cache_key']) && !empty($searchMeta['cache_ttl'])) {
        Container::getCacheService()->set(TypeCoerce::toString($searchMeta['cache_key']), [
            'results' => $accumulated,
            'search_id' => $searchId,
            'complete' => true,
        ], TypeCoerce::toInt($searchMeta['cache_ttl']));
        // Same per-offer snapshots as the synchronous path in search.php — the
        // polled path is the one most searches actually take, so omitting it
        // here would leave the booking form with nothing to trust.
        \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::remember(
            $accumulated,
            TypeCoerce::toInt($searchMeta['cache_ttl']),
        );
    }

    if ($terminal) {
        unset($session['sphinx_results_' . $searchId]);
        unset($session['sphinx_search_' . $searchId]);
    } else {
        $session['sphinx_results_' . $searchId] = $accumulated;
        // Persist the incremented poll index for the next poll's metrics. Write
        // the whole meta back — the Session container returns values by copy, so
        // a nested write would not stick.
        $meta = $session['sphinx_search_' . $searchId] ?? [];
        if (is_array($meta)) {
            $meta['poll_index'] = $pollIndex;
            $session['sphinx_search_' . $searchId] = $meta;
        }
    }

    echo json_encode([
        'status' => $status,
        'results' => $slimResults,
        'next_cursor' => $nextCursor,
        'alternatives' => $alternatives,
    ]);
    exit;
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx search_poll error: ' . $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    echo json_encode(['status' => 'error', 'error' => 'Internal error']);
    exit;
}
