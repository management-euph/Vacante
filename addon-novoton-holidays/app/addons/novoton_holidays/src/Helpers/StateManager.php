<?php

declare(strict_types=1);

/**
 * Novoton Holidays - State Manager
 *
 * Unified state file management for batched sync operations.
 * Provides:
 * - Atomic file operations with locking
 * - Consistent state structure
 * - Progress tracking
 * - Resume capability
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\DirectoryManager;
use Tygh\Addons\NovotonHolidays\Services\PathResolver;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class StateManager implements StateManagerInterface
{
    /**
     * State file path
     */
    private string $stateFile;

    /**
     * Lock file handle
     * @var resource|null
     */
    private $lockHandle = null;

    /**
     * Default state structure
     */
    public const array DEFAULT_STATE = [
        'status' => 'idle',
        'sync_type' => null,
        'started_at' => null,
        'last_run_at' => null,
        'total' => 0,
        'processed' => 0,
        'synced' => 0,
        'errors' => 0,
        'error_ids' => [],
        'item_ids' => [],
        'metadata' => [],
    ];

    /**
     * Constructor
     *
     * @param string $stateName Unique name for this state file (e.g., 'hotelinfo', 'priceinfo')
     */
    public function __construct(string $stateName)
    {
        // Ensure cache directory exists
        DirectoryManager::ensureCacheDir();

        $cacheDir = PathResolver::getPath('cache');
        $this->stateFile = $cacheDir . "batch_{$stateName}_state.json";
    }

    /**
     * Load state from file
     *
     * @return array<string, mixed> State array
     */
    public function load(): array
    {
        if (!file_exists($this->stateFile)) {
            return self::DEFAULT_STATE;
        }

        $this->acquireLock();
        try {
            $content = file_get_contents($this->stateFile);
            if ($content === false) {
                return self::DEFAULT_STATE;
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                return $this->restoreFromBackup();
            }

            // Handle checksummed format
            if (isset($decoded['_checksum']) && isset($decoded['_data'])) {
                $expectedChecksum = $decoded['_checksum'];
                $actualChecksum = md5((string) json_encode($decoded['_data']));
                if ($expectedChecksum !== $actualChecksum) {
                    return $this->restoreFromBackup();
                }
                $state = $decoded['_data'];
            } else {
                // Legacy format (no checksum wrapper)
                $state = $decoded;
            }

            if (!is_array($state)) {
                return $this->restoreFromBackup();
            }

            return array_merge(self::DEFAULT_STATE, TypeCoerce::toStringMap($state));
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Save state to file with locking
     *
     * @param array<string, mixed> $state State array
     * @return bool Success
     */
    public function save(array $state): bool
    {
        $state['last_run_at'] = date('Y-m-d H:i:s');

        // Use compact JSON (no pretty print) to reduce I/O for large state files
        $content = json_encode($state);
        if ($content === false) {
            return false;
        }

        // Add checksum for corruption detection
        $checksum = md5($content);
        $wrappedContent = json_encode(['_checksum' => $checksum, '_data' => $state]);
        if ($wrappedContent === false) {
            $wrappedContent = $content; // Fallback to unwrapped
        }

        $this->acquireLock();
        try {
            // Backup current state before overwriting
            if (file_exists($this->stateFile) && is_readable($this->stateFile)) {
                copy($this->stateFile, $this->stateFile . '.bak');
            }

            // Write to temp file first for atomic operation
            $tempFile = $this->stateFile . '.tmp';

            if (file_put_contents($tempFile, $wrappedContent, LOCK_EX) === false) {
                return false;
            }

            // Atomically replace the state file
            if (!rename($tempFile, $this->stateFile)) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                return false;
            }

            return true;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Clear state file and data-related auxiliary files (.bak, .tmp).
     *
     * The .lock file is intentionally NOT deleted — it is a coordination
     * mechanism that other processes may hold an flock() on. Deleting it
     * while held would cause the next acquireLock() to create a new inode,
     * allowing two processes to hold "exclusive" locks simultaneously.
     *
     * @return bool Success
     */
    public function clear(): bool
    {
        $ok = true;

        foreach (['', '.bak', '.tmp'] as $suffix) {
            $file = $this->stateFile . $suffix;
            if (file_exists($file)) {
                if (!unlink($file)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /**
     * Attempt to restore state from backup file
     *
     * @return array<string, mixed> Restored state or DEFAULT_STATE
     */
    private function restoreFromBackup(): array
    {
        $backupFile = $this->stateFile . '.bak';
        if (file_exists($backupFile)) {
            $backupContent = (string) file_get_contents($backupFile);
            $decoded = json_decode($backupContent, true);
            if (is_array($decoded)) {
                // Handle checksummed backup
                $state = (isset($decoded['_checksum']) && isset($decoded['_data']))
                    ? $decoded['_data']
                    : $decoded;
                if (is_array($state)) {
                    file_put_contents($this->stateFile, $backupContent, LOCK_EX);
                    return array_merge(self::DEFAULT_STATE, TypeCoerce::toStringMap($state));
                }
            }
        }
        return self::DEFAULT_STATE;
    }

    /**
     * Check if a sync is in progress
     */
    public function isInProgress(): bool
    {
        $state = $this->load();
        return $state['status'] === 'in_progress';
    }

    /**
     * Start a new sync
     *
     * @param string $syncType Type of sync (e.g., 'full', 'incremental')
     * @param array<int|string, mixed> $itemIds Array of item IDs to process (a flat list of ids)
     * @param array<string, mixed> $metadata Additional metadata
     * @return array<string, mixed> New state
     */
    public function start(string $syncType, array $itemIds, array $metadata = []): array
    {
        $state = [
            'status' => 'in_progress',
            'sync_type' => $syncType,
            'started_at' => date('Y-m-d H:i:s'),
            'last_run_at' => date('Y-m-d H:i:s'),
            'total' => count($itemIds),
            'processed' => 0,
            'synced' => 0,
            'errors' => 0,
            'error_ids' => [],
            'item_ids' => $itemIds,
            'metadata' => $metadata,
        ];

        $this->save($state);
        return $state;
    }

    /**
     * Update progress
     *
     * @param int $processed Number of items processed
     * @param int $synced Number of items successfully synced
     * @param int $errors Number of errors
     * @param list<string> $errorIds IDs of items with errors
     * @return array<string, mixed> Updated state
     */
    public function updateProgress(int $processed, int $synced, int $errors, array $errorIds = []): array
    {
        $state = $this->load();

        $state['processed'] = $processed;
        $state['synced'] = $synced;
        $state['errors'] = $errors;

        if (!empty($errorIds)) {
            $state['error_ids'] = array_unique(array_merge(TypeCoerce::toStringList($state['error_ids']), $errorIds));
        }

        $this->save($state);
        return $state;
    }

    /**
     * Increment counters
     *
     * @param int $processed Increment processed by
     * @param int $synced Increment synced by
     * @param int $errors Increment errors by
     * @param string|null $errorId Optional error ID to add
     * @return array<string, mixed> Updated state
     */
    public function increment(int $processed = 0, int $synced = 0, int $errors = 0, ?string $errorId = null): array
    {
        $state = $this->load();

        $state['processed'] = TypeCoerce::toInt($state['processed'] ?? 0) + $processed;
        $state['synced'] = TypeCoerce::toInt($state['synced'] ?? 0) + $synced;
        $state['errors'] = TypeCoerce::toInt($state['errors'] ?? 0) + $errors;

        if ($errorId !== null) {
            $errorIds = TypeCoerce::toStringList($state['error_ids'] ?? []);
            $errorIds[] = $errorId;
            $state['error_ids'] = $errorIds;
        }

        $this->save($state);
        return $state;
    }

    /**
     * Mark sync as completed
     *
     * @return array<string, mixed> Final state
     */
    public function complete(): array
    {
        $state = $this->load();
        $state['status'] = 'completed';
        $state['completed_at'] = date('Y-m-d H:i:s');

        // Calculate duration
        if (!empty($state['started_at'])) {
            $state['duration_seconds'] = time() - strtotime(TypeCoerce::toString($state['started_at']));
        }

        // Clear item_ids to save space (keep other data for reference)
        $state['item_ids'] = [];
        $this->save($state);

        return $state;
    }

    /**
     * Get current status with calculated fields
     *
     * @return array<string, mixed> Status with percent, eta, etc.
     */
    public function getStatus(): array
    {
        $state = $this->load();

        $status = [
            'status' => $state['status'],
            'sync_type' => $state['sync_type'],
            'total' => $state['total'],
            'processed' => $state['processed'],
            'synced' => $state['synced'],
            'errors' => $state['errors'],
        ];

        if ($state['status'] === 'in_progress') {
            $total = TypeCoerce::toInt($state['total'] ?? 0);
            $processed = TypeCoerce::toInt($state['processed'] ?? 0);

            $status['started_at'] = $state['started_at'];
            $status['last_run_at'] = $state['last_run_at'];

            // Calculate percent
            $status['percent'] = $total > 0
                ? round($processed / $total * 100, 1)
                : 0;

            // Calculate remaining
            $remaining = $total - $processed;
            $status['remaining'] = $remaining;

            // Calculate elapsed time
            $elapsed = time() - strtotime(TypeCoerce::toString($state['started_at']));
            $status['elapsed'] = $this->formatDuration($elapsed);

            // Calculate ETA
            if ($processed > 0) {
                $rate = $processed / max(1, $elapsed);
                $remaining_seconds = $remaining / max(0.001, $rate);
                $status['eta'] = $this->formatDuration((int)$remaining_seconds);
            } else {
                $status['eta'] = 'Calculating...';
            }
        } elseif ($state['status'] === 'completed') {
            $status['completed_at'] = $state['completed_at'] ?? null;
            $status['duration'] = isset($state['duration_seconds'])
                ? $this->formatDuration(TypeCoerce::toInt($state['duration_seconds']))
                : null;
        }

        // Add metadata
        if (!empty($state['metadata'])) {
            $status['metadata'] = $state['metadata'];
        }

        return $status;
    }

    /**
     * Get the next batch of item IDs to process.
     * If item_ids are stored in state (legacy), uses array_slice.
     * Otherwise returns empty — callers should use DB-based pagination.
     *
     * @param int $batchSize Number of items to get
     * @return list<mixed> Array of item IDs
     */
    public function getNextBatch(int $batchSize): array
    {
        $state = $this->load();

        if ($state['status'] !== 'in_progress') {
            return [];
        }

        // Legacy mode: item_ids stored in state
        if (!empty($state['item_ids'])) {
            return array_slice(TypeCoerce::toList($state['item_ids']), TypeCoerce::toInt($state['processed'] ?? 0), $batchSize);
        }

        // New mode: callers use DB pagination directly
        return [];
    }

    /**
     * Check if the current in-progress state is stale (abandoned).
     *
     * A state is stale when last_run_at is older than the given threshold,
     * meaning the process that was running the sync has likely died.
     *
     * @param int $maxAgeHours Maximum age in hours before state is considered stale
     * @return bool True if state exists, is in_progress, and is stale
     */
    public function isStale(int $maxAgeHours = 6): bool
    {
        $state = $this->load();

        if ($state['status'] !== 'in_progress') {
            return false;
        }

        $lastRun = $state['last_run_at'] ?? $state['started_at'] ?? null;
        if ($lastRun === null) {
            return true; // No timestamp at all — definitely stale
        }

        $ageHours = (time() - strtotime(TypeCoerce::toString($lastRun))) / 3600;
        return $ageHours > $maxAgeHours;
    }

    /**
     * Check if sync should be resumed.
     * Returns false for stale states (older than 6 hours with no activity).
     */
    public function shouldResume(): bool
    {
        if ($this->isStale()) {
            return false;
        }

        $state = $this->load();

        if ($state['status'] !== 'in_progress') {
            return false;
        }

        return $state['processed'] < $state['total'];
    }

    /**
     * Acquire an exclusive lock
     *
     * @param int $timeout Seconds to wait for lock
     * @return bool Success
     */
    public function acquireLock(int $timeout = 5): bool
    {
        // Every write goes through here (save, and load's backup restore), so
        // this is where the folder must exist. The constructor creating it is
        // not enough: a CS-Cart cache clear during the run — Travel Core
        // clears it after seeding new labels, and an admin can press "Clear
        // cache" any time — deletes var/cache/misc/ and this folder with it.
        // The lock and state writes then warned "No such file or directory"
        // and the batch's progress was lost, so the next run started over.
        $this->ensureDirectory();

        $lockFile = $this->stateFile . '.lock';
        $this->lockHandle = fopen($lockFile, 'c') ?: null;

        if ($this->lockHandle === null) {
            return false;
        }

        $startTime = time();
        while (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            if (time() - $startTime >= $timeout) {
                fclose($this->lockHandle);
                $this->lockHandle = null;
                return false;
            }
            usleep(100000); // 100ms
        }

        return true;
    }

    /** Create the state folder if it is missing (idempotent; tolerates a parallel run creating it). */
    private function ensureDirectory(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            error_log('novoton_holidays: cannot create the batch state folder ' . $dir);
        }
    }

    /**
     * Release the lock
     */
    public function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Format duration in human readable format
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $m = floor($seconds / 60);
            $s = $seconds % 60;
            return "{$m}m {$s}s";
        }

        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return "{$h}h {$m}m";
    }

    /**
     * Get state file path
     */
    public function getStateFilePath(): string
    {
        return $this->stateFile;
    }

    /**
     * Destructor - ensure lock is released
     */
    public function __destruct()
    {
        $this->releaseLock();
    }
}
