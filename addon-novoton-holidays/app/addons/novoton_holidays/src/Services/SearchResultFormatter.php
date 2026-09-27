<?php

declare(strict_types=1);

/**
 * Prepares the complete set of Smarty template variables for the search page.
 *
 * Loads hotel display info, extracts terms / early-booking details, handles
 * currency, and sets page meta / SEO / breadcrumbs.  Also provides a
 * safe-defaults method for error/empty-state rendering.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CurrencyService;

class SearchResultFormatter implements SearchResultFormatterInterface
{
    /**
     * Assign all search-result template variables.
     *
     * @param list<array<string, mixed>> $results Primary result rows
     * @param array<string, mixed> $novotonParams Template params (from normalizer)
     * @param array<string, mixed> $searchResult Output from HotelAvailabilitySearcher::search()
     * @param array<string, mixed> $altResult Output from AlternativeDateSearcher::search()
     * @param array<string, mixed> $searchParams Raw (sanitized) request params
     * @param array<string, mixed> $debugLog Debug lines (empty when debug is off)
     */
    #[\Override]
    public function assignToView(
        array $results,
        array $novotonParams,
        array $searchResult,
        array $altResult,
        array $searchParams,
        array $debugLog,
    ): void {
        $view = fn_novoton_holidays_get_view();

        // ── Core results ─────────────────────────────────────────────
        $view->assign('novoton_results', $results);
        $view->assign('novoton_params', $novotonParams);

        // ── Currency display ─────────────────────────────────────────
        $this->assignCurrency($view);

        // ── Multi-room data ──────────────────────────────────────────
        if (TypeCoerce::toBool($searchResult['is_multi_room'])) {
            $view->assign('all_room_results', $searchResult['all_room_results']);
            $view->assign('is_multi_room_search', true);
            $view->assign('multi_room_total_options', $searchResult['multi_room_total_options']);
        }

        $view->assign('max_room_capacity', $searchResult['max_room_capacity'] ?? [
            'adults' => 2, 'children' => 2, 'total' => 4,
        ]);

        // ── Early-booking discounts ──────────────────────────────────
        $view->assign('early_booking_discounts', $searchResult['early_booking_discounts'] ?? []);
        $view->assign('early_booking_range', $searchResult['early_booking_range'] ?? []);

        // ── Alternative dates ────────────────────────────────────────
        $noAvailability = $searchResult['no_availability'] ?? empty($results);
        $view->assign('alternative_results', $altResult['results'] ?? []);
        $view->assign('alternative_check_in', $altResult['check_in'] ?? '');
        $view->assign('alternative_check_out', $altResult['check_out'] ?? '');
        $view->assign('no_availability_message', $noAvailability);
        $view->assign('flex_days', $novotonParams['flex_days'] ?? 0);
        $view->assign(
            'flex_dates_searched',
            (($novotonParams['flex_days'] ?? 0) > 0 && !empty($altResult['check_in'])),
        );

        // ── Hotel display info ───────────────────────────────────────
        $hotelId = PriceInfoFormatter::toScalar($novotonParams['hotel_id'] ?? '');
        $productId = PriceInfoFormatter::toInt($novotonParams['product_id'] ?? 0);
        $this->assignHotelDisplay($view, $hotelId, $productId);

        // ── Hotel URL ────────────────────────────────────────────────
        $view->assign('hotel_url', !empty($productId)
            ? fn_url('products.view?product_id=' . $productId)
            : '');

        // ── Calendar prices (for datepicker in search form) ─────────
        $this->assignCalendarPrices($view, $hotelId);

        // ── Debug ────────────────────────────────────────────────────
        if (!empty($debugLog)) {
            $view->assign('novoton_debug', $debugLog);
        }

        // ── Page meta / SEO ──────────────────────────────────────────
        $this->assignMeta($view);
    }

    /**
     * Assign safe defaults so the template renders without secondary errors.
     * Used on early-return (no check-in) and in the error boundary.
     */
    #[\Override]
    public function assignDefaults(?string $warningLangKey = null): void
    {
        $view = fn_novoton_holidays_get_view();
        $translated = __('novoton_holidays.search_results');
        $pageTitle = is_string($translated) && $translated !== '' ? $translated : 'Search Results';

        $view->assign('novoton_results', []);
        $view->assign('novoton_params', [
            'check_in' => '', 'check_out' => '', 'nights' => 7,
            'adults' => 2, 'children' => [], 'children_count' => 0,
            'children_ages' => '', 'children_ages_str' => '',
            'children_ages_array' => [], 'num_rooms' => 1,
            'rooms_data' => [], 'rooms_data_json' => '[]',
            'flex_days' => 0,
            'meal_plan' => __('novoton_holidays.all_boards') ?: 'All Boards',
            'hotel_id' => '', 'product_id' => 0,
        ]);
        $view->assign('alternative_results', []);
        $view->assign('alternative_check_in', '');
        $view->assign('alternative_check_out', '');
        $view->assign('no_availability_message', true);
        $view->assign('hotel_name', '');
        $view->assign('hotel_city', '');
        $view->assign('hotel_country', '');
        $view->assign('hotel_region', '');
        $view->assign('hotel_lat', 0.0);
        $view->assign('hotel_lng', 0.0);
        $view->assign('hotel_package_name', '');
        $view->assign('hotel_url', '');
        $view->assign('terms_of_payment', '');
        $view->assign('terms_of_cancellation', '');
        $view->assign('early_booking_details', '');
        $view->assign('page_title', $pageTitle);

        $this->assignMeta($view);

        if ($warningLangKey !== null && $warningLangKey !== '') {
            fn_set_notification(
                'W',
                __('warning'),
                __($warningLangKey) ?: 'Please fill in required search fields',
            );
        }
    }

    // =====================================================================
    // Internal helpers
    // =====================================================================

    /** @param \Smarty $view */
    private function assignCurrency($view): void
    {
        $currency = defined('CART_SECONDARY_CURRENCY') ? TypeCoerce::toString(CART_SECONDARY_CURRENCY) : 'EUR';
        $currencies = ConfigProvider::getCurrencies();
        $entry = is_array($currencies[$currency] ?? null) ? $currencies[$currency] : [];
        $coefficient = is_numeric($entry['coefficient'] ?? null) ? (float) $entry['coefficient'] : 1.0;
        $symbol = is_string($entry['symbol'] ?? null) && $entry['symbol'] !== '' ? $entry['symbol'] : $currency;

        $view->assign('novoton_display_currency', $currency);
        $view->assign('novoton_display_coefficient', $coefficient);
        $view->assign('novoton_display_symbol', $symbol);
        $view->assign('novoton_round_prices', ConfigProvider::isRoundPrices());
    }

    /** @param \Smarty $view */
    private function assignHotelDisplay($view, string $hotelId, int $productId): void
    {
        $hotelName = '';
        $hotelCity = '';
        $hotelRegion = '';
        $hotelCountry = '';
        $hotelLat = 0.0;
        $hotelLng = 0.0;
        $hotelStars = '';

        if (!empty($hotelId)) {
            $hotelRepo = Container::getInstance()->hotelRepository();
            $hotelInfo = $hotelRepo->findBasicById($hotelId);

            if ($hotelInfo !== null) {
                $hotelName = $hotelInfo['hotel_name'] ?? '';
                $hotelCity = $hotelInfo['city'] ?? '';
                $hotelRegion = $hotelInfo['region'] ?? '';
                $hotelCountry = $hotelInfo['country'] ?? '';
                $hotelLat = TypeCoerce::toFloat($hotelInfo['latitude'] ?? 0);
                $hotelLng = TypeCoerce::toFloat($hotelInfo['longitude'] ?? 0);
                // Gold ★ glyphs (styled by .travel-hotel-stars) — the search
                // header previously showed a hardcoded "****" placeholder
                // because no rating was ever assigned.
                $hotelStarCount = min(5, max(0, TypeCoerce::toInt($hotelInfo['star_rating'] ?? 0)));
                $hotelStars = str_repeat('★', $hotelStarCount);

                // Fetch packages once, reuse across sub-methods
                $packageRepo = Container::getInstance()->hotelPackageRepository();
                $packages = $packageRepo->findByHotelIdFull($hotelId);

                $this->assignPackages($view, $packages);
                $this->assignActiveEarlyBooking($view, $packages);
                $this->assignSeasonPeriod($view, $packages);
            } else {
                $view->assign('hotel_package_name', '');
            }

            // Room-level facilities
            $lang_code = TypeCoerce::toString(CART_LANGUAGE);
            $roomFacilities = fn_novoton_holidays_get_hotel_facilities_by_type($hotelId, \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_ROOM_FACILITY, $lang_code);
            $view->assign('novoton_room_facilities', $roomFacilities);
        }

        // Fallback: product name
        if (empty($hotelName) && !empty($productId)) {
            $hotelName = TypeCoerce::toString(db_get_field(
                'SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s',
                $productId,
                CART_LANGUAGE,
            ));
        }
        if (empty($hotelName)) {
            $hotelName = !empty($hotelId) ? 'Hotel #' . $hotelId : '';
        }

        $view->assign('hotel_name', $hotelName);
        $view->assign('hotel_city', $hotelCity);
        $view->assign('hotel_region', $hotelRegion);
        $view->assign('hotel_country', $hotelCountry);
        $view->assign('hotel_stars', $hotelStars);
        $view->assign('hotel_lat', $hotelLat);
        $view->assign('hotel_lng', $hotelLng);

        // The hotel-header view model is gone from this surface: results
        // render inline on the product page (or headerless standalone), so
        // search.tpl no longer includes the shared hotel_header component.
    }

    /**
     * @param \Smarty $view
     * @param list<array<string, mixed>> $packages
     */
    private function assignPackages($view, array $packages): void
    {
        if (empty($packages)) {
            $view->assign('hotel_package_name', '');
            $view->assign('hotel_all_packages', []);
            return;
        }

        // Use first non-bracketed package name, or just the first
        $packageName = '';
        foreach ($packages as $pkg) {
            $pname = PriceInfoFormatter::toScalar($pkg['package_name'] ?? '');
            if (!empty($pname) && !str_ends_with($pname, ']')) {
                $packageName = $pname;
                break;
            }
        }
        if (empty($packageName) && !empty($packages[0])) {
            $packageName = PriceInfoFormatter::toScalar($packages[0]['package_name'] ?? '');
        }

        $view->assign('hotel_package_name', $packageName);
        $view->assign('hotel_all_packages', $packages);
    }

    /**
     * @param \Smarty $view
     * @param list<array<string, mixed>> $packages
     */
    private function assignActiveEarlyBooking($view, array $packages): void
    {
        $currentDate = date('Y-m-d');
        $activeEb = null;

        foreach ($packages as $pkg) {
            if (($pkg['has_early_booking'] ?? 'N') !== 'Y' || empty($pkg['priceinfo_data'])) {
                continue;
            }

            $priceinfo = json_decode(PriceInfoFormatter::toScalar($pkg['priceinfo_data']), true);
            if (!is_array($priceinfo) || empty($priceinfo['early_booking'])) {
                continue;
            }

            $ebData = $priceinfo['early_booking'];
            if (!is_array($ebData)) {
                continue;
            }
            if (isset($ebData['Reduction'])) {
                $ebData = [$ebData];
            }

            foreach ($ebData as $eb) {
                if (!is_array($eb)) {
                    continue;
                }
                $bookFrom = PriceInfoFormatter::toScalar($eb['BookFrom'] ?? '');
                $bookTo = PriceInfoFormatter::toScalar($eb['BookTo'] ?? '');
                if ($bookFrom <= $currentDate && $bookTo >= $currentDate) {
                    $activeEb = [
                        'reduction' => $eb['Reduction'] ?? 0,
                        'booking_from' => $bookFrom,
                        'booking_to' => $bookTo,
                        'stay_from' => PriceInfoFormatter::toScalar($eb['StayFrom'] ?? ''),
                        'stay_to' => PriceInfoFormatter::toScalar($eb['StayTo'] ?? ''),
                        'payment_date' => PriceInfoFormatter::toScalar($eb['PaymentDate'] ?? ''),
                        'payment_percent' => $eb['PaymentPercent'] ?? 0,
                        'room_types' => $eb['RoomTypes'] ?? 'all',
                        'min_stay' => $eb['MinStay'] ?? 0,
                    ];
                    break 2;
                }
            }
        }

        if ($activeEb !== null) {
            $view->assign('active_early_booking', $activeEb);
        }
    }

    /**
     * @param \Smarty $view
     * @param list<array<string, mixed>> $packages
     */
    private function assignSeasonPeriod($view, array $packages): void
    {
        $seasonFrom = '';
        $seasonTo = '';

        foreach ($packages as $pkg) {
            if (empty($pkg['priceinfo_data'])) {
                continue;
            }
            $pi = json_decode(PriceInfoFormatter::toScalar($pkg['priceinfo_data']), true);
            if (!is_array($pi) || !is_array($pi['seasons'] ?? null) || empty($pi['seasons']['season'])) {
                continue;
            }

            $piSeasons = $pi['seasons'];
            $seasons = $piSeasons['season'];
            if (!is_array($seasons)) {
                continue;
            }
            if (isset($seasons['IdSeason']) || isset($seasons['DateFrom'])) {
                $seasons = [$seasons];
            }

            $first = reset($seasons);
            $last = end($seasons);
            if (!is_array($first) || !is_array($last)) {
                continue;
            }
            $seasonFrom = PriceInfoFormatter::toScalar($first['DateFrom'] ?? $first['FromDate'] ?? '');
            $seasonTo = PriceInfoFormatter::toScalar($last['DateTo'] ?? $last['ToDate'] ?? '');
            break;
        }

        $view->assign('hotel_season_from', $seasonFrom);
        $view->assign('hotel_season_to', $seasonTo);
    }

    /** @param \Smarty $view */
    private function assignCalendarPrices($view, string $hotelId): void
    {
        $calendarPricesJson = '{}';
        $calendarPricesCurrency = '';
        $showCalendarPrices = ConfigProvider::isShowCalendarPrices() ? 'Y' : 'N';

        if (!empty($hotelId) && $showCalendarPrices === 'Y') {
            $displayCurrency = CurrencyService::getDisplayCurrency();
            $priceInfoService = Container::getInstance()->priceInfoService();
            $calendarData = $priceInfoService->getCalendarPrices($hotelId, $displayCurrency, 2);
            if (!empty($calendarData['prices'])) {
                $calendarPricesJson = json_encode($calendarData['prices'], JSON_UNESCAPED_UNICODE);
                $calendarPricesCurrency = $calendarData['currency'];
            }
        }

        $view->assign('calendar_prices_json', $calendarPricesJson);
        $view->assign('calendar_prices_currency', $calendarPricesCurrency);
        $view->assign('show_calendar_prices', $showCalendarPrices);
    }

    /** @param \Smarty $view */
    private function assignMeta($view): void
    {
        $translated = __('novoton_holidays.search_results');
        $pageTitle = is_string($translated) && $translated !== '' ? $translated : 'Search Results';

        $view->assign('page_title', $pageTitle);
        // Broadcast dynamic navigation/meta state via a functions/ helper —
        // keeps this service class free of direct Registry writes.
        fn_novoton_holidays_set_dynamic_page_meta($pageTitle);
        $view->assign('meta_description', '');
        $view->assign('meta_keywords', '');
        $view->assign('canonical_url', '');
        $view->assign('og_image', '');
        $view->assign('og_title', $pageTitle);
        $view->assign('og_description', '');
        $view->assign('og_type', 'website');
        $view->assign('twitter_card', '');
        $view->assign('twitter_title', $pageTitle);
        $view->assign('twitter_description', '');

        fn_add_breadcrumb($pageTitle);
    }
}
