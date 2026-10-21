<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;

/**
 * Create CS-Cart products from unlinked Sphinx hotels.
 *
 * Thin orchestrator: loads batches of unlinked hotels, resolves destination
 * hierarchies, then delegates per-hotel product creation to SphinxProductFactory.
 *
 * Supports resume capability (like Novoton's batched sync):
 * - Saves state to a JSON file after each batch of hotels
 * - findUnlinked() naturally skips already-linked hotels, so resume is implicit
 * - State file tracks progress counts (added/skipped/failed) across runs
 * - Stale state (>6h no activity) is cleared automatically
 *
 * Usage:
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&country=TR
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&limit=500
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&retry_skipped=1
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&retry_skipped=invalid_country
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&status=1
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&reset=1
 */
class AddProductsCommand extends AbstractSyncCommand
{
    use StatefulCommandTrait;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['add_products'];
    }

    private const string STATE_FILE_NAME = 'sphinx_add_products_state.json';
    private const int STALE_HOURS = 6;
    private const array DEFAULT_STATE = [
        'status' => 'idle',
        'started_at' => null,
        'last_run_at' => null,
        'added' => 0,
        'skipped' => 0,
        'failed' => 0,
        'invalid_country' => 0,
        'total' => 0,
        'country' => '',
    ];

    #[\Override]
    public static function getDescription(): string
    {
        return 'Create CS-Cart products from unlinked Sphinx hotels (retry_skipped=1 to retry previously skipped)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        // Handle reset — clears state file
        if (!empty($params['reset'])) {
            $this->clearState();
            $this->output('Add products state cleared. Ready for fresh run.');
            return ['success' => true, 'stats' => ['action' => 'reset']];
        }

        // Handle status check
        if (!empty($params['status'])) {
            return $this->showStatus();
        }

        // Handle debug — diagnose category creation without modifying anything
        if (!empty($params['debug'])) {
            return $this->debugCategoryCreation($params);
        }

        // Load existing state
        $state = $this->loadState();

        // Check for stale in-progress state
        if ($state['status'] === 'in_progress') {
            if ($this->isStale($state)) {
                $lastRunAt = ValidationHelpers::toString($state['last_run_at'] ?? '');
                $this->output("Stale state detected (no activity since {$lastRunAt}). Clearing and starting fresh.");
                $this->clearState();
            } else {
                // Resume — findUnlinked() naturally skips already-linked hotels
                $addedSoFar = ValidationHelpers::toInt($state['added'] ?? 0);
                $failedSoFar = ValidationHelpers::toInt($state['failed'] ?? 0);
                $this->output("Resuming product creation ({$addedSoFar} added, {$failedSoFar} failed so far)...");
                return $this->runWithState($state, $params);
            }
        }

        // Fresh start
        $state = self::DEFAULT_STATE;
        $state['status'] = 'in_progress';
        $state['started_at'] = date('Y-m-d H:i:s');
        $state['last_run_at'] = date('Y-m-d H:i:s');
        $state['country'] = $params['country'] ?? '';
        $this->saveState($state);

        return $this->runWithState($state, $params);
    }

    /**
     * Process hotels in batches, saving state after each batch.
     * Can be interrupted and resumed — findUnlinked() skips already-linked hotels.
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function runWithState(array $state, array $params): array
    {
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();
        $factory = Container::getProductFactory();
        $skipRepo = Container::getHotelSkipRepository();

        $countryCode = ValidationHelpers::toString($params['country'] ?? $state['country'] ?? '');
        $limit = ValidationHelpers::toInt($params['limit'] ?? 0);
        $batchSize = ValidationHelpers::toInt($params['batch_size'] ?? 200);
        $retrySkipped = ValidationHelpers::toString($params['retry_skipped'] ?? '');

        // Handle retry_skipped: reset product_skip_reason so hotels become eligible again
        if ($retrySkipped !== '') {
            $reason = ($retrySkipped === '1') ? '' : $retrySkipped;
            $reset = $skipRepo->resetSkipped($countryCode, $reason);
            $filter = $countryCode !== '' ? " for country {$countryCode}" : '';
            $reasonFilter = $reason !== '' ? " with reason '{$reason}'" : '';
            $this->output("Reset {$reset} previously skipped hotels{$filter}{$reasonFilter}. They are now eligible for product creation.");
        }

        // Pre-flight: check required configuration before processing any hotels
        $rootCategoryId = ConfigProvider::getHotelsCategoryId();
        if ($rootCategoryId <= 0) {
            $this->output('ERROR: hotels_category_id is not configured. Set a root category in Sphinx Holidays > Settings > Product Creation & Mapping.');
            $this->clearState();
            return ['success' => false, 'stats' => [
                'added' => 0, 'skipped' => 0, 'failed' => 0, 'invalid_country' => 0, 'total' => 0,
                'error' => 'hotels_category_id not configured',
            ]];
        }

        // Ensure the shared feature map and Sphinx provider aliases are present
        // before any product features are assigned. Seeding is idempotent
        // (INSERT IGNORE / addAlias upsert) and self-heals installs where the
        // canonical map or Sphinx aliases were incomplete when the addon was
        // installed (or where new feature types/codes were added afterwards).
        // Without this, FeatureMapper::resolve('sphinx', ...) returns null and
        // SphinxFeatureAssigner silently skips the feature, so products end up
        // with only the few features that happened to resolve (e.g. region,
        // property type). Mirrors the Novoton add_hotels_as_products seeding.
        $this->seedFeatureMappings();

        $factory->loadValidCountryCodes();

        if (!$destRepo->loadParentLookup()) {
            $this->output('WARNING: sphinx_destinations is empty — run destination sync first. Category paths will use hotel fields only.');
        }

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $hotelRepo->findUnlinked($countryCode, min($remaining, $effectiveBatch));
            if (empty($hotels)) {
                break;
            }

            if ($state['total'] === 0 && $processed === 0) {
                $this->output("Processing unlinked hotels in batches of {$effectiveBatch}...");
            }

            $stateTotal = ValidationHelpers::toInt($state['total'] ?? 0);
            $stateTotal += count($hotels);
            $state['total'] = $stateTotal;

            // Resolve destination hierarchies for this batch
            $destinationIds = array_values(array_filter(array_unique(
                array_map(
                    static fn ($v): int => TypeCoerce::toInt($v),
                    array_column($hotels, 'destination_id'),
                ),
            )));
            $hierarchyMap = !empty($destinationIds) ? $destRepo->resolveHierarchies($destinationIds) : [];

            foreach ($hotels as $hotel) {
                $hotelId = ValidationHelpers::toString($hotel['hotel_id'] ?? '');
                $hotelName = ValidationHelpers::toString($hotel['name'] ?? '');
                $destId = ValidationHelpers::toInt($hotel['destination_id'] ?? 0);
                $hierarchy = $hierarchyMap[$destId] ?? [];

                $result = $factory->createFromHotel($hotel, $hierarchy);

                $resultStatus = ValidationHelpers::toString($result['status']);
                $resultProductId = ValidationHelpers::toString($result['product_id']);
                $resultReason = ValidationHelpers::toString($result['reason']);

                $this->output("[{$hotelId}] {$hotelName} ... " . strtoupper($resultStatus)
                    . ($resultProductId !== '' ? " (ID: {$resultProductId})" : '')
                    . ($resultReason !== '' ? " ({$resultReason})" : ''));

                $stateAdded = ValidationHelpers::toInt($state['added'] ?? 0);
                $stateSkipped = ValidationHelpers::toInt($state['skipped'] ?? 0);
                $stateFailed = ValidationHelpers::toInt($state['failed'] ?? 0);
                $stateInvalidCountry = ValidationHelpers::toInt($state['invalid_country'] ?? 0);

                match ($resultStatus) {
                    'added' => $state['added'] = $stateAdded + 1,
                    'linked' => $state['skipped'] = $stateSkipped + 1,
                    'skipped' => str_contains($resultReason, 'invalid country')
                        ? $state['invalid_country'] = $stateInvalidCountry + 1
                        : $state['failed'] = $stateFailed + 1,
                    default => $state['failed'] = $stateFailed + 1,
                };
            }

            $processed += count($hotels);

            // Save state after every batch (critical for resume)
            $state['last_run_at'] = date('Y-m-d H:i:s');
            $this->saveState($state);

            // Clear CS-Cart internal caches to prevent OOM on large runs
            // fn_update_product() accumulates registry/cache data per product
            \Tygh\Registry::del('runtime.product_descriptions');
            \Tygh\Registry::del('seo_cache');
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        FeatureMapper::clearCache();

        // Diagnostic: if nothing was processed, check for skipped hotels
        if (ValidationHelpers::toInt($state['total'] ?? 0) === 0 && $processed === 0) {
            $skippedCount = $skipRepo->countSkipped();
            if ($skippedCount > 0) {
                $byReason = $this->getSkippedBreakdown();
                $this->output("No eligible hotels found, but {$skippedCount} hotel(s) were previously skipped:");
                foreach ($byReason as $reason => $count) {
                    $this->output("  - {$reason}: {$count}");
                }
                $this->output('Run with retry_skipped=1 to make them eligible again.');
                $this->output('  Or use: &cron_mode=add_products&retry_skipped=1');
            }
            $this->outputWaiting($skipRepo);
        }

        $finalAdded = ValidationHelpers::toInt($state['added'] ?? 0);
        $finalSkipped = ValidationHelpers::toInt($state['skipped'] ?? 0);
        $finalFailed = ValidationHelpers::toInt($state['failed'] ?? 0);
        $finalInvalidCountry = ValidationHelpers::toInt($state['invalid_country'] ?? 0);
        $finalTotal = ValidationHelpers::toInt($state['total'] ?? 0);

        if ($finalInvalidCountry > 0) {
            $this->output("WARNING: {$finalInvalidCountry} hotels skipped — country codes not in CS-Cart. Check sync health log.");
            $this->output('After enabling the missing countries in CS-Cart, run: cron_mode=add_products&retry_skipped=invalid_country');
        }

        $this->output("Done: {$finalAdded} added, {$finalSkipped} skipped, {$finalFailed} failed, {$finalInvalidCountry} invalid country.");

        // Mark sync as complete, clear state
        $this->clearState();

        return ['success' => true, 'stats' => [
            'added' => $finalAdded,
            'skipped' => $finalSkipped,
            'failed' => $finalFailed,
            'invalid_country' => $finalInvalidCountry,
            'total' => $finalTotal,
        ]];
    }

    /**
     * Diagnose category creation: check root category + country sub-category.
     * Does NOT create anything — read-only diagnostic.
     *
     * Usage: &cron_mode=add_products&debug=1
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function debugCategoryCreation(array $params): array
    {
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();

        $this->output('=== CATEGORY CREATION DIAGNOSTIC ===');
        $this->output('');

        // 1. Show CART_LANGUAGE
        $cartLang = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : '(undefined)';
        $this->output("CART_LANGUAGE = '{$cartLang}'");

        // 2. Show active languages
        $languages = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
        $this->output('Active languages: ' . implode(', ', $languages));
        $this->output('');

        // 3. Show configured root category IDs
        $rootIds = [
            'hotels' => ConfigProvider::getHotelsCategoryId(),
            'packages' => ConfigProvider::getPackagesCategoryId(),
            'circuits' => ConfigProvider::getCircuitsCategoryId(),
            'experiences' => ConfigProvider::getExperiencesCategoryId(),
        ];
        $this->output('--- Root Category IDs (from addon settings) ---');
        foreach ($rootIds as $type => $id) {
            $name = $id > 0 ? TypeCoerce::toString(db_get_field(
                'SELECT category FROM ?:category_descriptions WHERE category_id = ?i AND lang_code = ?s',
                $id,
                $cartLang,
            )) : '(not set)';
            $status = $id > 0 ? ($name !== '' ? 'OK' : 'MISSING in DB') : 'NOT CONFIGURED';
            $this->output("  {$type}: category_id={$id} name=\"{$name}\" [{$status}]");
        }
        $this->output('');

        // 4. Show feature IDs for region/city
        $this->output('--- Feature IDs (from travel_core settings) ---');
        $regionFeatureId = TravelCoreConfig::getFeatureId('region');
        $cityFeatureId = TravelCoreConfig::getFeatureId('city');
        $this->output("  feature_id_region = {$regionFeatureId}" . ($regionFeatureId > 0 ? ' (OK)' : ' (NOT CONFIGURED)'));
        $this->output("  feature_id_city = {$cityFeatureId}" . ($cityFeatureId > 0 ? ' (OK)' : ' (NOT CONFIGURED)'));
        $this->output('');

        // 5. Show existing sub-categories under hotel root
        $hotelRootId = $rootIds['hotels'];
        if ($hotelRootId > 0) {
            $this->output("--- Country sub-categories under hotels root (ID={$hotelRootId}) ---");
            $subs = TypeCoerce::toRowList(db_get_array(
                'SELECT c.category_id, cd.category, c.status
                 FROM ?:categories c
                 JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
                 WHERE c.parent_id = ?i
                 ORDER BY cd.category',
                $cartLang,
                $hotelRootId,
            ));
            if (empty($subs)) {
                $this->output('  (none yet — will be created on first add_products run)');
            } else {
                foreach ($subs as $sub) {
                    $this->output('  [' . ValidationHelpers::toString($sub['category_id'] ?? '') . '] "' . ValidationHelpers::toString($sub['category'] ?? '') . '" status=' . ValidationHelpers::toString($sub['status'] ?? ''));
                }
            }
            $this->output('');
        }

        // 6. Load destination hierarchy
        $destRepo->loadParentLookup();

        // 7. Pick first few unlinked hotels and show how they would be categorized
        $countryCode = ValidationHelpers::toString($params['country'] ?? '');
        $hotels = $hotelRepo->findUnlinked($countryCode, 3);

        if (empty($hotels)) {
            $hotels = TypeCoerce::toRowList(db_get_array(
                "SELECT hotel_id, name, destination_id, country_code, country_name, region_name, destination_name, product_skip_reason
                 FROM ?:sphinx_hotels
                 WHERE product_skip_reason IS NOT NULL AND sync_status = 'active'
                 LIMIT 3",
            ));
            if (!empty($hotels)) {
                $this->output('No unlinked hotels found, showing skipped hotels instead:');
            }
        }

        if (empty($hotels)) {
            $this->output('No hotels found to diagnose.');
            return ['success' => true, 'stats' => ['action' => 'debug']];
        }

        $factory = Container::getProductFactory();
        foreach ($hotels as $hotel) {
            $hotelId = ValidationHelpers::toString($hotel['hotel_id'] ?? '');
            $hotelName = ValidationHelpers::toString($hotel['name'] ?? '');
            $this->output("--- Hotel [{$hotelId}] {$hotelName} ---");
            $this->output('  country_code=' . ValidationHelpers::toString($hotel['country_code'] ?? '') . ', country_name=' . ValidationHelpers::toString($hotel['country_name'] ?? ''));
            $this->output('  region_name=' . ValidationHelpers::toString($hotel['region_name'] ?? '') . ', destination_name=' . ValidationHelpers::toString($hotel['destination_name'] ?? ''));
            $destIdVal = ValidationHelpers::toInt($hotel['destination_id'] ?? 0);
            $this->output("  destination_id={$destIdVal}");
            if (!empty($hotel['product_skip_reason'])) {
                $this->output('  skip_reason=' . ValidationHelpers::toString($hotel['product_skip_reason']));
            }

            // Resolve hierarchy
            $destinationIds = [$destIdVal];
            $hierarchyMap = !empty($destinationIds[0]) ? $destRepo->resolveHierarchies($destinationIds) : [];
            $hierarchy = $hierarchyMap[$destIdVal] ?? [];
            $this->output('  hierarchy: ' . json_encode($hierarchy));

            // Show resolved values
            $countryName = $factory->resolveCountryName($hotel, $hierarchy);
            $this->output("  resolved_country = \"{$countryName}\"");

            if ($countryName === '') {
                $this->output('  SKIP: no country resolved');
                continue;
            }

            // Check if country sub-category exists under root
            if ($hotelRootId > 0) {
                $countryCatId = ValidationHelpers::toInt(db_get_field(
                    'SELECT c.category_id FROM ?:categories c
                     JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
                     WHERE c.parent_id = ?i AND cd.category = ?s LIMIT 1',
                    $cartLang,
                    $hotelRootId,
                    $countryName,
                ));
                if ($countryCatId > 0) {
                    $this->output("  category: root({$hotelRootId}) -> \"{$countryName}\"({$countryCatId}) => FOUND");
                } else {
                    $this->output("  category: root({$hotelRootId}) -> \"{$countryName}\" => NOT FOUND (will be created)");
                }
            }

            $destinationNameVal = ValidationHelpers::toString($hotel['destination_name'] ?? '');
            $this->output('  region -> product feature: "' . ValidationHelpers::toString($hotel['region_name'] ?? '') . '"');
            $this->output("  city -> product feature: \"{$destinationNameVal}\"");
            $this->output('');
        }

        $this->output('=== END DIAGNOSTIC ===');
        return ['success' => true, 'stats' => ['action' => 'debug']];
    }

    /**
     * Show current progress without processing any hotels.
     * @return array<string, mixed>
     */
    private function showStatus(): array
    {
        $state = $this->loadState();

        $stateStatus = ValidationHelpers::toString($state['status'] ?? 'idle');
        if ($stateStatus === 'idle') {
            $this->output('Add Products Status: idle (no run in progress)');

            // Show unlinked count
            $unlinkedCount = ValidationHelpers::toInt(db_get_field(
                "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_id IS NULL AND sync_status = 'active' AND product_skip_reason IS NULL",
            ));
            $this->output("  Unlinked hotels ready: {$unlinkedCount}");

            $skipRepo = Container::getHotelSkipRepository();
            $skippedCount = $skipRepo->countSkipped();
            if ($skippedCount > 0) {
                $this->output("  Previously skipped: {$skippedCount}");
                $byReason = $this->getSkippedBreakdown();
                foreach ($byReason as $reason => $count) {
                    $this->output("    - {$reason}: {$count}");
                }
            }
            $this->outputWaiting($skipRepo);

            return ['success' => true, 'stats' => ['status' => 'idle', 'unlinked' => $unlinkedCount, 'skipped' => $skippedCount]];
        }

        $this->output('Add Products Status:');
        $this->output("  Status: {$stateStatus}");
        $this->output('  Total processed: ' . ValidationHelpers::toInt($state['total'] ?? 0));
        $this->output('  Added: ' . ValidationHelpers::toInt($state['added'] ?? 0));
        $this->output('  Skipped: ' . ValidationHelpers::toInt($state['skipped'] ?? 0));
        $this->output('  Failed: ' . ValidationHelpers::toInt($state['failed'] ?? 0));
        $this->output('  Invalid country: ' . ValidationHelpers::toInt($state['invalid_country'] ?? 0));
        $stateCountry = ValidationHelpers::toString($state['country'] ?? '');
        if ($stateCountry !== '') {
            $this->output("  Country filter: {$stateCountry}");
        }
        $this->output('  Started: ' . ValidationHelpers::toString($state['started_at'] ?? ''));
        $this->output('  Last activity: ' . ValidationHelpers::toString($state['last_run_at'] ?? ''));

        if ($this->isStale($state)) {
            $this->output('  WARNING: State appears stale (no activity for 6+ hours). Run with reset=1 to clear.');
        }

        if (!empty($state['started_at'])) {
            $elapsed = time() - ValidationHelpers::toInt(strtotime(ValidationHelpers::toString($state['started_at'])));
            $this->output('  Elapsed: ' . $this->formatDuration($elapsed));
        }

        return ['success' => true, 'stats' => [
            'status' => $state['status'],
            'total' => $state['total'],
            'added' => $state['added'],
            'failed' => $state['failed'],
        ]];
    }

    /** Hotels the availability gate keeps from becoming products for now. */
    private function outputWaiting(HotelSkipRepository $skipRepo): void
    {
        $waiting = $skipRepo->countSkipped(HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        if ($waiting > 0) {
            $this->output("  {$waiting} hotel(s) wait for an immediate-confirmation offer; the hotels cron makes them eligible when one appears.");
        }
    }

    /**
     * Get breakdown of skipped hotels by reason. Hotels waiting for an
     * immediate offer are left out: retry_skipped=1 does not reset them
     * (HotelSkipRepository::resetSkipped()), see outputWaiting().
     *
     * @return array<string, int> reason => count
     */
    private function getSkippedBreakdown(): array
    {
        $rows = TypeCoerce::toRowList(db_get_array(
            "SELECT product_skip_reason, COUNT(*) AS cnt
             FROM ?:sphinx_hotels
             WHERE product_skip_reason IS NOT NULL AND product_skip_reason <> ?s AND sync_status = 'active'
             GROUP BY product_skip_reason
             ORDER BY cnt DESC",
            HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY,
        ));

        $result = [];
        foreach ($rows as $row) {
            $result[TypeCoerce::toString($row['product_skip_reason'] ?? '')]
                = TypeCoerce::toInt($row['cnt'] ?? 0);
        }
        return $result;
    }

    #[\Override]
    protected function output(string $message, bool $addNewline = true): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message, $addNewline);
        }
    }
}
