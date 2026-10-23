<?php

declare(strict_types=1);

/**
 * Novoton PriceInfo Service
 *
 * Handles season-based price information for product tab display.
 * This is DIFFERENT from real-time booking prices (RoomPriceService).
 *
 * V3 Architecture:
 * - Season prices are stored in novoton_hotel_packages.priceinfo_data JSON
 * - Synced via cron job (mode=hotel_info_batched or mode=sync_priceinfo)
 * - NO CACHE needed - database is the source of truth
 *
 * Use Cases:
 * - Product detail page "Prices" tab
 * - Price sync/cron operations
 * - Reference pricing for browsing
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;
use Tygh\Addons\TravelCore\Services\CurrencyService;

class PriceInfoService implements PriceInfoServiceInterface
{
    private readonly PricingApiClientInterface $pricing;

    private float $commission;

    private bool $debug = false;

    private HotelPackageRepositoryInterface $packageRepo;

    private HotelRepositoryInterface $hotelRepo;

    private readonly PriceInfoExtractor $priceInfoExtractor;

    /**
     * Constructor
     */
    public function __construct(
        ?HotelPackageRepositoryInterface $packageRepo = null,
        ?HotelRepositoryInterface $hotelRepo = null,
        ?PricingApiClientInterface $pricing = null,
    ) {
        $this->pricing = $pricing ?? (new NovotonApi())->pricing();
        $this->commission = ConfigProvider::getCommission();
        $this->debug = ConfigProvider::isDebugLogging();
        $this->packageRepo = $packageRepo ?? new HotelPackageRepository();
        $this->hotelRepo = $hotelRepo ?? new HotelRepository();
        $this->priceInfoExtractor = new PriceInfoExtractor($this->packageRepo);
    }

    /**
     * Get price info for hotel package (for product tab)
     * V3: Reads from novoton_hotel_packages.priceinfo_data JSON
     *
     * @param string $hotelId Hotel ID
     * @param string $packageName Package name
     * @param string $lang Language
     * @return array<string, mixed>|null Parsed price info
     */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): ?array
    {
        // V3: Get from packages table
        $priceinfo = fn_novoton_holidays_get_package_priceinfo_by_name($hotelId, $packageName);

        if (!empty($priceinfo)) {
            $this->log('PriceInfo from database', ['hotel_id' => $hotelId, 'package' => $packageName]);
            return PriceInfoShaper::format($priceinfo, $hotelId);
        }

        // Fallback: Call API directly (for first-time or if cron hasn't run)
        $this->log('PriceInfo fallback to API', ['hotel_id' => $hotelId, 'package' => $packageName]);

        $response = $this->pricing->getPriceInfo($hotelId, $packageName, $lang);

        if (!(bool) $response) {
            return null;
        }

        // Parse response
        $result = $this->parsePriceInfoResponse($response, $hotelId);

        return $result;
    }

    /**
     * Get prices stored in database for product
     * V3: Reads from novoton_hotel_packages
     *
     * @param int|string $productIdOrHotelId Product ID or Hotel ID
     * @param string|null $packageName Optional package name filter
     * @return array<string, mixed> Prices grouped by room
     */
    public function getStoredPrices(int|string $productIdOrHotelId, ?string $packageName = null): array
    {
        $hotelId = $productIdOrHotelId;

        // If numeric, get hotel_id from product
        if (is_numeric($productIdOrHotelId)) {
            $hotelId = fn_novoton_holidays_get_hotel_id_by_product((int)$productIdOrHotelId);
            if (empty($hotelId)) {
                return [];
            }
        }

        // Get priceinfo from first package with data
        $priceinfoJson = $this->packageRepo->getPriceinfoData((string) $hotelId, $packageName ?: null);

        if (empty($priceinfoJson)) {
            return [];
        }

        $priceinfo = json_decode($priceinfoJson, true);
        if (empty($priceinfo) || !is_array($priceinfo)) {
            return [];
        }

        return PriceInfoShaper::extractPrices($priceinfo);
    }

    /**
     * Get last update time for product prices
     * V3: Reads from novoton_hotel_packages.synced_at
     *
     * @param int $productId Product ID
     * @return string|null Last update datetime
     */
    public function getLastUpdate(int $productId): ?string
    {
        $hotelId = fn_novoton_holidays_get_hotel_id_by_product($productId);
        if (empty($hotelId)) {
            return null;
        }

        return $this->packageRepo->getLastSyncedAt($hotelId);
    }

    /**
     * Get active package name for product
     * V3: Reads from novoton_hotel_packages
     *
     * @param int $productId Product ID
     * @return string|null Package name
     */
    public function getActivePackage(int $productId): ?string
    {
        $hotelId = fn_novoton_holidays_get_hotel_id_by_product($productId);
        if (empty($hotelId)) {
            return null;
        }

        return $this->packageRepo->getActivePackageName($hotelId);
    }

    /**
     * Get seasons for hotel
     * V3: Extracts from priceinfo_data JSON
     *
     * @param string $hotelId Hotel ID
     * @return list<array<string, mixed>> Seasons with dates
     */
    public function getSeasons(string $hotelId): array
    {
        return $this->priceInfoExtractor->getSeasons($hotelId);
    }

    /**
     * Get early booking discounts for hotel
     * V3: Extracts from priceinfo_data JSON
     *
     * @param string $hotelId Hotel ID
     * @return list<array<string, mixed>> Early booking periods
     */
    public function getEarlyBooking(string $hotelId): array
    {
        return $this->priceInfoExtractor->getEarlyBooking($hotelId);
    }

    /**
     * Get active early booking discount
     *
     * @param string $hotelId Hotel ID
     * @param string|null $date Date to check (default: today)
     * @return array<string, mixed>|null Active discount or null
     */
    public function getActiveEarlyBooking(string $hotelId, ?string $date = null): ?array
    {
        return $this->priceInfoExtractor->getActiveEarlyBooking($hotelId, $date);
    }

    /**
     * Get per-date calendar prices for a hotel.
     *
     * Returns a flat map of "YYYY-MM-DD" => price (float) representing
     * the estimated 1-night-stay total for a given number of adults in
     * the cheapest suitable room across ALL packages. Prices include
     * commission and are converted to the target currency.
     *
     * Reads precomputed raw EUR prices from novoton_hotels.calendar_prices_raw
     * (populated by sync cron via precomputeCalendarPrices()), then applies
     * commission and currency conversion per date.
     *
     * If the column is empty (cron hasn't run yet), returns an empty price map.
     * The Calendar UI should treat missing dates as "no price" (grey/dash).
     *
     * @param string $hotelId Hotel ID
     * @param string|null $targetCurrency Target currency code (null = display currency)
     * @param int $adults Number of adults (default 2)
     * @return array<string, mixed> ['prices' => [date => price], 'currency' => string]
     */
    public function getCalendarPrices(string $hotelId, ?string $targetCurrency = null, int $adults = 2): array
    {
        $currency = $targetCurrency ?? CurrencyService::getDisplayCurrency();
        $currencyService = _nvt_currency_service();

        // 1. Read precomputed raw prices (written by sync cron).
        // The calendar_prices_raw column is added by setup_db() on addon install/upgrade.
        // Use @-suppressed query — if the column doesn't exist yet, db_get_field triggers
        // a PHP warning or throws. Either way we fall back to an empty price map.
        try {
            $rawJson = $this->hotelRepo->getCalendarPricesRaw($hotelId);
        } catch (\Throwable) {
            $rawJson = null;
        }

        $rawPrices = !empty($rawJson) ? json_decode($rawJson, true) : null;

        if (empty($rawPrices) || !is_array($rawPrices)) {
            return ['prices' => [], 'currency' => $currency];
        }

        // 3. Apply commission + currency conversion (cheap per-date multiply)
        $dateMap = [];
        $commission = $this->commission;
        $roundPrices = ConfigProvider::isRoundPrices();
        $today = date('Y-m-d');

        foreach ($rawPrices as $date => $rawPrice) {
            $dateStr = (string) $date;
            if ($dateStr < $today) {
                continue;
            }
            $rawPriceFloat = PriceInfoFormatter::toFloat($rawPrice);
            $price = $rawPriceFloat * (1 + $commission / 100);
            $price = $currencyService->convertFromApiCurrency($price, $currency);
            if ($roundPrices) {
                $price = round($price);
            }
            $dateMap[$dateStr] = $price;
        }

        $this->log('Calendar prices loaded', [
            'hotel_id' => $hotelId,
            'currency' => $currency,
            'dates_count' => count($dateMap),
        ]);

        return ['prices' => $dateMap, 'currency' => $currency];
    }

    /**
     * Precompute raw calendar prices for a hotel and store in novoton_hotels.
     *
     * Called after priceinfo sync. Computes the cheapest raw EUR price per date
     * across ALL packages (no commission, no currency conversion). The result is
     * stored as JSON in novoton_hotels.calendar_prices_raw.
     *
     * At display time, getCalendarPrices() reads this column and applies
     * commission + currency — a trivial per-date multiply instead of full
     * JSON parsing and room grouping.
     *
     * @param string $hotelId Hotel ID
     */
    public static function precomputeCalendarPrices(string $hotelId): void
    {
        $rawPrices = (new CalendarPriceBuilder(new HotelPackageRepository()))->computeRawCalendarPrices($hotelId);

        // Column is added by setup_db() on addon install/upgrade.
        // Suppress errors if column doesn't exist yet.
        try {
            $hotelRepo = new HotelRepository();
            $json = !empty($rawPrices) ? json_encode($rawPrices, JSON_UNESCAPED_UNICODE) : null;
            $hotelRepo->setCalendarPricesRaw($hotelId, $json ?: null);
        } catch (\Throwable) {
            // Column doesn't exist yet — skip silently
        }

        // The published price window, for the hotel availability records.
        (new NovotonAvailabilityRecorder())->calendarComputed($hotelId, $rawPrices);
    }

    // Pure priceinfo-response shaping (extract / format / group-by-room) was
    // extracted to the stateless PriceInfoShaper collaborator (SRP). Callers
    // above delegate to it directly.

    /**
     * Parse priceinfo API response
     *
     * @param \SimpleXMLElement $response API response
     * @param string $hotelId Hotel ID
     * @return array<string, mixed> Parsed data
     */
    private function parsePriceInfoResponse($response, string $hotelId): array
    {
        // Convert SimpleXML to array
        $data = json_decode((string) json_encode($response), true);
        if (!is_array($data)) {
            return ['hotel_id' => $hotelId, 'seasons' => [], 'prices' => [], 'early_booking' => [], 'raw' => []];
        }

        return PriceInfoShaper::format($data, $hotelId);
    }

    /**
     * Log debug message
     *
     * @param string $message Message
     * @param array<string, mixed> $context Context data
     */
    private function log(string $message, array $context = []): void
    {
        if (!$this->debug) {
            return;
        }

        fn_log_event('novoton_holidays', 'priceinfo', array_merge(
            ['message' => $message],
            $context,
        ));
    }
}
