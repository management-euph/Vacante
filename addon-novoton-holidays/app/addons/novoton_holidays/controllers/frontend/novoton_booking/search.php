<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Search Mode
 *
 * Thin orchestration layer that delegates to:
 *   - SearchParameterNormalizer  (input parsing & validation)
 *   - HotelAvailabilitySearcher  (API calls & result processing)
 *   - AlternativeDateSearcher    (fallback when primary search is empty)
 *   - SearchResultFormatter      (template variable assignment)
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\HotelAvailabilitySearcher;
use Tygh\Addons\NovotonHolidays\Services\AlternativeDateSearcher;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilityRecorder;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

try {

    $container  = Container::getInstance();
    $formatter  = $container->searchResultFormatter();

    // ── 1. Validate & normalize input ────────────────────────────────
    $security     = _nvt_get_security_service();
    $searchParams = $security->validateSearchParams(TypeCoerce::toStringMap($_REQUEST));

    $normalizer = $container->searchParameterNormalizer();
    $query      = $normalizer->normalizeAsDto($searchParams);
    $novotonParams = $query->toNovotonParamsArray();

    // ── 2. Early return: no check-in date ────────────────────────────
    if ($query->checkIn === '') {
        $formatter->assignDefaults('novoton_holidays.please_fill_required_fields');
        return;
    }

    // ── 3. Debug mode (server-side config only) ──────────────────────
    $debug = ConfigProvider::isDebugMode();

    // ── 4. Hotel-specific search ─────────────────────────────────────
    if ($query->isHotelScoped()) {

        $searchSvc    = $container->searchService();
        $searcher     = new HotelAvailabilitySearcher($searchSvc, $debug);
        $searchResult = $searcher->search($novotonParams);
        $debugLog     = $searcher->getDebugLog();

        $results      = $searchResult['results'];
        $altResult    = ['results' => [], 'check_in' => '', 'check_out' => ''];

        // Offers a customer's search found are evidence for the hotel
        // availability records; an empty answer proves nothing.
        $availabilityRecorder = new NovotonAvailabilityRecorder();
        $availabilityRecorder->searchFound($query->hotelId, $query->checkIn, $results, date('Y-m-d H:i:s'));

        // ── 5. Alternative dates if no availability ──────────────────
        if ($searchResult['no_availability']) {
            $rooms      = $searcher->getRooms($query->hotelId);
            $boardTypes = $searcher->getBoardTypes($query->hotelId, $query->mealPlan);

            $altSearcher = new AlternativeDateSearcher($debug);
            $altResult   = $altSearcher->search(
                $query->hotelId,
                $query->checkIn,
                $query->nights,
                $query->totalAdults,
                $query->childrenAges,
                $query->flexDays,
                TypeCoerce::toStringMap($rooms),
                TypeCoerce::toStringMap($boardTypes)
            );
            $debugLog = array_merge($debugLog, $altSearcher->getDebugLog());
            $availabilityRecorder->searchFound($query->hotelId, $altResult['check_in'], $altResult['results'], date('Y-m-d H:i:s'));
        }

        // ── 5b. Pre-render booking engine as pure PHP string ────────────
        // fn_travel_core_render_booking_engine() builds the React mount HTML
        // entirely in PHP — zero Smarty involvement, zero scope chain traversal.
        // This prevents the 256MB OOM at Data.php:265 that occurs when Smarty 5
        // traverses the view's accumulated scope during {include} or fetch().
        $view = fn_novoton_holidays_get_view();
        $view->assign('booking_engine_html', fn_travel_core_render_booking_engine([
            'provider'        => 'novoton',
            'search_dispatch' => 'novoton_booking.search',
            'mode'            => 'search',
            'search_params'   => $novotonParams,
        ]));

        // ── 6. Assign everything to the template ─────────────────────
        $formatter->assignToView(
            $results,
            $novotonParams,
            $searchResult,
            $altResult,
            $searchParams,
            TypeCoerce::toStringMap($debugLog)
        );

    } else {
        // ── Homepage search → redirect to product search ─────────────
        $destination = $searchParams['destination'] ?? '';
        $searchQuery = $searchParams['q'] ?? '';

        $redirect_params = [
            'q'                 => $searchQuery ?: $destination,
            'novoton_check_in'  => $query->checkIn,
            'novoton_check_out' => $query->checkOut,
            'novoton_adults'    => $query->totalAdults,
        ];

        return [CONTROLLER_STATUS_REDIRECT, 'products.search?' . http_build_query($redirect_params)];
    }

} catch (\Throwable $e) {
    // ── Error boundary ───────────────────────────────────────────────
    fn_log_event('general', 'runtime', [
        'message' => 'Novoton Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
        'trace'   => $e->getTraceAsString(),
    ]);

    fn_set_notification('E', __('error'),
        __('novoton_holidays.search_error',
            ['[default]' => 'An error occurred while searching. Please try again later.']
        )
    );

    $formatter = Container::getInstance()->searchResultFormatter();
    $formatter->assignDefaults();

    if (ConfigProvider::isDebugMode()) {
        fn_novoton_holidays_get_view()->assign('novoton_debug', [
            '=== SEARCH ERROR ===',
            $e->getMessage(),
            $e->getFile() . ':' . $e->getLine(),
            $e->getTraceAsString(),
        ]);
    }
}
