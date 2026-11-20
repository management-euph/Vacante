<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Service Loader
 *
 * Provides global accessor functions that delegate to the DI Container.
 * These functions exist for use in procedural code (hooks, controllers)
 * where constructor injection is not possible.
 *
 * @package NovotonHolidays
 * @since 2.8.0 (container-backed since 3.3.0)
 */

use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// =============================================================================
// SERVICE GETTERS
// =============================================================================

/**
 */
function _nvt_guest_service(): \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface
{
    return Container::getInstance()->guestDataService();
}

/**
 */
function _nvt_search_service(): \Tygh\Addons\NovotonHolidays\Services\SearchServiceInterface
{
    return Container::getInstance()->searchService();
}

/**
 */
function _nvt_security_service(): \Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface
{
    return Container::getInstance()->securityService();
}

/**
 */
function _nvt_cache_service(): \Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface
{
    return Container::getInstance()->cacheService();
}

/**
 */
function _nvt_currency_service(): \Tygh\Addons\TravelCore\Services\CurrencyService
{
    return Container::getInstance()->currencyService();
}

/**
 */
function _nvt_price_info_service(): \Tygh\Addons\NovotonHolidays\Services\PriceInfoServiceInterface
{
    return Container::getInstance()->priceInfoService();
}

/**
 */
function _nvt_date_helper(): \Tygh\Addons\TravelCore\Services\DateHelper
{
    return Container::getInstance()->dateHelper();
}

/**
 */
function _nvt_diagnostics_service(): \Tygh\Addons\NovotonHolidays\Services\DiagnosticsServiceInterface
{
    return Container::getInstance()->diagnosticsService();
}

/**
 */
function _nvt_alternative_request_service(): \Tygh\Addons\NovotonHolidays\Services\AlternativeRequestServiceInterface
{
    return Container::getInstance()->alternativeRequestService();
}

/**
 */
function _nvt_booking_submission_service(): \Tygh\Addons\NovotonHolidays\Services\BookingSubmissionServiceInterface
{
    return Container::getInstance()->bookingSubmissionService();
}

/**
 */
function _nvt_api(): \Tygh\Addons\NovotonHolidays\NovotonApi
{
    return Container::getInstance()->novotonApi();
}

/**
 */
function _nvt_admin_cron_service(): \Tygh\Addons\NovotonHolidays\Services\AdminCronService
{
    return Container::getInstance()->adminCronService();
}

/**
 */
function _nvt_property_type_detector(): \Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector
{
    return Container::getInstance()->propertyTypeDetector();
}

// =============================================================================
// REPOSITORY GETTERS
// =============================================================================

/**
 */
function _nvt_hotel_repo(): \Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface
{
    return Container::getInstance()->hotelRepository();
}

/**
 */
function _nvt_booking_repo(): \Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface
{
    return Container::getInstance()->bookingRepository();
}

/**
 * Ownership-scoped booking lookups (frontend security boundary).
 */
function _nvt_booking_ownership_repo(): \Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepositoryInterface
{
    return Container::getInstance()->bookingOwnershipRepository();
}

/**
 * Admin/aggregate booking reporting queries (counts, admin lists, export).
 */
function _nvt_booking_reporting_repo(): \Tygh\Addons\NovotonHolidays\Repository\BookingReportingRepositoryInterface
{
    return Container::getInstance()->bookingReportingRepository();
}

/**
 */
function _nvt_facility_repo(): \Tygh\Addons\NovotonHolidays\Repository\FacilityRepositoryInterface
{
    return Container::getInstance()->facilityRepository();
}

/**
 */
function _nvt_sync_log_repo(): \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepositoryInterface
{
    return Container::getInstance()->syncLogRepository();
}

/**
 */
function _nvt_alternative_request_repo(): \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface
{
    return Container::getInstance()->alternativeRequestRepository();
}
