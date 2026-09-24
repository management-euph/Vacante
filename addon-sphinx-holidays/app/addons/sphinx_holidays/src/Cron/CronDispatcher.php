<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron;

use Tygh\Addons\SphinxHolidays\Cron\Commands\AbstractSyncCommand;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Helpers\CronRunLock;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron Command Dispatcher
 *
 * Auto-discovers command classes from the Cron/Commands/ directory — the
 * same OCP pattern as novoton's dispatcher: any class extending
 * AbstractSyncCommand registers itself via getModes(), so adding a command
 * requires only a new file (the hand-maintained 26-entry mode map is gone,
 * as is the class-per-line import list it dragged along).
 *
 * Run-level mutual exclusion uses the shared travel_core CronRunLock
 * (PID-stamped non-blocking flock with stale-takeover) — this dispatcher
 * carried the original inline copy that helper was extracted from.
 * Status/reset/debug requests are read-only and skip the lock.
 */
class CronDispatcher implements CronDispatcherInterface
{
    /** @var array<string, class-string<AbstractSyncCommand>> mode => command class */
    private static array $commandMap = [];
    private static bool $registered = false;

    /**
     * Auto-discover and register all command classes from the Commands/
     * directory (shared travel_core CommandDiscovery).
     */
    private static function registerCommands(): void
    {
        if (self::$registered) {
            return;
        }

        self::$commandMap = \Tygh\Addons\TravelCore\Cron\CommandDiscovery::map(
            __DIR__ . '/Commands/',
            'Tygh\\Addons\\SphinxHolidays\\Cron\\Commands\\',
            AbstractSyncCommand::class,
        );
        self::$registered = true;
    }

    /**
     * Get all available modes with descriptions.
     *
     * @return array<string, string>
     */
    #[\Override]
    public static function getAvailableModes(): array
    {
        self::registerCommands();

        $result = [];
        foreach (self::$commandMap as $mode => $class) {
            $result[$mode] = TypeCoerce::toString($class::getDescription());
        }
        ksort($result);

        return $result;
    }

    /**
     * Check if a mode exists.
     */
    #[\Override]
    public function hasMode(string $mode): bool
    {
        self::registerCommands();

        return isset(self::$commandMap[$mode]);
    }

    /**
     * Dispatch a cron job by mode.
     *
     * Uses file-based locking to prevent two instances of the same mode
     * from running concurrently. The 'full' mode acquires a single lock
     * for the composite run (individual modes dispatched by FullSyncCommand
     * will also acquire their own locks).
     *
     * @param string $mode The cron mode to execute
     * @param array<string, mixed> $params Additional parameters
     * @return array<string, mixed> Result from the command (shape varies by mode: success, plus mode-specific keys like stats/busy/synced/error)
     */
    #[\Override]
    public function dispatch(string $mode, array $params = []): array
    {
        if (!$this->hasMode($mode)) {
            return [
                'success' => false,
                'error' => "Unknown mode: {$mode}",
            ];
        }

        // Status/reset/debug are non-destructive read-only ops — skip the lock
        $isReadOnly = !empty($params['status']) || !empty($params['reset']) || !empty($params['debug']);

        $lock = null;
        if (!$isReadOnly) {
            $lock = new CronRunLock($this->getLockPath($mode));

            if (!$lock->acquire()) {
                return [
                    'success' => false,
                    'busy' => true,
                    'message' => "Mode '{$mode}' is already running. Try again later. Use &status=1 to check progress or &reset=1 to clear stale state.",
                ];
            }
        }

        try {
            // Remove PHP execution time limit for long-running sync jobs
            // (destination sync: 200k+ items, hotel sync: 100k+ items)
            set_time_limit(0);

            $class = self::$commandMap[$mode];
            $command = new $class();

            // Set output callback to echo progress and keep lock file fresh
            $command->setOutputCallback(function (string $message, bool $addNewline = true) use ($lock): void {
                echo $message . ($addNewline ? "\n" : '');
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                // Touch the lock so stale detection doesn't kill an active sync
                $lock?->touch();
            });

            // Recorded for Travel Core -> Tools (started, finished, ok, error).
            // Read-only status/reset/debug requests are not runs, so they are not.
            $result = $isReadOnly
                ? $command->execute($params)
                : CronRunLog::record('sphinx_holidays', $mode, static fn (): array => $command->execute($params));
        } finally {
            $lock?->release();
        }

        // Normalise the command result to the dispatcher contract: callers
        // (CronRunner / HTTP) rely on a boolean `success` flag and string
        // `error`/`message` fields. The diagnostic keys consumers read
        // (busy, stats) are carried over verbatim, and every optional key is
        // only included when the command actually set it, preserving the
        // `?? default` fallbacks on the reading side.
        $normalized = ['success' => TypeCoerce::toBool($result['success'] ?? false)];
        if (array_key_exists('error', $result)) {
            $normalized['error'] = TypeCoerce::toString($result['error']);
        }
        if (array_key_exists('message', $result)) {
            $normalized['message'] = TypeCoerce::toString($result['message']);
        }
        if (array_key_exists('busy', $result)) {
            $normalized['busy'] = $result['busy'];
        }
        if (array_key_exists('stats', $result)) {
            $normalized['stats'] = $result['stats'];
        }

        return $normalized;
    }

    /**
     * Get the lock file path for a given mode.
     */
    private function getLockPath(string $mode): string
    {
        $cacheDir = TypeCoerce::toString(defined('DIR_CACHE') ? DIR_CACHE : sys_get_temp_dir());
        return rtrim($cacheDir, '/') . "/sphinx_cron_{$mode}.lock";
    }

    /**
     * Reset for testing — forces re-discovery on next use.
     */
    public static function reset(): void
    {
        self::$commandMap = [];
        self::$registered = false;
    }
}
