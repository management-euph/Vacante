<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Abstract Batched Sync
 *
 * Base class for all batched sync operations. Provides:
 * - State management with resume capability
 * - Unified logging
 * - Progress tracking
 * - Time limit handling
 * - Memory limit monitoring
 * - Per-item timeout tracking
 *
 * Subclasses must implement:
 * - getSyncName(): Unique name for this sync type
 * - determineSyncType(): Decide if full/incremental sync needed
 * - getItemsToSync(): Get item IDs to process
 * - processItem(): Process a single item
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PathResolver;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

abstract class AbstractBatchedSync implements SyncInterface
{
    /**
     * State manager (interface-typed for testability).
     */
    protected StateManagerInterface $state;

    /**
     * Logger (interface-typed for testability).
     */
    protected SyncLoggerInterface $logger;

    /**
     * API instance
     */
    protected ?NovotonApi $api = null;

    /**
     * Batch size
     */
    protected int $batchSize;

    /**
     * Maximum execution time per run (seconds)
     */
    protected int $maxExecutionTime;

    /**
     * Unlimited mode (no time limit)
     */
    protected bool $unlimited = false;

    /**
     * Start time of current run
     */
    protected int $startTime;

    /**
     * Memory usage threshold as fraction of PHP memory_limit (0.0 to 1.0)
     */
    protected float $memoryThreshold = 0.85;

    /**
     * Per-item warning threshold in seconds
     */
    protected int $itemTimeoutWarning = 30;

    /**
     * Constructor with optional DI.
     *
     * Collaborators are interface-typed and default to the concrete
     * StateManager / SyncLogger so existing subclasses that call
     * `parent::__construct()` with no arguments keep working unchanged.
     * Unit tests inject in-memory fakes or mocks.
     */
    public function __construct(
        ?StateManagerInterface $state = null,
        ?SyncLoggerInterface $logger = null,
    ) {
        $this->batchSize = ConfigProvider::getCronBatchSize();
        $this->maxExecutionTime = ConfigProvider::getCronMaxExecutionTime();

        $this->state = $state ?? new StateManager($this->getSyncName());
        $this->logger = $logger ?? new SyncLogger($this->getSyncName());

        // CLI runs have no web-request timeout; matching the legacy
        // Batched*Sync helpers, default to unlimited mode under CLI so
        // artificial batching doesn't slow down ad-hoc operator runs.
        if ($this->isCliEnvironment()) {
            $this->unlimited = true;
        }
    }

    /**
     * Get unique name for this sync type
     */
    abstract protected function getSyncName(): string;

    /**
     * Determine what type of sync is needed
     *
     * @param array<string, mixed> $options
     * @return string 'full', 'incremental', or 'none'
     */
    abstract protected function determineSyncType(array $options): string;

    /**
     * Get item IDs to sync based on sync type.
     *
     * Implementations return a flat list of item identifiers (hotel ids, or
     * composite "hotelId/packageId" strings); the key type is therefore left
     * open so subclasses can return a list.
     *
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    abstract protected function getItemsToSync(string $syncType, array $options): array;

    /**
     * Process a single item
     *
     * @param string|int $itemId
     * @return array<string, mixed> ['success' => bool, 'message' => string, 'data' => mixed]
     */
    abstract protected function processItem($itemId): array;

    /**
     * Set batch size
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(ConfigProvider::MIN_BATCH_SIZE, min(ConfigProvider::MAX_BATCH_SIZE, $size));
    }

    /**
     * Set maximum execution time
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->maxExecutionTime = max(ConfigProvider::MIN_EXECUTION_TIME, min(ConfigProvider::MAX_EXECUTION_TIME, $seconds));
    }

    /**
     * Set unlimited mode
     */
    public function setUnlimited(bool $unlimited): void
    {
        $this->unlimited = $unlimited;
    }

    /**
     * Set output callback for logger
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->logger->setOutputCallback($callback);
    }

    /**
     * Get the API kit for this sync.
     *
     * Return type is deliberately narrowed to NovotonApiKitInterface —
     * the concrete NovotonApi facade carries 29 @deprecated flat
     * delegate methods, and subclasses must not reach for them.
     * Route every call through a sub-client accessor:
     *
     *     $this->getApi()->hotels()->getHotelInfoBatch(...)
     *     $this->getApi()->pricing()->getPriceInfo(...)
     *     $this->getApi()->destinations()->getOffersUpdate(...)
     *
     * NovotonApi implements NovotonApiKitInterface (added in PR #4),
     * so returning `$this->api` (a concrete NovotonApi instance) from
     * this narrowed method is valid by covariance.
     */
    protected function getApi(): NovotonApiKitInterface
    {
        if ($this->api === null) {
            $srcDir = PathResolver::getPath('src');
            if (file_exists($srcDir . 'NovotonApi.php')) {
                require_once($srcDir . 'NovotonApi.php');
            }
            $this->api = new NovotonApi();
        }
        return $this->api;
    }

    /**
     * Check if time limit has been reached
     */
    protected function isTimeLimitReached(): bool
    {
        if ($this->unlimited) {
            return false;
        }
        return (time() - $this->startTime) > $this->maxExecutionTime;
    }

    /**
     * Check if memory usage is approaching the PHP memory_limit.
     *
     * Returns true when current usage exceeds memoryThreshold fraction
     * of the configured limit, giving the process time to save state
     * before OOM.
     */
    protected function isMemoryLimitReached(): bool
    {
        $limit = self::parseMemoryLimit();
        if ($limit <= 0) {
            return false; // unlimited or unparseable
        }
        return memory_get_usage(true) > (int)($limit * $this->memoryThreshold);
    }

    /**
     * Check if any resource limit (time or memory) is reached.
     */
    protected function isLimitReached(): bool
    {
        return $this->isTimeLimitReached() || $this->isMemoryLimitReached();
    }

    /**
     * Sleep between processed items to avoid hammering the upstream API.
     *
     * Overridable in subclasses (and unit-test doubles) to change the delay
     * or skip it entirely. Default reads ConfigProvider::API_DELAY_MS.
     */
    protected function sleepBetweenItems(): void
    {
        usleep(ConfigProvider::API_DELAY_MS * 1000);
    }

    /**
     * Hook called once per batch, right after it is loaded and before the
     * per-item processing loop starts. Default is a no-op.
     *
     * Subclasses override this to pre-fetch auxiliary data for the whole
     * batch in a single query — for example, a hotel-name map so every
     * processItem() call doesn't re-query the database.
     *
     * @param array<int|string, mixed> $batch The item IDs in this batch
     */
    protected function preBatch(array $batch): void
    {
    }

    /**
     * Whether the base class should retry failed items once, after the
     * normal per-item processing loop completes.
     *
     * Subclasses opt in by returning true. Retry runs exactly once per
     * sync (guarded by the `retry_done` state flag), takes items from
     * `state->load()['error_ids']`, feeds each back through processItem(),
     * and sleeps sleepBetweenRetries() between attempts.
     */
    protected function shouldRetryFailedItems(): bool
    {
        return false;
    }

    /**
     * Sleep between retry attempts.
     *
     * Default 500 ms, matching Constants::API_DELAY_BACKOFF used by the
     * legacy Batched*Sync helpers this base class is slated to replace.
     * Tests override this to skip the sleep entirely.
     */
    protected function sleepBetweenRetries(): void
    {
        usleep(500_000);
    }

    /**
     * The time a run decides by (unix time): which pass is due, which items
     * to retry. Overridable so a test can pin it.
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * Whether the current process is running under the PHP CLI SAPI.
     *
     * Factored out as a protected method so unit tests can override it —
     * PHP_SAPI itself is not mockable at runtime.
     */
    protected function isCliEnvironment(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Parse PHP memory_limit to bytes.
     */
    private static function parseMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === '') {
            return 0; // unlimited
        }
        $limit = trim($limit);
        $value = (int)$limit;
        $unit = strtolower(substr($limit, -1));
        switch ($unit) {
            case 'g':
                $value *= 1024;
                // fall through
                // no break
            case 'm':
                $value *= 1024;
                // fall through
                // no break
            case 'k':
                $value *= 1024;
        }
        return $value;
    }

    /**
     * Run the sync operation
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $this->startTime = time();

        // Handle reset option
        if (!empty($options['reset'])) {
            $this->state->clear();
            $this->logger->output('State reset. Ready for new sync.');
            return ['status' => 'reset'];
        }

        // Load state once and decide: stale → clear, active → resume, else → fresh
        $currentState = $this->state->load();

        if ($currentState['status'] === 'in_progress') {
            if ($this->state->isStale()) {
                $lastRunAt = TypeCoerce::toString($currentState['last_run_at'] ?? '');
                $this->logger->output("Stale state detected (no activity since {$lastRunAt}). Clearing and starting fresh.");
                $this->state->clear();
            } elseif ($currentState['processed'] < $currentState['total']) {
                $status = $this->state->getStatus();
                $syncType = TypeCoerce::toString($status['sync_type'] ?? '');
                $processed = TypeCoerce::toString($status['processed'] ?? '');
                $total = TypeCoerce::toString($status['total'] ?? '');
                $percent = TypeCoerce::toString($status['percent'] ?? '');
                $this->logger->output("Resuming {$syncType} sync...");
                $this->logger->output("Progress: {$processed}/{$total} ({$percent}%)");
                return $this->resumeSync();
            }
        }

        // Determine sync type needed
        $syncType = $this->determineSyncType($options);

        if ($syncType === 'none') {
            $this->logger->output('No sync needed at this time.');
            return ['status' => 'skipped', 'reason' => 'No sync needed'];
        }

        $this->logger->output("Starting {$syncType} sync...");

        // Get items to sync
        $itemIds = $this->getItemsToSync($syncType, $options);

        if (empty($itemIds)) {
            $this->logger->output('No items to sync.');
            return ['status' => 'skipped', 'reason' => 'No items found'];
        }

        $this->logger->output('Found ' . count($itemIds) . ' items to sync.');

        // Create new state
        $metadata = $this->getMetadata($options);
        $this->state->start($syncType, $itemIds, $metadata);

        return $this->resumeSync();
    }

    /**
     * Get metadata to store with state
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function getMetadata(array $options): array
    {
        return [
            'countries' => $options['countries'] ?? ConfigProvider::getSelectedCountries(),
        ];
    }

    /**
     * Resume an in-progress sync
     *
     * @return array<string, mixed>
     */
    protected function resumeSync(): array
    {
        $processedThisRun = 0;
        $syncedThisRun = 0;
        $errorsThisRun = 0;

        $status = $this->state->getStatus();
        $offset = TypeCoerce::toInt($status['processed'] ?? 0);
        $total = TypeCoerce::toInt($status['total'] ?? 0);
        $statusSynced = TypeCoerce::toInt($status['synced'] ?? 0);
        $statusErrors = TypeCoerce::toInt($status['errors'] ?? 0);

        while ($offset < $total) {
            // Check time and memory limits before batch
            if ($this->isLimitReached()) {
                $elapsed = time() - $this->startTime;
                $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                $this->logger->output("\nLimit reached (time: {$elapsed}s, mem: {$mem}MB). Saving state for resume.");
                break;
            }

            // Get next batch
            $batch = $this->state->getNextBatch($this->batchSize);

            if (empty($batch)) {
                break;
            }

            // Hook: subclasses can pre-fetch auxiliary data (e.g. hotel names)
            // for the whole batch here to avoid N+1 queries inside processItem.
            $this->preBatch($batch);

            // Accumulate counters per batch to avoid per-item file I/O
            $batchProcessed = 0;
            $batchErrorIds = [];

            foreach ($batch as $itemId) {
                $item = TypeCoerce::toString($itemId);

                // Check limits within batch
                if ($this->isLimitReached()) {
                    // Save accumulated batch progress before breaking
                    if ($batchProcessed > 0) {
                        $this->state->updateProgress($offset, $statusSynced + $syncedThisRun, $statusErrors + $errorsThisRun, $batchErrorIds);
                    }
                    break 2;
                }

                $itemStart = hrtime(true);
                $result = $this->processItem($item);
                $itemDurationMs = (int)((hrtime(true) - $itemStart) / 1_000_000);

                // Warn about slow items
                if ($itemDurationMs > $this->itemTimeoutWarning * 1000) {
                    $secs = round($itemDurationMs / 1000, 1);
                    $this->logger->output("Warning: item {$item} took {$secs}s (threshold: {$this->itemTimeoutWarning}s)");
                }

                $offset++;
                $processedThisRun++;
                $batchProcessed++;

                if (TypeCoerce::toBool($result['success'] ?? false)) {
                    $syncedThisRun++;
                } else {
                    $errorsThisRun++;
                    $batchErrorIds[] = $item;
                }

                // Small delay to avoid API rate limits
                $this->sleepBetweenItems();
            }

            // Save state once per batch instead of per item
            $this->state->updateProgress($offset, $statusSynced + $syncedThisRun, $statusErrors + $errorsThisRun, $batchErrorIds);

            // Progress output
            $this->logger->outputProgress($offset, $total);
        }

        // Check if complete
        if ($offset >= $total) {
            if ($this->shouldRetryFailedItems()) {
                $this->retryFailedItems();
            }
            return $this->completeSync();
        }

        // Still in progress
        $remaining = $total - $offset;
        $runsRemaining = ceil($remaining / max(1, $processedThisRun));

        return [
            'status' => 'in_progress',
            'sync_type' => $status['sync_type'],
            'total' => $total,
            'processed' => $offset,
            'remaining' => $remaining,
            'synced_this_run' => $syncedThisRun,
            'errors_this_run' => $errorsThisRun,
            'estimated_runs_remaining' => $runsRemaining,
        ];
    }

    /**
     * Retry every item currently in `state['error_ids']` once.
     *
     * Called from resumeSync() when the normal processing loop has
     * reached offset >= total, only if the subclass returned true from
     * shouldRetryFailedItems(). Guarded by the `retry_done` state flag so
     * a resume after retry does not repeat the loop.
     *
     * Semantics match the legacy Batched*Sync helpers:
     *   - recovered IDs are removed from error_ids
     *   - synced counter increments by the number of recoveries
     *   - errors counter decrements by the number of recoveries
     *   - retry_done is set to true regardless of whether the retry
     *     loop completed or exited early due to a resource limit
     */
    private function retryFailedItems(): void
    {
        $state = $this->state->load();

        if (!empty($state['retry_done'])) {
            return;
        }

        $errorIds = array_values(array_unique(TypeCoerce::toStringList($state['error_ids'] ?? [])));

        if (empty($errorIds)) {
            $state['retry_done'] = true;
            $this->state->save($state);
            return;
        }

        $this->logger->output("\nRetrying " . count($errorIds) . ' failed items...');

        $recoveredIds = [];

        foreach ($errorIds as $retryId) {
            if ($this->isLimitReached()) {
                $this->logger->output('Retry stopped early: resource limit reached.');
                break;
            }

            $this->sleepBetweenRetries();

            $result = $this->processItem($retryId);

            if (!empty($result['success'])) {
                $recoveredIds[] = $retryId;
                $this->logger->output("  [{$retryId}] retry OK");
            } else {
                $msg = TypeCoerce::toString($result['message'] ?? '');
                $this->logger->output("  [{$retryId}] retry failed" . ($msg !== '' ? ": {$msg}" : ''));
            }
        }

        // Re-read state in case processItem() mutated it via side effects,
        // then patch in the retry outcome.
        $recoveredCount = count($recoveredIds);
        $freshState = $this->state->load();
        $freshState['synced'] = TypeCoerce::toInt($freshState['synced'] ?? 0) + $recoveredCount;
        $freshState['errors'] = max(0, TypeCoerce::toInt($freshState['errors'] ?? 0) - $recoveredCount);
        $freshState['error_ids'] = array_values(array_diff(
            TypeCoerce::toStringList($freshState['error_ids'] ?? []),
            $recoveredIds,
        ));
        $freshState['retry_done'] = true;
        $this->state->save($freshState);

        if ($recoveredCount > 0) {
            $this->logger->output("Recovered {$recoveredCount} items on retry.");
        }
    }

    /**
     * Complete the sync
     *
     * @return array<string, mixed>
     */
    protected function completeSync(): array
    {
        $state = $this->state->complete();

        // Log to database
        $this->logger->setStat('total', $state['total']);
        $this->logger->setStat('synced', $state['synced']);
        $this->logger->setStat('errors', $state['errors']);

        $extra = [
            'sync_type' => $state['sync_type'],
            'metadata' => $state['metadata'] ?? [],
        ];

        $this->logger->logToDatabase('completed', $extra);
        $this->logger->outputSummary();

        // Send email report
        $metadata = TypeCoerce::toStringMap($state['metadata'] ?? []);
        $countries = TypeCoerce::toStringList($metadata['countries'] ?? []);
        $this->logger->sendEmailReport([], implode(', ', $countries));

        // Clear state file
        $this->state->clear();

        return [
            'status' => 'completed',
            'sync_type' => $state['sync_type'],
            'total' => $state['total'],
            'synced' => $state['synced'],
            'errors' => $state['errors'],
            'duration' => $state['duration_seconds'] ?? 0,
        ];
    }

    /**
     * Get current status
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $status = $this->state->getStatus();

        if ($status['status'] === 'idle') {
            $syncLogRepo = Container::getInstance()->syncLogRepository();
            $lastSync = $syncLogRepo->getLastSync($this->getSyncName());

            if (is_array($lastSync) && $lastSync !== []) {
                $notes = TypeCoerce::toStringMap(json_decode(TypeCoerce::toString($lastSync['notes'] ?? '{}'), true));
                $status['last_sync'] = $lastSync['sync_date'];
                $status['last_sync_type'] = $notes['sync_type'] ?? 'unknown';
                $status['last_total'] = $lastSync['products_total'];
            }
        }

        return $status;
    }
}
