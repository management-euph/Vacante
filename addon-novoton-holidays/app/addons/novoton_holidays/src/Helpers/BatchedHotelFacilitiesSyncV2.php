<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Batched Hotel Facilities Sync (V2)
 *
 * Reimplementation of BatchedHotelFacilitiesSync on top of the
 * AbstractBatchedSync template-method base class. Replaces ~500 lines
 * of bespoke state/retry/progress code with a ~200-line subclass that
 * only contains the per-hotel business logic.
 *
 * Shipped side-by-side with the legacy class — `FacilitiesBatchSyncCommand`
 * is NOT yet pointed at V2. Swap happens in a small follow-up PR once
 * reviewers have verified equivalence.
 *
 * Architectural audit tracking: PR #7 of the roadmap. Relies on the
 * hooks added to AbstractBatchedSync in PR #6b (preBatch, retry, CLI).
 *
 * @package NovotonHolidays
 * @since   3.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\NovotonNormalizer;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Addons\NovotonHolidays\Services\FeatureMapper as NovotonFeatureMapper;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper as CoreFeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;

class BatchedHotelFacilitiesSyncV2 extends AbstractBatchedSync
{
    /** Full-sync interval in seconds (e.g. 30 days). */
    private readonly int $fullSyncInterval;

    /**
     * Per-batch cache: hotel_id → hotel_name. Populated in preBatch()
     * so every processItem() call gets the name without an extra query.
     *
     * @var array<string, string>
     */
    private array $hotelNameCache = [];

    public function __construct(
        ?StateManagerInterface $state = null,
        ?SyncLoggerInterface $logger = null,
    ) {
        parent::__construct($state, $logger);
        $this->fullSyncInterval = ConfigProvider::getSyncIntervalFacilities();
    }

    #[\Override]
    protected function getSyncName(): string
    {
        return 'hotel_facilities';
    }

    /**
     * Opt in to the single-pass retry of failed items at the end of
     * the main processing loop. Matches the legacy class behaviour.
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
        if (!empty($options['force_full'])) {
            return 'full';
        }

        $lastSync = db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log
             WHERE sync_type = 'hotel_facilities' AND status = 'completed'",
        );

        if (empty($lastSync)) {
            $this->logger->output('No previous hotel facilities sync found. Starting full sync.');
            return 'full';
        }

        $timeSince = time() - strtotime(TypeCoerce::toString($lastSync));

        if ($timeSince > $this->fullSyncInterval) {
            $days = round($timeSince / 86400);
            $this->logger->output("Last sync was {$days} days ago. Starting full sync.");
            return 'full';
        }

        // Check for hotels that never had facilities synced
        $unsynced = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_facilities hf ON h.hotel_id = hf.hotel_id
             WHERE ' . DestinationScope::current()->syncWhere('h') . ' AND hf.hotel_id IS NULL',
        ));

        if ($unsynced > 0) {
            $this->logger->output("{$unsynced} hotels have no facilities. Starting incremental sync.");
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
        // countries until a whitelist is saved.
        $scope = DestinationScope::current();

        if ($syncType === 'full') {
            return TypeCoerce::toStringList(db_get_fields(
                'SELECT hotel_id FROM ?:novoton_hotels
                 WHERE ' . $scope->syncWhere() . '
                 ORDER BY hotel_name',
            ));
        }

        // Incremental — only hotels without any facilities
        return TypeCoerce::toStringList(db_get_fields(
            'SELECT h.hotel_id FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_facilities hf ON h.hotel_id = hf.hotel_id
             WHERE ' . $scope->syncWhere('h') . ' AND hf.hotel_id IS NULL
             ORDER BY h.hotel_name',
        ));
    }

    /**
     * Pre-fetch hotel names for the whole batch in one query.
     * Avoids an N+1 lookup inside the processItem() output line.
     *
     * @param array<int|string, mixed> $batch
     */
    #[\Override]
    protected function preBatch(array $batch): void
    {
        $hotelIds = TypeCoerce::toStringList($batch);
        if (empty($hotelIds)) {
            $this->hotelNameCache = [];
            return;
        }

        // toArrayMap, NOT toStringMap: db_get_hash_single_array keys by the
        // numeric hotel_id, which PHP stores as int array keys — toStringMap
        // would drop every one and empty the cache (all hotels then print "?").
        $rows = TypeCoerce::toArrayMap(db_get_hash_single_array(
            'SELECT hotel_id, hotel_name FROM ?:novoton_hotels WHERE hotel_id IN (?a)',
            ['hotel_id', 'hotel_name'],
            $hotelIds,
        ));
        $this->hotelNameCache = [];
        foreach ($rows as $id => $name) {
            $this->hotelNameCache[(string) $id] = TypeCoerce::toString($name);
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function processItem($itemId): array
    {
        $hotelId = (string) $itemId;
        $cachedName = $this->hotelNameCache[$hotelId] ?? '';
        $hotelName = $cachedName !== '' ? $cachedName : '(unnamed)';
        $this->logger->output("[{$hotelId}] {$hotelName} ... ", false);

        try {
            $ok = fn_novoton_holidays_sync_hotel_facilities($hotelId);
        } catch (\Throwable $e) {
            $this->logger->output('ERROR: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }

        if (!$ok) {
            $this->logger->output('EMPTY/FAILED');
            return [
                'success' => false,
                'message' => 'api_returned_empty',
                'data' => null,
            ];
        }

        $count = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s',
            $hotelId,
        ));

        // Re-derive travel groups if the hotel has a linked CS-Cart product.
        $this->refreshTravelGroups($hotelId);

        $this->logger->output("OK ({$count} facilities)");

        return [
            'success' => true,
            'message' => '',
            'data' => ['facility_count' => $count],
        ];
    }

    /**
     * Re-derive travel groups for a hotel's linked product after a facility sync.
     *
     * Travel groups (adults_only, family_friendly, pets_friendly) are inferred
     * from facility canonical codes — when facilities change, groups must update.
     *
     * Logic preserved verbatim from the legacy BatchedHotelFacilitiesSync.
     */
    private function refreshTravelGroups(string $hotelId): void
    {
        $hotel = TypeCoerce::toStringMap(db_get_row(
            'SELECT product_id, is_adults_only FROM ?:novoton_hotels WHERE hotel_id = ?s',
            $hotelId,
        ));

        $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
        if ($productId <= 0) {
            return;
        }

        $facilityIds = TypeCoerce::toList(db_get_fields(
            'SELECT facility_id FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s',
            $hotelId,
        ));

        $normalizer = new NovotonNormalizer();
        $resolvedCodes = [];
        foreach ($facilityIds as $fid) {
            $code = $normalizer->normalizeFacilityCode($fid);
            if ($code !== null) {
                $mapping = CoreFeatureMapper::resolveFacility('novoton', $code);
                if ($mapping !== null && !empty($mapping['canonical_code'])) {
                    $resolvedCodes[] = TypeCoerce::toString($mapping['canonical_code']);
                }
            }
        }

        $groups = TravelGroupResolver::derive(
            $resolvedCodes,
            ($hotel['is_adults_only'] ?? 'N') === 'Y',
        );

        if (!empty($groups)) {
            $featureMapper = new NovotonFeatureMapper();
            $featureMapper->assignMultipleViaCore($productId, 'travel_group', $groups);
        }
    }
}
