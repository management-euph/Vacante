<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Batched Hotel Info Sync (V2)
 *
 * Reimplementation of BatchedHotelInfoSync on top of the
 * AbstractBatchedSync template-method base class. Replaces ~860 lines
 * of bespoke state/retry/progress code with a ~430-line subclass that
 * contains only the per-hotel business logic and four private helpers
 * preserved verbatim from the legacy class.
 *
 * Shipped side-by-side with the legacy class — `BatchedSyncCommand`
 * is NOT yet pointed at V2 for the hotel_info mode. Swap happens in
 * a small follow-up commit (PR #9b).
 *
 * Architectural audit tracking: PR #9 of the roadmap — the last of
 * the three legacy Batched*Sync helpers to be migrated. Relies on the
 * hooks added to AbstractBatchedSync in PR #6b (preBatch, retry, CLI).
 *
 * Notable design choices
 * ----------------------
 * 1. curl_multi batch fetch:
 *    `$api->getHotelInfoBatch($batch)` runs in preBatch(), caching
 *    the result map in `$this->batchResults`. processItem() reads
 *    from that cache instead of fetching per-hotel — preserving the
 *    single-batched-fetch-per-page optimisation from the legacy class.
 *
 * 2. Retry-path fallback:
 *    The base class's retryFailedItems() bypasses preBatch() and calls
 *    processItem() directly for each error_id. When processItem() sees
 *    an empty cache for the current hotel, it calls
 *    `$this->preBatch([$hotelId])` to populate caches + batch result
 *    for a single item — effectively a single-hotel curl_multi call
 *    which is structurally identical to the legacy retry's dedicated
 *    single-hotel getHotelInfo fetch.
 *
 * 3. reconcileProductLinks():
 *    The legacy class's run() flow runs this before determineSyncType().
 *    The base class doesn't expose a pre-run hook, so V2 calls it from
 *    the top of determineSyncType() instead — which runs on fresh
 *    sync starts and is skipped on resume, exactly like the legacy.
 *
 * 4. Large-sync DB-pagination mode:
 *    Dropped. Legacy used DB pagination for hotel sets > 5000 to avoid
 *    a large state file. V2 stores every item in `state['item_ids']`;
 *    worst-case ~400 KB state file is acceptable and simplifies the
 *    class by ~100 LOC.
 *
 * @package NovotonHolidays
 * @since   3.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\AdultOnlyDetector;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class BatchedHotelInfoSyncV2 extends AbstractBatchedSync
{
    /** Full-sync interval in seconds (from ConfigProvider). */
    private readonly int $fullSyncInterval;

    /** Adults-only detector (hotel-name-based classification). */
    private readonly AdultOnlyDetector $adultOnlyDetector;

    /** Parses package data out of the hotel-info XML response. */
    private readonly HotelPackageExtractor $packageExtractor;

    /** Reconciles hotel<->product links at the start of a fresh sync. */
    private readonly HotelProductLinkReconciler $linkReconciler;

    /** Detects changed hotels for incremental sync via offers_update. */
    private readonly ChangedHotelDetector $changedHotelDetector;

    /**
     * Product code prefixes from config — e.g. `['NVT', 'NV']`.
     * Cached in constructor so preBatch() and processHotelInfo() don't
     * re-query ConfigProvider on every call.
     *
     * @var string[]
     */
    private readonly array $productCodePrefixes;

    /**
     * Per-batch cache: hotel_id → ['hotel_name' => string, 'product_id' => int|null].
     * Populated in preBatch(), consumed by processItem() + processHotelInfo().
     *
     * @var array<string, array<string, mixed>>
     */
    private array $hotelMap = [];

    /**
     * Per-batch cache: product_code → product_id.
     * Built from hotels with NULL product_id so processHotelInfo() can
     * auto-link them without per-hotel queries.
     *
     * @var array<string, int>
     */
    private array $productCodeMap = [];

    /**
     * Per-batch cache: hotel_id → SimpleXMLElement|false.
     * Populated by the curl_multi getHotelInfoBatch() call inside
     * preBatch(). processItem() reads from here instead of re-fetching.
     *
     * @var array<string, \SimpleXMLElement|false>
     */
    private array $batchResults = [];

    public function __construct(
        ?StateManagerInterface $state = null,
        ?SyncLoggerInterface $logger = null,
    ) {
        parent::__construct($state, $logger);
        $this->fullSyncInterval = ConfigProvider::getSyncIntervalHotelInfo();
        $this->adultOnlyDetector = new AdultOnlyDetector();
        $this->packageExtractor = new HotelPackageExtractor();
        $this->productCodePrefixes = ConfigProvider::getProductCodePrefixes();
        $this->linkReconciler = new HotelProductLinkReconciler($this->logger, $this->productCodePrefixes);
        $this->changedHotelDetector = new ChangedHotelDetector($this->logger);
    }

    #[\Override]
    protected function getSyncName(): string
    {
        // Matches the legacy state file path:
        //   {cache_misc}/novoton/batch_hotelinfo_state.json
        return 'hotelinfo';
    }

    /**
     * Opt in to the base class's one-shot retry of error_ids at the
     * end of the main processing loop. Matches the legacy behaviour.
     */
    #[\Override]
    protected function shouldRetryFailedItems(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected function determineSyncType(array $options): string
    {
        // The legacy run() flow reconciles product links before deciding
        // the sync type. determineSyncType() is only called on fresh sync
        // starts (not on resume), which matches legacy semantics exactly.
        $this->linkReconciler->reconcile();

        if (!empty($options['force_full'])) {
            return 'full';
        }

        // Look only at completed *full* syncs (notes JSON contains sync_type: full).
        $lastFullSync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'
             AND notes LIKE '%\"sync_type\":\"full\"%'",
        );

        if (empty($lastFullSync)) {
            $this->logger->output('No previous full sync found. Starting full sync.');
            return 'full';
        }

        $timeSinceFull = time() - (int) strtotime(TypeCoerce::toString($lastFullSync));

        if ($timeSinceFull > $this->fullSyncInterval) {
            $days = round($timeSinceFull / 86400);
            $this->logger->output("Last full sync was {$days} days ago. Starting full sync.");
            return 'full';
        }

        // Incremental: last completed sync of any kind.
        $lastIncremental = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotelinfo' AND status = 'completed'",
        );

        if (empty($lastIncremental)) {
            return 'full';
        }

        // More than 24 hours since the last completed sync → incremental.
        $timeSinceLast = time() - (int) strtotime(TypeCoerce::toString($lastIncremental));
        if ($timeSinceLast > 24 * 3600) {
            return 'incremental';
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    #[\Override]
    protected function getItemsToSync(string $syncType, array $options): array
    {
        $countries = TypeCoerce::toStringList($options['countries'] ?? ConfigProvider::getSelectedCountries());
        // The destinations we sell (+ hotels with live products); the selected
        // countries until a whitelist is saved. One hotelinfo call per hotel.
        $scope = DestinationScope::current()->syncWhere();

        if ($syncType === 'full') {
            // &country= narrows the run; otherwise the scope alone decides,
            // so a live product in a country no longer sold stays fresh too.
            return TypeCoerce::toStringList(db_get_fields(
                'SELECT hotel_id FROM ?:novoton_hotels
                 WHERE ' . (isset($options['countries']) ? db_quote('country IN (?a) AND ', $countries) : '') . $scope . '
                 ORDER BY hotel_name',
            ));
        }

        // Incremental: changed hotels from offers_update API, unioned
        // with hotels that never had hotelinfo synced yet — minus the known
        // hotels outside the scope. A hotel not in ?:novoton_hotels yet has
        // no resort to judge by, so it stays.
        $changed = $this->changedHotelDetector->detect($this->getApi(), $countries);
        if ($changed === []) {
            return [];
        }
        $outside = TypeCoerce::toStringList(db_get_fields(
            'SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id IN (?a) AND NOT ' . $scope,
            $changed,
        ));

        return array_values(array_diff($changed, $outside));
    }

    /**
     * Pre-fetch EVERYTHING needed for a batch in a single place:
     *   - hotel_name + product_id per hotel  (one DB query)
     *   - product_code → product_id for unlinked hotels (one DB query)
     *   - hotel info XML per hotel, via curl_multi (one batched API call)
     *
     * Eliminates every N+1 the legacy class built up across its main
     * loop and per-item processing.
     *
     * @param array<int|string, mixed> $batch
     */
    #[\Override]
    protected function preBatch(array $batch): void
    {
        $this->hotelMap = [];
        $this->productCodeMap = [];
        $this->batchResults = [];

        if (empty($batch)) {
            return;
        }

        $hotelIds = array_values(array_map(
            static fn (mixed $id): string => TypeCoerce::toString($id),
            $batch,
        ));

        // 1. Hotel metadata (hotel_name, product_id) keyed by hotel_id.
        //    toArrayMap, NOT toStringMap: db_get_hash_array keys by the numeric
        //    hotel_id (int array keys), which toStringMap would drop — leaving
        //    hotelMap empty and silently disabling adults-only-from-name below.
        $hotelRows = TypeCoerce::toArrayMap(db_get_hash_array(
            'SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels WHERE hotel_id IN (?a)',
            'hotel_id',
            $hotelIds,
        ));
        foreach ($hotelRows as $hid => $row) {
            $this->hotelMap[(string) $hid] = TypeCoerce::toStringMap($row);
        }

        // 2. Product code -> product_id map for unlinked hotels only.
        $codePatterns = [];
        foreach ($hotelIds as $hid) {
            if (empty($this->hotelMap[$hid]['product_id'])) {
                foreach ($this->productCodePrefixes as $pfx) {
                    $codePatterns[] = $pfx . $hid;
                }
            }
        }
        if (!empty($codePatterns)) {
            $codeRows = TypeCoerce::toStringMap(db_get_hash_single_array(
                'SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)',
                ['product_code', 'product_id'],
                $codePatterns,
            ));
            foreach ($codeRows as $code => $pid) {
                $this->productCodeMap[$code] = TypeCoerce::toInt($pid);
            }
        }

        // 3. curl_multi parallel fetch of hotel info for the whole batch.
        $this->batchResults = $this->getApi()->hotels()->getHotelInfoBatch($hotelIds);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function processItem($itemId): array
    {
        $hotelId = (string) $itemId;

        // Retry-path fallback: the base class's retryFailedItems() calls
        // processItem() directly without invoking preBatch(), so our
        // caches may be empty for this item. Populate them for just
        // this hotel — effectively a single-hotel curl_multi call which
        // is structurally equivalent to the legacy retry's dedicated
        // getHotelInfo() call.
        if (!isset($this->hotelMap[$hotelId]) || !array_key_exists($hotelId, $this->batchResults)) {
            $this->preBatch([$hotelId]);
        }

        $hotelName = TypeCoerce::toString($this->hotelMap[$hotelId]['hotel_name'] ?? '?');
        $hotelInfo = $this->batchResults[$hotelId] ?? false;

        $this->logger->output("[{$hotelId}] {$hotelName} ... ", false);

        if ($hotelInfo === false) {
            $this->logger->output('API returned empty');
            return ['success' => false, 'message' => 'api_returned_empty', 'data' => null];
        }

        try {
            $this->processHotelInfo(
                $hotelId,
                $hotelInfo,
                date('Y-m-d H:i:s'),
                $this->hotelMap,
                $this->productCodeMap,
                $this->productCodePrefixes,
            );
        } catch (ApiException $e) {
            $this->logger->output('ERROR: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Throwable $e) {
            $this->logger->output('ERROR: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $packagesCount = $this->packageExtractor->countPackages($hotelInfo);
        $this->logger->output("OK ({$packagesCount} packages)");

        return [
            'success' => true,
            'message' => '',
            'data' => ['packages_count' => $packagesCount],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Private helpers — preserved verbatim from the legacy class, with
    // only the array-argument list reformatted to fit typed parameters.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Process hotel info from an API response.
     *
     * Writes hotel_data JSON, detects adults-only from the hotel name,
     * links unlinked products via the pre-fetched product_code_map, and
     * upserts every package in a single transactional batch INSERT.
     *
     * @param string $hotelId Hotel ID
     * @param \SimpleXMLElement $hotelInfo SimpleXML hotel info from the API
     * @param string $now Current timestamp
     * @param array<string, array<string, mixed>> $hotelMap hotel_id → [hotel_name, product_id] map
     * @param array<string, int> $productCodeMap product_code → product_id map
     * @param string[] $prefixes Product code prefixes
     */
    private function processHotelInfo(
        string $hotelId,
        \SimpleXMLElement $hotelInfo,
        string $now,
        array $hotelMap = [],
        array $productCodeMap = [],
        array $prefixes = ['NVT'],
    ): void {
        $hotelDataJson = json_encode($hotelInfo);
        if ($hotelDataJson === false) {
            $hotelDataJson = null;
        }

        $update = [
            'hotelinfo_synced_at' => $now,
            'hotel_data' => $hotelDataJson,
        ];

        // Detect adults-only from hotel name
        $hotelName = TypeCoerce::toString($hotelMap[$hotelId]['hotel_name'] ?? '');
        if ($hotelName !== '' && $this->adultOnlyDetector->detect($hotelName)) {
            $update['is_adults_only'] = 'Y';
        }

        // Link product if not already linked — use pre-fetched maps (no extra queries)
        $currentProductId = $hotelMap[$hotelId]['product_id'] ?? null;

        if (empty($currentProductId)) {
            foreach ($prefixes as $prefix) {
                $pid = $productCodeMap[$prefix . $hotelId] ?? null;
                if (!empty($pid)) {
                    $update['product_id'] = $pid;
                    break;
                }
            }
        }

        // Extract package_name
        $packageName = $this->packageExtractor->extractPackageName($hotelInfo);
        if ($packageName !== '') {
            $update['package_name'] = $packageName;
        }

        // Extract and store packages (has_room_price is set exclusively by room_price check)
        $packages = $this->packageExtractor->extractPackages($hotelInfo);
        $update['packages_count'] = count($packages);

        // Wrap hotel + packages update in a transaction for atomicity
        db_query('START TRANSACTION');
        try {
            // Update hotel record
            db_query('UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s', $update, $hotelId);

            // Batch INSERT packages (multi-row upsert instead of N individual queries)
            $validPackages = array_filter($packages, static fn (array $pkg): bool => !empty($pkg['IdCont']));
            if (!empty($validPackages)) {
                $values = [];
                $params = [];
                foreach ($validPackages as $pkg) {
                    $values[] = '(?s, ?s, ?s, NOW())';
                    $params[] = $hotelId;
                    $params[] = $pkg['IdCont'];
                    $params[] = $pkg['PackageName'];
                }
                $sql = 'INSERT INTO ?:novoton_hotel_packages (hotel_id, package_id, package_name, created_at) VALUES '
                    . implode(', ', $values)
                    . ' ON DUPLICATE KEY UPDATE package_name = VALUES(package_name)';
                db_query($sql, ...$params);
            }

            db_query('COMMIT');
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }
}
