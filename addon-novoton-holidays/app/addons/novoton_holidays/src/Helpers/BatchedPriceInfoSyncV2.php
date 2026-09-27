<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Batched Price Info Sync (V2)
 *
 * Reimplementation of BatchedPriceInfoSync on top of the
 * AbstractBatchedSync template-method base class. Replaces ~660 lines
 * of bespoke state/retry/progress code with a ~300-line subclass that
 * only contains per-package business logic and two private helpers
 * preserved verbatim from the legacy class.
 *
 * Shipped side-by-side with the legacy class — `BatchedSyncCommand`
 * is NOT yet pointed at V2 for the price_info mode. Swap happens in
 * a small follow-up commit (PR #8b).
 *
 * Composite item IDs
 * ------------------
 * Each sync item is a (hotel_id, package_id) pair, which doesn't fit
 * StateManager's flat `item_ids` array directly. V2 encodes the pair
 * as the string "hotelId/packageId" — the same format the legacy
 * class already used for its retry error_ids. `processItem()` splits
 * the string back into its components.
 *
 * Architectural audit tracking: PR #8 of the roadmap. Relies on the
 * hooks added to AbstractBatchedSync in PR #6b (preBatch, retry, CLI).
 *
 * @package NovotonHolidays
 * @since   3.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class BatchedPriceInfoSyncV2 extends AbstractBatchedSync
{
    /** Full-sync interval in seconds (from ConfigProvider). */
    private readonly int $fullSyncInterval;

    /** Stale threshold in hours for the incremental branch of determineSyncType(). */
    private int $staleHours = 24;

    /**
     * Per-batch cache: "hotelId/packageId" → package_name.
     * Populated in preBatch() so every processItem() call gets the
     * name without re-querying the database.
     *
     * @var array<string, string>
     */
    private array $packageNameCache = [];

    public function __construct(
        ?StateManagerInterface $state = null,
        ?SyncLoggerInterface $logger = null,
    ) {
        parent::__construct($state, $logger);
        $this->fullSyncInterval = ConfigProvider::getSyncIntervalPriceInfo();
    }

    /**
     * Setter retained for backward compatibility with BatchedSyncCommand,
     * which calls `$sync->setStaleHours($n)` directly before `run()`.
     *
     * Not part of SyncInterface — callers must hold a concrete
     * BatchedPriceInfoSyncV2 reference. Programmatic callers can also
     * pass `stale_hours` inside the `$options` array to run().
     */
    public function setStaleHours(int $hours): void
    {
        $this->staleHours = max(1, min(168, $hours));
    }

    #[\Override]
    protected function getSyncName(): string
    {
        // Matches the legacy state file path:
        //   {cache_misc}/novoton/batch_priceinfo_state.json
        return 'priceinfo';
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
        // Programmatic callers can override stale_hours via run() options.
        // The cron command sets it via the setter instead; either path works.
        if (!empty($options['stale_hours'])) {
            $this->setStaleHours(TypeCoerce::toInt($options['stale_hours']));
        }

        if (!empty($options['force_full'])) {
            return 'full';
        }

        // Look only at completed *full* syncs (notes JSON contains sync_type: full).
        $lastFullSync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'sync_priceinfo' AND status = 'completed'
             AND notes LIKE '%\"sync_type\":\"full\"%'",
        );

        if (empty($lastFullSync)) {
            $this->logger->output('No previous full sync found. Starting full sync.');
            return 'full';
        }

        $timeSinceFull = time() - strtotime(TypeCoerce::toString($lastFullSync));

        if ($timeSinceFull > $this->fullSyncInterval) {
            $days = round($timeSinceFull / 86400);
            $this->logger->output("Last full sync was {$days} days ago. Starting full sync.");
            return 'full';
        }

        // Incremental branch: count stale packages.
        $staleCount = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotel_packages
             WHERE synced_at IS NULL OR synced_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)',
            $this->staleHours,
        ));

        if ($staleCount > 0) {
            $this->logger->output("Found {$staleCount} stale packages (older than {$this->staleHours}h).");
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
        // The destinations we sell (+ hotels with live products); the selected
        // countries until a whitelist is saved. One priceinfo call per package.
        $scope = DestinationScope::current()->syncWhere('h');

        if ($syncType === 'full') {
            $rows = db_get_array(
                'SELECT p.hotel_id, p.package_id
                 FROM ?:novoton_hotel_packages p
                 JOIN ?:novoton_hotels h ON p.hotel_id = h.hotel_id
                 WHERE ' . $scope . '
                 ORDER BY h.hotel_name, p.package_name',
            );
        } else {
            $rows = db_get_array(
                'SELECT p.hotel_id, p.package_id
                 FROM ?:novoton_hotel_packages p
                 JOIN ?:novoton_hotels h ON p.hotel_id = h.hotel_id
                 WHERE ' . $scope . '
                 AND (p.synced_at IS NULL OR p.synced_at < DATE_SUB(NOW(), INTERVAL ?i HOUR))
                 ORDER BY p.synced_at ASC, h.hotel_name',
                $this->staleHours,
            );
        }

        // Encode composite (hotel_id, package_id) as "hotelId/packageId" string.
        return array_map(
            static fn (array $row): string => TypeCoerce::toString($row['hotel_id'] ?? '')
                . '/' . TypeCoerce::toString($row['package_id'] ?? ''),
            TypeCoerce::toRowList($rows),
        );
    }

    /**
     * Pre-fetch package names for the whole batch in one query.
     *
     * The legacy class builds a complex OR-WHERE to look up (hotel_id,
     * package_id) pairs — we preserve that exact pattern here to keep
     * the SQL equivalent. Results are cached keyed by "hotelId/packageId".
     *
     * @param array<int|string, mixed> $batch
     */
    #[\Override]
    protected function preBatch(array $batch): void
    {
        $this->packageNameCache = [];

        if (empty($batch)) {
            return;
        }

        $whereParts = [];
        $whereParams = [];
        foreach ($batch as $itemId) {
            [$hotelId, $packageId] = $this->splitItemId(TypeCoerce::toString($itemId));
            if ($hotelId === '' || $packageId === '') {
                continue;
            }
            $whereParts[] = '(hotel_id = ?s AND package_id = ?s)';
            $whereParams[] = $hotelId;
            $whereParams[] = $packageId;
        }

        if (empty($whereParts)) {
            return;
        }

        $rows = db_get_array(
            'SELECT hotel_id, package_id, package_name FROM ?:novoton_hotel_packages WHERE ' . implode(' OR ', $whereParts),
            ...$whereParams,
        );

        foreach (TypeCoerce::toRowList($rows) as $row) {
            $key = TypeCoerce::toString($row['hotel_id'] ?? '') . '/' . TypeCoerce::toString($row['package_id'] ?? '');
            $this->packageNameCache[$key] = TypeCoerce::toString($row['package_name'] ?? '');
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function processItem($itemId): array
    {
        $key = (string) $itemId;
        [$hotelId, $packageId] = $this->splitItemId($key);

        if ($hotelId === '' || $packageId === '') {
            return ['success' => false, 'message' => 'invalid_item_id', 'data' => null];
        }

        $packageName = $this->packageNameCache[$key] ?? '';

        // Fallback lookup if preBatch didn't cover the item (e.g. during
        // retryFailedItems() which bypasses the batch loader).
        if ($packageName === '') {
            $pkg = db_get_field(
                'SELECT package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s',
                $hotelId,
                $packageId,
            );
            $packageName = is_string($pkg) ? $pkg : '';
        }

        if ($packageName === '' || $packageName === '?') {
            $this->logger->output("[{$hotelId}/{$packageId}] SKIP (no package_name)");
            return ['success' => false, 'message' => 'no_package_name', 'data' => null];
        }

        $this->logger->output("[{$hotelId}/{$packageId}] {$packageName} ... ", false);

        try {
            // The Novoton API requires PackageName, not package_id (IdCont).
            $priceInfo = $this->getApi()->pricing()->getPriceInfo($hotelId, $packageName);
        } catch (ApiException $e) {
            $this->logger->output('ERROR: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        if (empty($priceInfo)) {
            $this->logger->output('API returned empty');
            return ['success' => false, 'message' => 'api_returned_empty', 'data' => null];
        }

        $seasonsCount = $this->processPriceInfo($hotelId, $packageId, $priceInfo, date('Y-m-d H:i:s'));
        $this->logger->output("OK ({$seasonsCount} seasons)");

        return [
            'success' => true,
            'message' => '',
            'data' => ['seasons_count' => $seasonsCount],
        ];
    }

    /**
     * Split the composite item ID back into (hotelId, packageId).
     *
     * @return array{0: string, 1: string}
     */
    private function splitItemId(string $itemId): array
    {
        $parts = explode('/', $itemId, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * Process price info from an API response.
     *
     * Stores raw priceinfo JSON and flags the package for recomputation
     * by the compute_prices cron. Does NOT compute min_price, seasons_count
     * or has_early_booking inline — that's the cron's job.
     *
     * Logic preserved verbatim from the legacy BatchedPriceInfoSync.
     * @param \SimpleXMLElement $priceinfo
     */
    private function processPriceInfo(string $hotelId, string $packageId, $priceinfo, string $now): int
    {
        // Count seasons for the return value (lightweight, used for output only)
        $seasonsCount = isset($priceinfo->seasons) ? count((array) $priceinfo->seasons) : 0;

        // Convert SimpleXML to array for reliable JSON encoding.
        // json_encode(SimpleXMLElement) can lose attributes and mishandle repeated siblings.
        $priceinfoArray = self::simpleXmlToArray($priceinfo);
        $priceinfoJson = json_encode($priceinfoArray);

        if ($priceinfoJson === false || $priceinfoJson === 'null') {
            // Fallback to direct encode if conversion failed
            $priceinfoJson = json_encode($priceinfo);
        }

        db_query(
            "UPDATE ?:novoton_hotel_packages SET
             priceinfo_data = ?s,
             needs_price_compute = 'Y',
             synced_at = ?s
             WHERE hotel_id = ?s AND package_id = ?s",
            $priceinfoJson,
            $now,
            $hotelId,
            $packageId,
        );

        return $seasonsCount;
    }

    /**
     * Reliably convert SimpleXMLElement to associative array.
     *
     * Handles repeated siblings (same-named elements) as arrays,
     * preserves text content, and includes attributes.
     *
     * Preserved verbatim from the legacy BatchedPriceInfoSync.
     * @param \SimpleXMLElement $xml
     * @return array<string, mixed>
     */
    private static function simpleXmlToArray($xml): array
    {
        $result = [];

        // Include attributes
        foreach ($xml->attributes() as $attrName => $attrValue) {
            $result['@' . $attrName] = (string) $attrValue;
        }

        // Process child elements
        foreach ($xml->children() as $name => $child) {
            $value = ($child->count() > 0) ? self::simpleXmlToArray($child) : (string) $child;

            // Handle repeated siblings: convert to array
            if (isset($result[$name])) {
                if (!is_array($result[$name]) || !isset($result[$name][0])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
