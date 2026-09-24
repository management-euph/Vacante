<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeHttpClient;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Repository\CityRepository;
use Tygh\Addons\Eurosite\Repository\CountryRepository;
use Tygh\Addons\Eurosite\Repository\DestinationWhitelistRepository;
use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\Eurosite\Repository\HotelRepository;
use Tygh\Addons\Eurosite\Repository\ProductInfoCacheRepository;
use Tygh\Addons\Eurosite\Repository\RoomTypeRepository;
use Tygh\Addons\Eurosite\Repository\SyncLogRepository;
use Tygh\Addons\Eurosite\Repository\TagRepository;

/**
 * Tiny service container for the Eurosite addon.
 *
 * Lazily wires the API stack from the addon settings (ConfigProvider) — HTTP
 * client, XML builder, XML parser, API facade — and caches the singletons per
 * request. This is the composition root the controllers/hooks call
 * (ConfigProvider is the only thing that reads the Registry).
 */
final class Container
{
    private static ?EurositeApiClient $api = null;

    private static ?EurositeHttpClient $http = null;

    public static function getApi(): EurositeApiClient
    {
        if (self::$api === null) {
            self::$api = new EurositeApiClient(
                self::getHttpClient(),
                self::getXmlBuilder(),
                new EurositeXmlParser(),
                ConfigProvider::getTourOpCode(),
            );
        }

        return self::$api;
    }

    public static function getHttpClient(): EurositeHttpClient
    {
        if (self::$http === null) {
            self::$http = new EurositeHttpClient(ConfigProvider::toClientSettings());
        }

        return self::$http;
    }

    public static function getXmlBuilder(): EurositeXmlBuilder
    {
        return new EurositeXmlBuilder(
            ConfigProvider::getApiUser(),
            ConfigProvider::getApiPassword(),
            ConfigProvider::getDefaultLanguage(),
        );
    }

    // ── Repositories (stateless; cached per request) ─────────────────────────

    private static ?CountryRepository $countries = null;

    private static ?CityRepository $cities = null;

    private static ?HotelRepository $hotels = null;

    private static ?RoomTypeRepository $roomTypes = null;

    private static ?TagRepository $tags = null;

    private static ?DestinationWhitelistRepository $whitelist = null;

    private static ?SyncLogRepository $syncLog = null;

    private static ?ProductInfoCacheRepository $productInfoCache = null;

    private static ?EurositeBookingRepository $bookings = null;

    private static ?EurositeProductFactory $productFactory = null;

    private static ?HotelProductService $hotelProducts = null;

    public static function productFactory(): EurositeProductFactory
    {
        return self::$productFactory ??= new EurositeProductFactory(self::hotels());
    }

    public static function hotelProducts(): HotelProductService
    {
        return self::$hotelProducts ??= new HotelProductService(
            self::hotels(),
            self::productInfoCache(),
            self::getApi(),
            self::productFactory(),
        );
    }

    public static function bookings(): EurositeBookingRepository
    {
        return self::$bookings ??= new EurositeBookingRepository();
    }

    public static function countries(): CountryRepository
    {
        return self::$countries ??= new CountryRepository();
    }

    public static function cities(): CityRepository
    {
        return self::$cities ??= new CityRepository();
    }

    public static function hotels(): HotelRepository
    {
        return self::$hotels ??= new HotelRepository();
    }

    public static function roomTypes(): RoomTypeRepository
    {
        return self::$roomTypes ??= new RoomTypeRepository();
    }

    public static function tags(): TagRepository
    {
        return self::$tags ??= new TagRepository();
    }

    public static function whitelist(): DestinationWhitelistRepository
    {
        return self::$whitelist ??= new DestinationWhitelistRepository();
    }

    public static function syncLog(): SyncLogRepository
    {
        return self::$syncLog ??= new SyncLogRepository();
    }

    public static function productInfoCache(): ProductInfoCacheRepository
    {
        return self::$productInfoCache ??= new ProductInfoCacheRepository();
    }

    /**
     * Reset cached singletons (tests / after a settings change).
     */
    public static function reset(): void
    {
        self::$api = null;
        self::$http = null;
        self::$countries = null;
        self::$cities = null;
        self::$hotels = null;
        self::$roomTypes = null;
        self::$tags = null;
        self::$whitelist = null;
        self::$syncLog = null;
        self::$productInfoCache = null;
        self::$bookings = null;
        self::$productFactory = null;
        self::$hotelProducts = null;
    }
}
