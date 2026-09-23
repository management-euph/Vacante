<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron;

use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;

/**
 * Cron Command Dispatcher
 *
 * Auto-discovers command classes from the Cron/Commands/ directory.
 * Any class extending AbstractCronCommand is automatically registered
 * via its getModes() method. Adding a new command requires only creating
 * a new file in Commands/ — no modification to this class needed (OCP).
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */
class CronDispatcher implements CronDispatcherInterface
{
    /** @var array<string, class-string<AbstractCronCommand>> mode => command class */
    private static array $commandMap = [];
    private static bool $registered = false;
    private \Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface $api;
    private ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger;

    public function __construct(\Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface $api, ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
        self::registerCommands();
    }

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
            'Tygh\\Addons\\NovotonHolidays\\Cron\\Commands\\',
            AbstractCronCommand::class,
        );
        self::$registered = true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function dispatch(string $mode, array $params = []): array
    {
        if (!isset(self::$commandMap[$mode])) {
            return ['success' => false, 'error' => "Unknown mode: {$mode}"];
        }

        // Run-level mutual exclusion per mode (shared CronRunLock, ported
        // from sphinx's dispatcher): a scheduled cron overlapping a manual
        // admin trigger used to double-fetch/double-write — StateManager's
        // flock only guards individual state saves, not the run. Status/
        // reset/debug requests are read-only and skip the lock.
        $isReadOnly = !empty($params['status']) || !empty($params['reset']) || !empty($params['debug']);
        $lock = null;
        if (!$isReadOnly) {
            $lock = new \Tygh\Addons\TravelCore\Helpers\CronRunLock($this->getLockPath($mode));
            if (!$lock->acquire()) {
                return [
                    'success' => false,
                    'busy' => true,
                    'error' => "Mode '{$mode}' is already running. Try again later, or use &status=1 to check progress.",
                ];
            }
        }

        try {
            // Long-running sync batches must not die to max_execution_time.
            set_time_limit(0);

            $class = self::$commandMap[$mode];
            $command = new $class($this->api, $this->logger, array_merge($params, ['_mode' => $mode]));

            // Keep the run lock alive. Without this a mode that runs longer
            // than CronRunLock::STALE_THRESHOLD (30 min) is taken over as
            // stale by the next tick — and acquire() UNLINKS and reopens on
            // that path, so the original holder keeps flock on an orphaned
            // inode and BOTH run at once. `full` is documented at 5-30+
            // minutes, so this was reachable on the nightly schedule.
            //
            // Via setHeartbeat, not setOutputCallback: the latter replaces,
            // and this command's constructor may already have wired SyncLogger
            // there (HTTP path) or deliberately left it null (CLI path).
            $command->setHeartbeat(static function () use ($lock): void {
                $lock?->touch();
            });

            return $command->execute();
        } finally {
            $lock?->release();
        }
    }

    /**
     * Lock file path for a given mode.
     */
    private function getLockPath(string $mode): string
    {
        $cacheDir = defined('DIR_CACHE') && is_string(DIR_CACHE) ? DIR_CACHE : sys_get_temp_dir();
        return rtrim($cacheDir, '/') . "/novoton_cron_{$mode}.lock";
    }

    #[\Override]
    public function hasMode(string $mode): bool
    {
        return isset(self::$commandMap[$mode]);
    }

    /**
     * @return array<string, string> mode => description
     */
    #[\Override]
    public static function getAvailableModes(): array
    {
        self::registerCommands();

        $modes = [];
        foreach (self::$commandMap as $mode => $class) {
            $modes[$mode] = $class::getDescription();
        }
        return $modes;
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
