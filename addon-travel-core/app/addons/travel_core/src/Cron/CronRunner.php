<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Shared cron entry-point helper.
 *
 * Extracts the duplicated boilerplate from each addon's cron.php:
 * CLI arg parsing, access-key authentication, mode sanitization,
 * dispatch + output + error handling.
 *
 * Each addon's cron.php becomes ~15 lines: bootstrap, build dispatcher, run().
 *
 * Note: this class legitimately uses `exit()` as a cron entry point,
 * which is why ExitExpression is excluded for this file in phpmd.xml.
 */
class CronRunner
{
    public function __construct(
        private readonly string $addonLabel,
        private readonly CronDispatcherInterface $dispatcher,
        private readonly ?string $defaultMode = null,
        /** @var callable(\Throwable): void|null */
        private readonly mixed $onError = null,
    ) {
    }

    /**
     * Parse CLI/HTTP arguments and return [accessKey, mode, extraParams].
     *
     * @return array{string, string, array<string, string>}
     */
    public static function parseArgs(): array
    {
        global $argv;

        $accessKey = TypeCoerce::toString($_GET['access_key'] ?? '');
        $mode = TypeCoerce::toString($_GET['mode'] ?? '');
        $params = [];

        if (isset($argv) && is_array($argv)) {
            foreach ($argv as $i => $arg) {
                if ($i === 0) {
                    continue;
                }
                $arg = TypeCoerce::toString($arg);
                if (str_starts_with($arg, 'access_key=')) {
                    $accessKey = substr($arg, strlen('access_key='));
                } elseif (str_starts_with($arg, 'mode=')) {
                    $mode = substr($arg, strlen('mode='));
                } elseif (str_contains($arg, '=')) {
                    [$k, $v] = explode('=', $arg, 2);
                    $params[$k] = $v;
                }
            }
        }

        return [$accessKey, $mode, $params];
    }

    /**
     * Authenticate the access key using timing-safe comparison.
     *
     * Returns only on success; a failure terminates via refuse().
     *
     * $addon (the CS-Cart add-on id) is where a refused request is noted in
     * CronRunLog, for Travel Core -> Tools. A WRONG key is what a crontab
     * still on an old key sends after a rotation, so it is the one refusal
     * worth surfacing; an unset stored key is a configuration state the page
     * already shows, so it is not logged as a refusal.
     */
    public static function authenticate(string $storedKey, string $providedKey, string $addonLabel = '', string $addon = ''): void
    {
        if ($storedKey === '') {
            self::refuse("Cron access key not set in {$addonLabel} addon settings.", $addonLabel);
        }
        if ($providedKey === '' || !hash_equals($storedKey, $providedKey)) {
            // Guarded: the refusal itself (403, exit 1) must happen even where
            // the log cannot load — CronAuthFailureTest boots this class alone.
            if (class_exists(CronRunLog::class)) {
                CronRunLog::refused($addon, $providedKey !== '');
            }
            self::refuse('Invalid or missing access key.', $addonLabel);
        }
    }

    /**
     * Refuse a cron request: 403 over HTTP, non-zero exit, and a log line.
     *
     * All three matter, and none of them used to happen.
     *
     * EXIT CODE. Both failures used to end in a bare `exit("ERROR: …")`, which
     * is status **0** — while run() right below uses exit(1) for an unknown
     * mode and for a caught Throwable, so this was a slip rather than a
     * convention. A crontab wrapper checking $? saw success while every
     * scheduled sync was being refused. That is the failure mode of a botched
     * key rotation, and it was invisible.
     *
     * STATUS CODE. Over HTTP the same bare exit sent 200 with no Content-Type,
     * so uptime monitoring pointed at a cron URL reported healthy while the
     * endpoint rejected everything.
     *
     * LOGGING. There was no failed-auth logging anywhere in the repo — only
     * successful starts were logged. With one shared key that is also the only
     * signal distinguishing "nobody ran it" from "someone is guessing at it",
     * so the remote address goes in the line.
     */
    private static function refuse(string $reason, string $addonLabel): never
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }

        if (function_exists('fn_log_event')) {
            $from = TypeCoerce::toString($_SERVER['REMOTE_ADDR'] ?? '');
            fn_log_event('general', 'runtime', [
                'message' => trim("{$addonLabel} cron auth refused: {$reason}")
                    . ($from === '' ? '' : " (from {$from})"),
            ]);
        }

        echo "ERROR: {$reason}\n";

        exit(1);
    }

    /**
     * Sanitize a mode string to alphanumeric + underscores only.
     */
    public static function sanitizeMode(string $mode): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '', strtolower($mode));
    }

    /**
     * Run the full cron lifecycle: validate mode, dispatch, handle output/errors.
     *
     * @param string $mode Sanitized mode name
     * @param array<string,string> $params Extra CLI/HTTP parameters
     */
    public function run(string $mode, array $params = []): never
    {
        // Apply default mode if empty
        if ($mode === '' && $this->defaultMode !== null) {
            $mode = $this->defaultMode;
        }

        // Validate mode
        if (!$this->dispatcher->hasMode($mode)) {
            echo "Unknown mode: {$mode}\n\n";
            echo "Available modes:\n";
            foreach ($this->dispatcher::getAvailableModes() as $m => $desc) {
                echo "  {$m} - {$desc}\n";
            }
            exit(1);
        }

        echo '[' . date('Y-m-d H:i:s') . "] {$this->addonLabel} Cron Started - Mode: {$mode}\n";

        if (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', [
                'message' => "{$this->addonLabel} cron job started (mode: {$mode})",
            ]);
        }

        try {
            $this->dispatcher->dispatch($mode, $params);

            echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
            exit(0);
        } catch (\Throwable $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";

            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', [
                    'message' => "{$this->addonLabel} cron error: " . $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            exit(1);
        }
    }
}
