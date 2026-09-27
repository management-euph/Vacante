<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: Compute derived price metadata from raw priceinfo_data
 *
 * Runs as a lightweight cron (no API calls, pure DB + PHP computation).
 * Processes packages flagged with needs_price_compute = 'Y' after sync,
 * then recomputes calendar_prices_raw for affected hotels.
 *
 * Computed fields per package:
 *   - min_price: lowest adult price (excludes children, percentages, 3RD ADULT)
 *   - seasons_count: number of seasons in priceinfo
 *   - has_early_booking: Y/N flag
 *
 * After all dirty packages are processed, precomputeCalendarPrices() runs
 * once per affected hotel (not per package).
 *
 * Modes:
 *   - compute_prices: Process packages where needs_price_compute = 'Y'
 *
 * Parameters:
 *   - force=1:      Recompute ALL packages (ignores needs_price_compute flag)
 *   - hotel_id=X:   Recompute only packages for a single hotel
 *
 * Usage:
 *   cron.php?mode=compute_prices                    # Only dirty packages
 *   cron.php?mode=compute_prices&force=1            # ALL packages
 *   cron.php?mode=compute_prices&hotel_id=12345     # Single hotel
 */
class PriceComputeCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['compute_prices'];
    }

    public static function getDescription(): string
    {
        return 'Compute min_price, seasons_count, has_early_booking from priceinfo_data (no API calls, fast)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('Compute Price Metadata');
        $this->output('======================');
        $this->output('');

        $singleHotel = $this->getParam('hotel_id', '');
        if (!empty($singleHotel)) {
            return $this->computeForHotel(TypeCoerce::toString($singleHotel));
        }

        $force = !empty($this->params['force']);

        if ($force) {
            $this->output('Mode: FORCE (all packages with priceinfo_data)');
            $packages = db_get_array(
                "SELECT id, hotel_id, package_id, priceinfo_data
                 FROM ?:novoton_hotel_packages
                 WHERE priceinfo_data IS NOT NULL AND priceinfo_data != ''",
            );
        } else {
            $this->output('Mode: incremental (needs_price_compute = Y)');
            $packages = db_get_array(
                "SELECT id, hotel_id, package_id, priceinfo_data
                 FROM ?:novoton_hotel_packages
                 WHERE needs_price_compute = 'Y'
                   AND priceinfo_data IS NOT NULL AND priceinfo_data != ''",
            );
        }

        $packages = TypeCoerce::toList($packages);
        $total = count($packages);
        $this->output("Packages to process: {$total}");
        $this->output('');

        if ($total === 0) {
            $this->output('Nothing to do.');
            return ['success' => true, 'stats' => ['total' => 0, 'processed' => 0, 'errors' => 0]];
        }

        $processed = 0;
        $errors = 0;
        $dirtyHotels = [];

        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $pkgHotelId = PriceInfoFormatter::toScalar($pkg['hotel_id'] ?? '');
            $pkgPackageId = PriceInfoFormatter::toScalar($pkg['package_id'] ?? '');
            try {
                $priceinfo = json_decode(PriceInfoFormatter::toScalar($pkg['priceinfo_data'] ?? ''), true);
                if (empty($priceinfo) || !is_array($priceinfo)) {
                    $errors++;
                    $this->output("  ERROR [{$pkgHotelId}/{$pkgPackageId}]: invalid JSON");
                    continue;
                }
                $priceinfo = TypeCoerce::toStringMap($priceinfo);

                $minPrice = self::extractMinPrice($priceinfo);
                $seasonsCount = self::countSeasons($priceinfo);
                $hasEarlyBooking = self::hasEarlyBooking($priceinfo) ? 'Y' : 'N';

                db_query(
                    "UPDATE ?:novoton_hotel_packages SET
                     min_price = ?d,
                     seasons_count = ?i,
                     has_early_booking = ?s,
                     needs_price_compute = 'N'
                     WHERE id = ?i",
                    $minPrice,
                    $seasonsCount,
                    $hasEarlyBooking,
                    PriceInfoFormatter::toInt($pkg['id'] ?? 0),
                );

                $dirtyHotels[$pkgHotelId] = true;
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->output("  ERROR [{$pkgHotelId}/{$pkgPackageId}]: " . $e->getMessage());
            }

            if (($processed + $errors) % 200 === 0) {
                $done = $processed + $errors;
                $pct = round($done / $total * 100, 1);
                $this->output("  Progress: {$done}/{$total} ({$pct}%)");
            }
        }

        // Recompute calendar prices once per affected hotel
        $hotelCount = count($dirtyHotels);
        if ($hotelCount > 0) {
            $this->output('');
            $this->output("Recomputing calendar prices for {$hotelCount} hotels...");
            $calErrors = 0;
            foreach (array_keys($dirtyHotels) as $hotelId) {
                try {
                    PriceInfoService::precomputeCalendarPrices((string) $hotelId);
                } catch (\Throwable $e) {
                    $calErrors++;
                    $this->output("  Calendar ERROR [{$hotelId}]: " . $e->getMessage());
                }
            }
            if ($calErrors > 0) {
                $this->output("  Calendar errors: {$calErrors}");
            }

            // Update CS-Cart product prices from computed min_price
            $this->output('Updating product catalog prices...');
            $priceUpdated = 0;
            foreach (array_keys($dirtyHotels) as $hotelId) {
                try {
                    $priceUpdated += self::updateProductPrice((string) $hotelId) ? 1 : 0;
                } catch (\Throwable $e) {
                    $this->output("  Product price ERROR [{$hotelId}]: " . $e->getMessage());
                }
            }
            if ($priceUpdated > 0) {
                $this->output("  {$priceUpdated} product prices updated");
            }
        }

        $duration = round(microtime(true) - $this->startTime, 1);
        $this->output('');
        $this->output("Done in {$duration}s: {$processed} computed, {$errors} errors, {$hotelCount} hotels recalculated");

        $this->logToSyncTable('compute_prices', $processed, $errors);

        return [
            'success' => true,
            'stats' => [
                'total' => $total,
                'processed' => $processed,
                'errors' => $errors,
                'hotels_recomputed' => $hotelCount,
                'duration_sec' => $duration,
            ],
        ];
    }

    /**
     * Compute prices for a single hotel's packages.
     * @return array<string, mixed>
     */
    private function computeForHotel(string $hotelId): array
    {
        $this->output("Computing for hotel: {$hotelId}");

        $packages = db_get_array(
            "SELECT id, hotel_id, package_id, priceinfo_data
             FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL AND priceinfo_data != ''",
            $hotelId,
        );

        if (empty($packages)) {
            $this->output('No packages with priceinfo_data found.');
            return ['success' => true, 'stats' => ['hotel_id' => $hotelId, 'processed' => 0]];
        }

        $processed = 0;
        $errors = 0;

        foreach (TypeCoerce::toList($packages) as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            try {
                $priceinfo = json_decode(PriceInfoFormatter::toScalar($pkg['priceinfo_data'] ?? ''), true);
                if (empty($priceinfo) || !is_array($priceinfo)) {
                    $errors++;
                    continue;
                }
                $priceinfo = TypeCoerce::toStringMap($priceinfo);

                $minPrice = self::extractMinPrice($priceinfo);
                $seasonsCount = self::countSeasons($priceinfo);
                $hasEarlyBooking = self::hasEarlyBooking($priceinfo) ? 'Y' : 'N';

                db_query(
                    "UPDATE ?:novoton_hotel_packages SET
                     min_price = ?d,
                     seasons_count = ?i,
                     has_early_booking = ?s,
                     needs_price_compute = 'N'
                     WHERE id = ?i",
                    $minPrice,
                    $seasonsCount,
                    $hasEarlyBooking,
                    PriceInfoFormatter::toInt($pkg['id'] ?? 0),
                );

                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $pkgId = PriceInfoFormatter::toScalar($pkg['package_id'] ?? '');
                $this->output("  ERROR [{$pkgId}]: " . $e->getMessage());
            }
        }

        // Recompute calendar prices for this hotel
        try {
            PriceInfoService::precomputeCalendarPrices($hotelId);
            $this->output('Calendar prices recomputed.');
        } catch (\Throwable $e) {
            $this->output('Calendar ERROR: ' . $e->getMessage());
        }

        // Update CS-Cart product price
        if (self::updateProductPrice($hotelId)) {
            $this->output('Product catalog price updated.');
        }

        $this->output("OK: {$processed} packages computed, {$errors} errors");

        return [
            'success' => true,
            'stats' => [
                'hotel_id' => $hotelId,
                'processed' => $processed,
                'errors' => $errors,
            ],
        ];
    }

    /**
     * Update CS-Cart product price from the hotel's lowest min_price (with commission).
     *
     * @param string $hotelId Hotel ID
     * @return bool True if product price was updated
     */
    public static function updateProductPrice(string $hotelId): bool
    {
        /** @var array<string, mixed>|null $row */
        $row = db_get_row(
            'SELECT h.product_id, MIN(p.min_price) AS lowest_price
             FROM ?:novoton_hotels h
             JOIN ?:novoton_hotel_packages p ON p.hotel_id = h.hotel_id
             WHERE h.hotel_id = ?s AND p.min_price > 0 AND h.product_id > 0
             GROUP BY h.product_id',
            $hotelId,
        );

        if (empty($row) || empty($row['product_id']) || empty($row['lowest_price'])) {
            return false;
        }

        $commission = ConfigProvider::getCommission();
        $price = PriceInfoFormatter::toFloat($row['lowest_price']) * (1 + ($commission / 100));
        $price = ConfigProvider::isRoundPrices() ? round($price) : round($price, 2);

        self::writeBasePrice(PriceInfoFormatter::toInt($row['product_id']), $price);

        return true;
    }

    /**
     * Set a product's catalog price: its base row in ?:product_prices
     * (quantity 1, all customer groups), created when the product has none.
     *
     * CS-Cart 4 keeps prices there: ?:products has no price column, so
     * writing one there failed every compute_prices run with "Unknown
     * column 'price' in 'SET' (1054)", one error per hotel.
     */
    public static function writeBasePrice(int $productId, float $price): void
    {
        if ($productId <= 0) {
            return;
        }

        db_query(
            'INSERT INTO ?:product_prices (product_id, price, lower_limit, usergroup_id)
             VALUES (?i, ?d, 1, 0)
             ON DUPLICATE KEY UPDATE price = VALUES(price)',
            $productId,
            $price,
        );
    }

    /**
     * Extract minimum adult price from decoded priceinfo data.
     *
     * Rules (canonical, single source of truth):
     * - Only ADULT age rows (case-insensitive match on IdAge)
     * - Excludes "3 RD ADULT" / "3RD ADULT" supplementary rows
     * - Skips percentage values (e.g. "85%")
     * - Checks Price1 through Price20
     *
     * @param array<string, mixed> $priceinfo Decoded priceinfo_data JSON
     * @return float|null Minimum price or null if none found
     */
    public static function extractMinPrice(array $priceinfo): ?float
    {
        $minPrice = null;

        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (empty($seasonPrices)) {
            return null;
        }

        $seasonPricesMap = TypeCoerce::toStringMap($seasonPrices);

        // Normalize single entry to array
        if (isset($seasonPricesMap['Code']) || isset($seasonPricesMap['IdRoom'])) {
            $seasonPrices = [$seasonPricesMap];
        } else {
            $seasonPrices = TypeCoerce::toList($seasonPrices);
        }

        foreach ($seasonPrices as $sp) {
            if (!is_array($sp)) {
                continue;
            }
            $idAge = PriceInfoFormatter::toScalar($sp['IdAge'] ?? '');

            // Must contain ADULT (case-insensitive)
            if (!str_contains(strtolower($idAge), 'adult')) {
                continue;
            }

            // Exclude supplementary "3 RD ADULT" / "3RD ADULT" rows
            if (str_contains(strtolower($idAge), '3 rd') || str_contains(strtolower($idAge), '3rd')) {
                continue;
            }

            for ($i = 1; $i <= 20; $i++) {
                $priceKey = "Price{$i}";
                if (!isset($sp[$priceKey])) {
                    continue;
                }

                $priceVal = PriceInfoFormatter::toScalar($sp[$priceKey]);

                // Skip percentage values
                if (str_contains($priceVal, '%')) {
                    continue;
                }

                $price = PriceInfoFormatter::toFloat($priceVal);
                if ($price > 0 && ($minPrice === null || $price < $minPrice)) {
                    $minPrice = $price;
                }
            }
        }

        return $minPrice;
    }

    /**
     * Count seasons in priceinfo data.
     * @param array<string, mixed> $priceinfo
     */
    public static function countSeasons(array $priceinfo): int
    {
        $seasonsRoot = TypeCoerce::toStringMap($priceinfo['seasons'] ?? []);
        $seasons = $seasonsRoot['season'] ?? $priceinfo['seasons'] ?? [];

        if (empty($seasons) || !is_array($seasons)) {
            return 0;
        }

        // Single season (has IdSeason key directly)
        if (isset($seasons['IdSeason'])) {
            return 1;
        }

        return count($seasons);
    }

    /**
     * Check if priceinfo has early booking discounts.
     * @param array<string, mixed> $priceinfo
     */
    public static function hasEarlyBooking(array $priceinfo): bool
    {
        if (!empty($priceinfo['early_booking'])) {
            return true;
        }

        // Also check within seasons
        $seasons = $priceinfo['seasons'] ?? [];
        $seasonsMap = TypeCoerce::toStringMap($seasons);
        if (isset($seasonsMap['season'])) {
            $seasons = $seasonsMap['season'];
        }
        $seasonsMap = TypeCoerce::toStringMap($seasons);
        if (isset($seasonsMap['IdSeason'])) {
            $seasons = [$seasonsMap];
        }
        if (is_array($seasons)) {
            foreach ($seasons as $season) {
                $seasonMap = TypeCoerce::toStringMap($season);
                if (!empty($seasonMap['EarlyBooking']) || !empty($seasonMap['early_booking'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
