<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Contracts\HotelSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Fetches hotels from the Sphinx API and syncs them into the local DB.
 *
 * Strategy: instead of fetching all 100k+ hotels at once, we fetch
 * per-destination — using the destination IDs from the selected countries
 * in the synced sphinx_destinations table. This keeps API calls manageable.
 *
 * Flow:
 *   1. Resolve selected country codes → destination IDs
 *   2. For each destination, paginate through getHotels()
 *   3. Normalize and filter each hotel
 *   4. Batch upsert into sphinx_hotels
 *   5. Mark stale hotels as inactive
 */
class HotelSyncService extends AbstractSyncService implements HotelSyncServiceInterface
{
    private const int UPSERT_BATCH_SIZE = 100;
    private const int DEST_CHUNK_SIZE = 200;
    private const int PER_PAGE = 1000;

    private readonly HotelRowMapper $rowMapper;
    private readonly HotelAvailabilityGate $availabilityGate;

    /** Whether the availability gate runs this sync (resolved in doSync). */
    private bool $availabilityGateEnabled = true;

    public function __construct(
        SphinxApi $api,
        private readonly HotelRepository $hotelRepo,
        private readonly DestinationRepository $destRepo,
        HotelSkipRepository $skipRepo,
        ?SphinxNormalizer $normalizer = null,
        ?HotelRowMapper $rowMapper = null,
        ?HotelAvailabilityGate $availabilityGate = null,
    ) {
        parent::__construct($api);
        // The mapper shares this service's DestinationRepository so the parent
        // lookup preloaded in doSync() is visible to enrichFromHierarchy().
        $this->rowMapper = $rowMapper ?? new HotelRowMapper($normalizer ?? Container::getNormalizer(), $destRepo);
        $this->availabilityGate = $availabilityGate ?? new HotelAvailabilityGate($api, $skipRepo);
    }

    #[\Override]
    protected function getSyncType(): string
    {
        return 'hotels';
    }

    /**
     * Run hotel sync filtered by country codes and/or specific destination IDs.
     *
     * @param string[] $countryCodes Country codes to sync (e.g. ['GR', 'BG'])
     * @param int[] $extraDestinationIds Specific destination IDs to include (region/city level targeting)
     * @param bool $fullSync Force full re-fetch (ignores updated_since)
     * @param bool|null $availabilityGateOverride Force the availability gate on/off for this run (null = use setting)
     * @return array{success: bool, total: int, synced: int, skipped: int, failed: int, duration_ms: int, error: string, sync_mode: string}
     */
    #[\Override]
    public function sync(
        array $countryCodes = [],
        array $extraDestinationIds = [],
        bool $fullSync = false,
        ?bool $availabilityGateOverride = null,
    ): array {
        return $this->runSync($fullSync, [
            'country_codes' => $countryCodes,
            'destination_ids' => $extraDestinationIds,
            'availability_gate_override' => $availabilityGateOverride,
        ]);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[\Override]
    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        $countryCodes = TypeCoerce::toStringList($context['country_codes'] ?? null);
        $extraDestinationIds = TypeCoerce::toIntList($context['destination_ids'] ?? null);

        // Availability gate: per-run override (cron &availability_gate=0|1) wins,
        // otherwise fall back to the addon setting (default Y).
        $gateOverride = $context['availability_gate_override'] ?? null;
        $this->availabilityGateEnabled = is_bool($gateOverride)
            ? $gateOverride
            : ConfigProvider::shouldRequireImmediateAvailability();

        if (empty($countryCodes) && empty($extraDestinationIds)) {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
            $extraDestinationIds = ConfigProvider::getAllowedDestinationIds();
        }

        if (empty($countryCodes) && empty($extraDestinationIds)) {
            $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $labels = [];
        if (!empty($countryCodes)) {
            $labels[] = 'countries: ' . implode(', ', $countryCodes);
        }
        if (!empty($extraDestinationIds)) {
            $labels[] = 'destination IDs: ' . implode(', ', $extraDestinationIds);
        }
        $this->output('Hotel sync starting for ' . implode('; ', $labels));

        // Get destination IDs for selected countries (all types under those countries)
        $destinationIds = $this->resolveDestinationIds($countryCodes, $extraDestinationIds);

        if (empty($destinationIds)) {
            $stats['error'] = 'No destinations found for selected countries. Sync destinations first.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $this->output('Found ' . count($destinationIds) . ' destination(s) to sync hotels for');

        // Preload destination hierarchy for country/region/city resolution during sync
        if (!$this->destRepo->loadParentLookup()) {
            $this->output('WARNING: sphinx_destinations is empty — country/region enrichment will use sync context only. Run destination sync first for best results.');
        }

        // Fetch and sync hotels per country (with per-country incremental timestamps)
        foreach ($destinationIds as $countryCodeStr => $destIdsForCountry) {
            $updatedSince = null;
            if (!$fullSync) {
                $lastSynced = $this->hotelRepo->getLastSyncedAt($countryCodeStr);
                if ($lastSynced !== null) {
                    $updatedSince = $lastSynced;
                }
            }
            $modeLabel = $updatedSince !== null ? "incremental since {$updatedSince}" : 'full';
            $this->output("  {$countryCodeStr}: sync mode: {$modeLabel}");

            $countryStats = $this->syncCountry($countryCodeStr, $destIdsForCountry, $updatedSince);
            $stats['total'] = ValidationHelpers::toInt($stats['total'] ?? 0) + ValidationHelpers::toInt($countryStats['total'] ?? 0);
            $stats['synced'] = ValidationHelpers::toInt($stats['synced'] ?? 0) + ValidationHelpers::toInt($countryStats['synced'] ?? 0);
            $stats['skipped'] = ValidationHelpers::toInt($stats['skipped'] ?? 0) + ValidationHelpers::toInt($countryStats['skipped'] ?? 0);
            $stats['failed'] = ValidationHelpers::toInt($stats['failed'] ?? 0) + ValidationHelpers::toInt($countryStats['failed'] ?? 0);

            foreach ([
                'availability_probed', 'availability_waiting', 'availability_products_hidden',
                'availability_products_restored', 'availability_products_released',
                'availability_cleared', 'availability_errors',
            ] as $k) {
                $stats[$k] = ValidationHelpers::toInt($stats[$k] ?? 0) + ValidationHelpers::toInt($countryStats[$k] ?? 0);
            }

            $cError = ValidationHelpers::toString($countryStats['error'] ?? '');
            if (!empty($cError)) {
                $currentError = ValidationHelpers::toString($stats['error'] ?? '');
                $stats['error'] = $currentError . ($currentError !== '' ? '; ' : '') . $cError;
            }
        }

        // Set overall sync_mode label based on what actually happened
        $stats['sync_mode'] = $fullSync ? 'full' : 'per-country incremental';
        $sSynced = ValidationHelpers::toInt($stats['synced']);
        $sTotal = ValidationHelpers::toInt($stats['total']);
        $sSkipped = ValidationHelpers::toInt($stats['skipped']);
        $stats['success'] = ($sSynced > 0 || $sTotal === 0);
        $this->output("Sync complete: {$sSynced}/{$sTotal} hotels synced, {$sSkipped} skipped.");

        if (!$this->availabilityGateEnabled) {
            $this->output('Availability gate: disabled');
        } else {
            $this->output(sprintf(
                'Availability gate: %d newly waiting (no product, no immediate offer), %d product(s) hidden, %d cleared, %d product(s) reactivated, %d product(s) changed by an admin and left alone (%d destination(s) probed, %d search error(s))',
                ValidationHelpers::toInt($stats['availability_waiting'] ?? 0),
                ValidationHelpers::toInt($stats['availability_products_hidden'] ?? 0),
                ValidationHelpers::toInt($stats['availability_cleared'] ?? 0),
                ValidationHelpers::toInt($stats['availability_products_restored'] ?? 0),
                ValidationHelpers::toInt($stats['availability_products_released'] ?? 0),
                ValidationHelpers::toInt($stats['availability_probed'] ?? 0),
                ValidationHelpers::toInt($stats['availability_errors'] ?? 0),
            ));
        }

        return $stats;
    }

    /**
     * Sync hotels for a single country.
     *
     * Uses server-side destination_ids filtering to reduce API payload.
     * When updatedSince is set (incremental sync), stale detection is skipped.
     *
     * @param int[] $destinationIds Destination IDs belonging to this country
     * @param string|null $updatedSince Only fetch hotels updated since this datetime
     * @return array<string, mixed>
     */
    private function syncCountry(string $countryCode, array $destinationIds, ?string $updatedSince = null): array
    {
        $stats = ['total' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'error' => ''];

        if (empty($destinationIds)) {
            $this->output("  {$countryCode}: no destinations, skipping");
            return $stats;
        }

        $this->output("  {$countryCode}: syncing from " . count($destinationIds) . ' destination(s)...');

        // Record sync start time for stale detection (replaces $activeIds array)
        $syncStartedAt = date('Y-m-d H:i:s');

        // Chunk destination IDs to avoid URL length overflow.
        // Large ID lists create query strings that can exceed server limits.
        $destIdChunks = array_chunk($destinationIds, self::DEST_CHUNK_SIZE);

        foreach ($destIdChunks as $chunkIdx => $destIdChunk) {
            // Wait for circuit breaker cooldown before attempting next chunk
            $httpClient = $this->api->getHttpClient();
            if ($chunkIdx > 0 && $httpClient->isCircuitOpen()) {
                $waitSecs = $httpClient->getCircuitBreakerTimeout() + 5;
                $this->output("    Circuit breaker open. Waiting {$waitSecs}s before retry...");
                sleep($waitSecs);
                /** @var bool $stillOpen */
                $stillOpen = $httpClient->isCircuitOpen();
                if ($stillOpen) {
                    $this->output('    Circuit breaker still open after wait. Aborting remaining chunks.');
                    $stats['error'] = 'Circuit breaker open — API unavailable';
                    break;
                }
            }

            if (count($destIdChunks) > 1) {
                $this->output('    Destination chunk ' . ($chunkIdx + 1) . '/' . count($destIdChunks) . ' (' . count($destIdChunk) . ' IDs)');
            }

            $page = 1;

            // Stream-and-upsert: fetch each page and upsert immediately
            while (true) {
                $response = $this->api->getHotels($page, self::PER_PAGE, $updatedSince, $destIdChunk);

                if ($response === null) {
                    $stats['error'] = "API request failed on page {$page}: " . $this->api->getHttpClient()->getLastError();
                    $this->output('    ERROR: ' . $stats['error']);
                    break;
                }

                $items = $this->extractItems($response);

                if (empty($items)) {
                    break;
                }

                $pageBatch = [];
                foreach ($items as $raw) {
                    if (!is_array($raw)) {
                        continue;
                    }
                    $normalized = $this->rowMapper->normalize(TypeCoerce::toStringMap($raw));
                    if ($normalized === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Safety filter: verify country_code matches
                    $hotelCountry = strtoupper(ValidationHelpers::toString($normalized['country_code'] ?? ''));
                    if ($hotelCountry !== '' && $hotelCountry !== $countryCode) {
                        $stats['skipped']++;
                        continue;
                    }

                    $pageBatch[] = $normalized;
                    $stats['total']++;
                }

                // Batch-resolve country/region/city from destination hierarchy.
                // Stored geocoded values ride along as the ladder's last rung —
                // without them a re-sync would blank a geocoded city.
                $batchIds = [];
                foreach ($pageBatch as $row) {
                    $bid = TypeCoerce::toString($row['hotel_id'] ?? '');
                    if ($bid !== '') {
                        $batchIds[] = $bid;
                    }
                }
                $pageBatch = $this->rowMapper->enrichFromHierarchy(
                    $pageBatch,
                    $countryCode,
                    $this->hotelRepo->findGeoLocations($batchIds),
                );

                // Upsert in sub-batches
                foreach (array_chunk($pageBatch, self::UPSERT_BATCH_SIZE) as $upsertChunk) {
                    $stats['synced'] += $this->hotelRepo->upsertBatch($upsertChunk);
                }

                $totalPlusSkipped = $stats['total'] + $stats['skipped'];
                if (!$this->hasMorePages($response, $page, self::PER_PAGE, $totalPlusSkipped)) {
                    break;
                }

                $page++;
            }
        }

        if ($stats['total'] === 0) {
            if ($updatedSince !== null) {
                $this->output("    {$countryCode}: no hotels updated since {$updatedSince}");
            } else {
                $this->output("    {$countryCode}: 0 hotels matched");
            }
            // Still run the availability gate: it clears no_availability from
            // hotels that became bookable even when no static data changed.
            return $this->applyAvailabilityGate($countryCode, $destinationIds, $stats);
        }

        // Only mark stale hotels on full sync — incremental returns only changed items
        // Uses timestamp comparison instead of NOT IN (id_list) to avoid memory/SQL limits at scale
        if ($updatedSince === null) {
            $inactive = $this->hotelRepo->markInactiveBefore($syncStartedAt, $countryCode);
            if ($inactive > 0) {
                $this->output("    Marked {$inactive} stale hotel(s) as inactive");
            }
        }

        $this->output("    {$countryCode}: {$stats['synced']}/{$stats['total']} hotels synced");

        return $this->applyAvailabilityGate($countryCode, $destinationIds, $stats);
    }

    /**
     * Run the availability gate for this country's destinations, unless the
     * per-run setting disabled it. Delegates to HotelAvailabilityGate.
     *
     * @param int[] $destinationIds
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function applyAvailabilityGate(string $countryCode, array $destinationIds, array $stats): array
    {
        if (!$this->availabilityGateEnabled) {
            return $stats;
        }

        return $this->availabilityGate->apply($countryCode, $destinationIds, $stats, $this->output(...));
    }

    /**
     * Resolve destination IDs grouped by country code.
     *
     * @param string[] $countryCodes
     * @param int[] $allowedDestIds Destination IDs already resolved from the whitelist
     * @return array<string, int[]> Country code => [destination_id, ...]
     */
    private function resolveDestinationIds(array $countryCodes, array $allowedDestIds = []): array
    {
        $result = [];

        if (!empty($allowedDestIds)) {
            // Group the already-resolved whitelist destination IDs by country code.
            // These IDs come from ConfigProvider::getAllowedDestinationIds() which
            // correctly respects selection_type ('all' vs 'specific').
            $rows = TypeCoerce::toRowList(db_get_array(
                'SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id IN (?n)',
                $allowedDestIds,
            ));

            foreach ($rows as $row) {
                $cc = TypeCoerce::toString($row['country_code'] ?? '') ?: 'CUSTOM';
                $result[$cc][] = TypeCoerce::toInt($row['destination_id'] ?? 0);
            }

            foreach ($result as $cc => $ids) {
                $result[$cc] = array_values(array_unique($ids));
            }
        } elseif (!empty($countryCodes)) {
            // Fallback: when called with explicit country codes (no whitelist IDs),
            // include all destinations for those countries.
            foreach ($countryCodes as $code) {
                $ids = TypeCoerce::toIntList(db_get_fields(
                    'SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s',
                    $code,
                ));
                if ($ids !== []) {
                    $result[$code] = $ids;
                }
            }
        }

        return $result;
    }

    /**
     * Re-link existing CS-Cart products (with the configured prefix) back to sphinx_hotels.
     *
     * After a fresh addon reinstall the sphinx_hotels table is empty but CS-Cart
     * still has products with SPX* codes. This method fetches only those hotels
     * from the Sphinx API one-by-one and re-inserts them with the product link.
     *
     * @param callable|null $progressCallback fn(int $current, int $total, string $hotelId)
     * @return array{total: int, linked: int, skipped: int, not_found: int, errors: int}
     */
    public function relinkExistingProducts(?callable $progressCallback = null): array
    {
        $prefix = ConfigProvider::getProductCodePrefix();
        $prefixLen = strlen($prefix);

        // SphinxProductFactory writes <ISO-2 country><hotel_id> codes
        // (TR3612, HR59843) — the configured prefix ('SPX') is a legacy
        // shape kept for products minted before the country prefixes.
        // Scanning only the legacy prefix silently relinked NOTHING, which
        // left every unlinked hotel product without a booking form.
        // The 2-letter regexp cannot swallow novoton's NVT+digits codes.
        $spxProducts = TypeCoerce::toRowList(db_get_array(
            'SELECT product_id, product_code FROM ?:products'
            . ' WHERE product_code LIKE ?l OR product_code REGEXP ?s',
            $prefix . '%',
            '^[A-Z]{2}[0-9]+$',
        ));

        $stats = ['total' => count($spxProducts), 'linked' => 0, 'skipped' => 0, 'not_found' => 0, 'errors' => 0];

        if ($spxProducts === []) {
            return $stats;
        }

        foreach ($spxProducts as $i => $product) {
            $code = TypeCoerce::toString($product['product_code'] ?? '');
            $hotelId = str_starts_with($code, $prefix)
                ? substr($code, $prefixLen)
                : (string) preg_replace('/^[A-Z]{2}/', '', $code);
            if ($progressCallback !== null) {
                $progressCallback($i + 1, $stats['total'], $hotelId);
            }

            // Skip if already linked in sphinx_hotels
            $existing = $this->hotelRepo->findById($hotelId);
            if ($existing !== null && !empty($existing['product_id'])) {
                $stats['skipped']++;
                continue;
            }

            // Fetch single hotel from API
            try {
                $raw = $this->api->getHotel($hotelId);
            } catch (\Throwable) {
                $stats['errors']++;
                continue;
            }

            if (empty($raw)) {
                $stats['not_found']++;
                continue;
            }

            // Envelope already unwrapped by SphinxApi (ResponseDecoder::single).
            $normalized = $this->rowMapper->normalize(TypeCoerce::toStringMap($raw));
            if ($normalized === null) {
                $stats['errors']++;
                continue;
            }

            $this->hotelRepo->upsertBatch([$normalized]);
            $this->hotelRepo->linkToProduct($hotelId, TypeCoerce::toInt($product['product_id'] ?? 0));
            $stats['linked']++;
        }

        return $stats;
    }
}
