<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron;

use Tygh\Addons\Eurosite\Cron\Commands\AbstractSyncCommand;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Helpers\CronRunLock;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite cron dispatcher — the sphinx/novoton shape: commands are
 * auto-discovered from Cron/Commands/ (any AbstractSyncCommand subclass
 * registers itself via getModes()), per-mode mutual exclusion uses the
 * shared PID-stamped CronRunLock, and status/reset/debug requests are
 * read-only so they skip the lock.
 */
class CronDispatcher implements CronDispatcherInterface
{
    /** @var array<string, class-string<AbstractSyncCommand>> mode => command class */
    private static array $commandMap = [];

    private static bool $registered = false;

    private static function registerCommands(): void
    {
        if (self::$registered) {
            return;
        }
        self::$commandMap = \Tygh\Addons\TravelCore\Cron\CommandDiscovery::map(
            __DIR__ . '/Commands/',
            'Tygh\\Addons\\Eurosite\\Cron\\Commands\\',
            AbstractSyncCommand::class,
        );
        self::$registered = true;
    }

    /**
     * @return array<string, string> mode => description
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

    #[\Override]
    public function hasMode(string $mode): bool
    {
        self::registerCommands();

        return isset(self::$commandMap[$mode]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function dispatch(string $mode, array $params = []): array
    {
        if (!$this->hasMode($mode)) {
            return ['success' => false, 'error' => "Unknown mode: {$mode}"];
        }

        $isReadOnly = !empty($params['status']) || !empty($params['reset']) || !empty($params['debug']);

        $lock = null;
        if (!$isReadOnly) {
            $lock = new CronRunLock($this->getLockPath($mode));
            if (!$lock->acquire()) {
                return [
                    'success' => false,
                    'busy' => true,
                    'message' => "Mode '{$mode}' is already running. Try again later, or use &status=1 / &reset=1.",
                ];
            }
        }

        try {
            set_time_limit(0);
            $class = self::$commandMap[$mode];
            $command = new $class();
            $command->setOutputCallback(function (string $message, bool $addNewline = true) use ($lock): void {
                echo $message . ($addNewline ? "\n" : '');
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                $lock?->touch();
            });
            // Recorded for Travel Core -> Tools (started, finished, ok, error).
            // Read-only status/reset/debug requests are not runs, so they are not.
            $result = $isReadOnly
                ? $command->execute($params)
                : CronRunLog::record('eurosite', $mode, static fn (): array => $command->execute($params));
        } finally {
            $lock?->release();
        }

        $normalized = ['success' => TypeCoerce::toBool($result['success'] ?? false)];
        foreach (['error', 'message'] as $key) {
            if (array_key_exists($key, $result)) {
                $normalized[$key] = TypeCoerce::toString($result[$key]);
            }
        }
        foreach (['busy', 'stats'] as $key) {
            if (array_key_exists($key, $result)) {
                $normalized[$key] = $result[$key];
            }
        }

        return $normalized;
    }

    private function getLockPath(string $mode): string
    {
        $cacheDir = TypeCoerce::toString(defined('DIR_CACHE') ? DIR_CACHE : sys_get_temp_dir());

        return rtrim($cacheDir, '/') . "/eurosite_cron_{$mode}.lock";
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
