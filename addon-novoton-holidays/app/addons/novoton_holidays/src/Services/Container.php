<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Dependency Injection Container
 *
 * Manages service construction, dependency wiring, and singleton lifecycle.
 * All service/repository/helper access should go through this container.
 *
 * Usage:
 *   $container = Container::getInstance();
 *   $bookingService = $container->bookingService();
 *   $hotelRepo = $container->hotelRepository();
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\NovotonNormalizer;
use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelFacilitiesSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedPriceInfoSyncV2;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelperInterface;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIteratorInterface;
use Tygh\Addons\NovotonHolidays\Helpers\ProductFactory;
use Tygh\Addons\NovotonHolidays\Helpers\ProductFactoryInterface;
use Tygh\Addons\NovotonHolidays\Helpers\SyncInterface;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingReportingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingReportingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepository;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelCacheRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelCacheRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelReportingRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelReportingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelSearchRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelSearchRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepositoryInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class Container
{
    private static ?self $instance = null;

    /** @var array<string, object> Resolved singleton instances */
    private array $instances = [];

    /** @var array<string, callable> Factory overrides for testing */
    private array $overrides = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the container instance (for testing).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Reset all cached instances (for testing or mid-request reload).
     */
    public function reset(): void
    {
        $this->instances = [];
    }

    /**
     * Override a factory for testing.
     * @param string $id Service identifier (method name)
     * @param callable $factory Factory returning the mock/stub
     */
    public function override(string $id, callable $factory): void
    {
        $this->overrides[$id] = $factory;
        unset($this->instances[$id]);
    }

    // ── Resolve helper ──

    /**
     * @template T of object
     * @param callable(): T $factory
     * @return T
     */
    private function resolve(string $id, callable $factory): object
    {
        if (isset($this->overrides[$id])) {
            /** @var T */
            return $this->instances[$id] ??= ($this->overrides[$id])();
        }
        /** @var T */
        return $this->instances[$id] ??= $factory();
    }

    /**
     * Like {@see self::resolve()} but never caches: a new instance is created
     * on every call (or produced by the test override). Used for the batched
     * sync helpers, which must be fresh per invocation.
     *
     * @template T of object
     * @param callable(): T $factory
     * @return T
     */
    private function resolveFresh(string $id, callable $factory): object
    {
        if (isset($this->overrides[$id])) {
            /** @var T */
            return ($this->overrides[$id])();
        }
        return $factory();
    }

    // ═══════════════════════════════════════════════════════════════════
    // REPOSITORIES
    // ═══════════════════════════════════════════════════════════════════

    public function hotelRepository(): HotelRepositoryInterface
    {
        return $this->resolve('hotelRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\HotelRepository => new HotelRepository(
            $this->hotelSearchRepository(),
            $this->hotelReportingRepository(),
            $this->hotelCacheRepository(),
            $this->hotelPackageRepository(),
        ));
    }

    public function hotelSearchRepository(): HotelSearchRepositoryInterface
    {
        return $this->resolve('hotelSearchRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\HotelSearchRepository => new HotelSearchRepository());
    }

    public function hotelReportingRepository(): HotelReportingRepositoryInterface
    {
        return $this->resolve('hotelReportingRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\HotelReportingRepository => new HotelReportingRepository());
    }

    public function hotelCacheRepository(): HotelCacheRepositoryInterface
    {
        return $this->resolve('hotelCacheRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\HotelCacheRepository => new HotelCacheRepository());
    }

    public function bookingRepository(): BookingRepositoryInterface
    {
        return $this->resolve('bookingRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\BookingRepository => new BookingRepository());
    }

    public function bookingReportingRepository(): BookingReportingRepositoryInterface
    {
        return $this->resolve('bookingReportingRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\BookingReportingRepository => new BookingReportingRepository());
    }

    public function bookingOwnershipRepository(): BookingOwnershipRepositoryInterface
    {
        return $this->resolve('bookingOwnershipRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepository => new BookingOwnershipRepository());
    }

    public function facilityRepository(): FacilityRepositoryInterface
    {
        return $this->resolve('facilityRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\FacilityRepository => new FacilityRepository());
    }

    public function syncLogRepository(): SyncLogRepositoryInterface
    {
        return $this->resolve('syncLogRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository => new SyncLogRepository());
    }

    public function alternativeRequestRepository(): AlternativeRequestRepositoryInterface
    {
        return $this->resolve('alternativeRequestRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository => new AlternativeRequestRepository());
    }

    public function hotelPackageRepository(): HotelPackageRepositoryInterface
    {
        return $this->resolve('hotelPackageRepository', fn (): \Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository => new HotelPackageRepository());
    }

    // ═══════════════════════════════════════════════════════════════════
    // SERVICES
    // ═══════════════════════════════════════════════════════════════════

    public function bookingService(): BookingServiceInterface
    {
        return $this->resolve('bookingService', fn (): \Tygh\Addons\NovotonHolidays\Services\BookingService => new BookingService(
            $this->guestDataService(),
            $this->bookingRepository(),
            $this->novotonApi()->pricing(),
            $this->hotelRepository(),
        ));
    }

    public function guestDataService(): \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface
    {
        return $this->resolve('guestDataService', fn (): \Tygh\Addons\TravelCore\Services\GuestDataService => new \Tygh\Addons\TravelCore\Services\GuestDataService());
    }

    public function searchService(): SearchServiceInterface
    {
        return $this->resolve('searchService', fn (): \Tygh\Addons\NovotonHolidays\Services\SearchService => new SearchService());
    }

    public function roomPriceService(): RoomPriceServiceInterface
    {
        return $this->resolve('roomPriceService', fn (): \Tygh\Addons\NovotonHolidays\Services\RoomPriceService => new RoomPriceService(
            pricing: $this->novotonApi()->pricing(),
        ));
    }

    public function securityService(): SecurityServiceInterface
    {
        return $this->resolve('securityService', fn (): \Tygh\Addons\NovotonHolidays\Services\SecurityService => new SecurityService());
    }

    public function cacheService(): CacheServiceInterface
    {
        return $this->resolve('cacheService', fn (): \Tygh\Addons\NovotonHolidays\Services\CacheService => new CacheService());
    }

    public function priceInfoService(): PriceInfoServiceInterface
    {
        return $this->resolve('priceInfoService', fn (): \Tygh\Addons\NovotonHolidays\Services\PriceInfoService => new PriceInfoService());
    }

    public function dateHelper(): \Tygh\Addons\TravelCore\Services\DateHelper
    {
        return $this->resolve('dateHelper', fn (): \Tygh\Addons\TravelCore\Services\DateHelper => new \Tygh\Addons\TravelCore\Services\DateHelper());
    }

    public function configProvider(): ConfigProvider
    {
        return $this->resolve('configProvider', fn (): \Tygh\Addons\NovotonHolidays\Services\ConfigProvider => ConfigProvider::instance());
    }

    public function cronService(): CronServiceInterface
    {
        return $this->resolve('cronService', fn (): \Tygh\Addons\NovotonHolidays\Services\CronService => new CronService(
            reservations: $this->novotonApi()->reservations(),
        ));
    }

    public function searchParameterNormalizer(): SearchParameterNormalizer
    {
        return $this->resolve('searchParameterNormalizer', fn (): \Tygh\Addons\NovotonHolidays\Services\SearchParameterNormalizer => new SearchParameterNormalizer());
    }

    public function searchResultFormatter(): SearchResultFormatter
    {
        return $this->resolve('searchResultFormatter', fn (): \Tygh\Addons\NovotonHolidays\Services\SearchResultFormatter => new SearchResultFormatter());
    }

    public function diagnosticsService(): DiagnosticsServiceInterface
    {
        return $this->resolve('diagnosticsService', fn (): \Tygh\Addons\NovotonHolidays\Services\DiagnosticsService => new DiagnosticsService());
    }

    public function alternativeRequestService(): AlternativeRequestServiceInterface
    {
        return $this->resolve('alternativeRequestService', fn (): \Tygh\Addons\NovotonHolidays\Services\AlternativeRequestService => new AlternativeRequestService(
            $this->securityService(),
            $this->novotonApi()->reservations(),
        ));
    }

    public function bookingSubmissionService(): BookingSubmissionServiceInterface
    {
        return $this->resolve('bookingSubmissionService', fn (): \Tygh\Addons\NovotonHolidays\Services\BookingSubmissionService => new BookingSubmissionService(
            $this->bookingRepository(),
            $this->novotonApi()->reservations(),
            null,
            static fn (array $booking, string $status): string => RequestAnswerMail::forStore()->answered($booking, $status),
        ));
    }

    public function currencyService(): \Tygh\Addons\TravelCore\Services\CurrencyService
    {
        return $this->resolve('currencyService', fn (): \Tygh\Addons\TravelCore\Services\CurrencyService => new \Tygh\Addons\TravelCore\Services\CurrencyService(
            ConfigProvider::getApiCurrency(),
        ));
    }

    public function preOrderPriceVerifier(): \Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface
    {
        return $this->resolve('preOrderPriceVerifier', fn (): \Tygh\Addons\NovotonHolidays\Services\PreOrderPriceVerifier => new PreOrderPriceVerifier());
    }

    public function priceChangeDetector(): \Tygh\Addons\TravelCore\Services\PriceChangeDetector
    {
        return $this->resolve('priceChangeDetector', fn (): \Tygh\Addons\TravelCore\Services\PriceChangeDetector => new \Tygh\Addons\TravelCore\Services\PriceChangeDetector(
            TypeCoerce::toFloat(ConfigProvider::get('price_change_tolerance_percent', 1.0)),
        ));
    }

    public function featureMapper(): FeatureMapper
    {
        return $this->resolve('featureMapper', fn (): \Tygh\Addons\NovotonHolidays\Services\FeatureMapper => new FeatureMapper());
    }

    public function novotonNormalizer(): ProviderNormalizerInterface
    {
        return $this->resolve('novotonNormalizer', fn (): \Tygh\Addons\NovotonHolidays\Api\NovotonNormalizer => new NovotonNormalizer());
    }

    public function novotonApi(): NovotonApi
    {
        return $this->resolve('novotonApi', fn (): \Tygh\Addons\NovotonHolidays\NovotonApi => new NovotonApi());
    }

    public function adminCronService(): AdminCronService
    {
        return $this->resolve('adminCronService', fn (): \Tygh\Addons\NovotonHolidays\Services\AdminCronService => new AdminCronService(
            $this->novotonApi(),
        ));
    }

    public function propertyTypeDetector(): PropertyTypeDetector
    {
        return $this->resolve('propertyTypeDetector', fn (): \Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector => new PropertyTypeDetector());
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    public function databaseHelper(): DatabaseHelperInterface
    {
        return $this->resolve('databaseHelper', fn (): \Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper => new DatabaseHelper());
    }

    public function databaseIterator(): DatabaseIteratorInterface
    {
        return $this->resolve('databaseIterator', fn (): \Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator => new DatabaseIterator());
    }

    public function productFactory(): ProductFactoryInterface
    {
        return $this->resolve('productFactory', fn (): \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory => new ProductFactory(
            $this->databaseHelper(),
        ));
    }

    /**
     * BatchedHotelFacilitiesSyncV2 is the AbstractBatchedSync-based
     * replacement for BatchedHotelFacilitiesSync. Not a singleton —
     * new instance each call.
     */
    public function batchedHotelFacilitiesSyncV2(): SyncInterface
    {
        return $this->resolveFresh(
            'batchedHotelFacilitiesSyncV2',
            fn (): \Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelFacilitiesSyncV2 => new BatchedHotelFacilitiesSyncV2(),
        );
    }

    /**
     * BatchedPriceInfoSyncV2 is the AbstractBatchedSync-based replacement
     * for BatchedPriceInfoSync.
     *
     * Returns the concrete class (not SyncInterface) because callers need
     * access to `setStaleHours()`, which is not part of SyncInterface.
     * Not a singleton — new instance each call.
     */
    public function batchedPriceInfoSyncV2(): BatchedPriceInfoSyncV2
    {
        return $this->resolveFresh(
            'batchedPriceInfoSyncV2',
            fn (): BatchedPriceInfoSyncV2 => new BatchedPriceInfoSyncV2(),
        );
    }

    /**
     * BatchedHotelInfoSyncV2 is the AbstractBatchedSync-based replacement
     * for BatchedHotelInfoSync.
     *
     * Returns SyncInterface — unlike BatchedPriceInfoSyncV2, the hotel-info
     * helper has no concrete-only setters, so callers get the loose
     * coupling of the interface type.
     * Not a singleton — new instance each call.
     */
    public function batchedHotelInfoSyncV2(): \Tygh\Addons\NovotonHolidays\Helpers\AbstractBatchedSync
    {
        return $this->resolveFresh(
            'batchedHotelInfoSyncV2',
            fn (): \Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSyncV2 => new BatchedHotelInfoSyncV2(),
        );
    }
}
