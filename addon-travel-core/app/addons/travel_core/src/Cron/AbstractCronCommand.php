<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

/**
 * Shared base class for cron commands across all travel provider addons.
 *
 * Provides common infrastructure: output handling, duration tracking,
 * error-safe item processing, and result wrapping. Provider addons
 * extend this with addon-specific methods (logging, reporting, etc.).
 */
abstract class AbstractCronCommand
{
    protected ?\Closure $outputCallback = null;

    /**
     * Called on every output(), IN ADDITION to the output callback.
     *
     * Kept separate from $outputCallback on purpose. A long-running command
     * must refresh its CronRunLock or it is taken over as stale mid-run
     * (CronRunLock::STALE_THRESHOLD is 30 minutes and novoton's `full` is
     * documented at 5-30+), but "keep the lock alive" is not "emit output" —
     * and setOutputCallback() REPLACES, so a dispatcher that wired the touch
     * that way would silence whatever routing the command already had. That
     * is exactly the trap novoton was in: its constructor wires SyncLogger as
     * the output callback on the HTTP path and nothing on the CLI path.
     */
    protected ?\Closure $heartbeat = null;

    protected float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Execute the cron command.
     *
     * @return array<string, mixed> Command result with at minimum a 'success' key
     */
    abstract public function execute(): array;

    /**
     * Human-readable description for CLI help output.
     */
    abstract public static function getDescription(): string;

    /**
     * Cron mode identifiers this command handles.
     *
     * Novoton's CronDispatcher uses this to register commands by mode.
     * Sphinx commands that don't use the mode concept inherit the empty
     * default and are registered by other means.
     *
     * @return string[]
     */
    public static function getModes(): array
    {
        return [];
    }

    /**
     * Set a callback for output messages (used in web/CLI contexts).
     *
     * NOTE: this REPLACES any existing callback. To observe output without
     * disturbing where it goes — keeping a run lock alive, for instance — use
     * setHeartbeat().
     */
    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Observe every output() without changing where the output goes.
     */
    public function setHeartbeat(\Closure $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
    }

    /**
     * Emit an output message via the callback, or fall back to fn_log_event.
     *
     * The `$addNewline` flag supports prompt-style output where a command
     * wants to emit a partial line ("[hotel] processing... ") followed by
     * a completion marker on the same line ("OK"). The callback receives
     * the flag as a second argument; callbacks that don't care about line
     * breaks can ignore it.
     */
    protected function output(string $message, bool $addNewline = true): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }

        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message, $addNewline);
            return;
        }

        if (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', [
                'message' => 'CronCommand: ' . $message,
            ]);
        }
    }

    /**
     * Elapsed seconds since command construction, rounded to 1 decimal.
     */
    protected function getDuration(): float
    {
        return round(microtime(true) - $this->startTime, 1);
    }

    /**
     * Execute a sync operation with standardized error handling.
     *
     * Catches all Throwable, logs consistently. Returns true on success.
     *
     * @param callable $work The operation to execute
     * @param string $context Human-readable context for error messages
     * @param array<int, string> &$errors Array to collect error messages
     */
    protected function trySyncItem(callable $work, string $context, array &$errors): bool
    {
        try {
            $work();
            return true;
        } catch (\Throwable $e) {
            $errors[] = "Error for {$context}: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Wrap command stats into a standardized result array.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    protected function wrapResult(array $stats): array
    {
        return [
            'success' => $stats['success'] ?? true,
            'stats' => $stats,
        ];
    }
}
