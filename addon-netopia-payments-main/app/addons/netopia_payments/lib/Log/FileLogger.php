<?php

declare(strict_types=1);

namespace Netopia\CsCart\Log;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * PSR-3 logger that appends one JSON-encoded line per call to a
 * date-stamped file in CS-Cart's `var/log/` directory.
 *
 * Each line: {"ts":"<ISO 8601>","level":"<rfc-5424>","message":"<text>","context":{...}}
 *
 * Date-stamped filename ({prefix}-Y-m-d.log) gives natural daily
 * rotation; admins prune old files via cron / `find -mtime +30 -delete`.
 *
 * Production-safe: if the target dir isn't writable (typical in
 * tests/CLI or a fresh deploy with stale perms), the constructor caches
 * that fact and every subsequent log() call is a silent no-op. Write
 * errors are caught and swallowed — a logger must NEVER bubble an
 * exception into the payment flow.
 */
final class FileLogger extends AbstractLogger
{
    /**
     * Hard cap on the JSON-encoded context size per log line. 8 KB
     * keeps a runaway $context (e.g. an admin pasting a 1 MB blob into
     * an order note that ends up in our context) from blowing up the
     * file or busting the line-buffer of common JSON tools.
     */
    private const int CONTEXT_BYTE_CAP = 8192;

    private readonly bool $writable;

    public function __construct(
        private readonly string $logDir,
        private readonly string $filenamePrefix = 'netopia_payments',
    ) {
        // Resolve writability once. is_writable() does its own stat; we
        // don't want to pay that on every log line in a hot IPN loop.
        $this->writable = $logDir !== '' && is_dir($logDir) && is_writable($logDir);
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, $message, array $context = []): void
    {
        if (!$this->writable) {
            return;
        }

        // Install a no-op error handler around the write so transient
        // file_put_contents() warnings (perms race, disk full, partial
        // write) cannot become exceptions under a strict global error
        // handler — CS-Cart's debug mode is known to convert warnings
        // to throws. Restored unconditionally; the try/catch is
        // belt-and-braces against anything else exotic. A logger must
        // never bubble into the payment flow.
        set_error_handler(static fn (): bool => true);
        try {
            $line = $this->formatLine(
                is_scalar($level) ? (string) $level : 'unknown',
                (string) $message,
                $context,
            );
            $path = $this->logDir . '/' . $this->filenamePrefix . '-' . date('Y-m-d') . '.log';
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Lose this line. CsCartLogger (sibling under CompositeLogger)
            // still captured it for the admin Logs UI.
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function formatLine(string $level, string $message, array $context): string
    {
        $payload = [
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
        ];

        if ($context !== []) {
            $encodedContext = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedContext === false) {
                $payload['context'] = '[non-encodable context]';
            } elseif (strlen($encodedContext) > self::CONTEXT_BYTE_CAP) {
                // Truncate the encoded form rather than the array, so
                // we keep top-level keys and just lose tails of long
                // string values. The trailing "...[truncated]" marker
                // makes the line still grep-able as JSON-ish even
                // though it's no longer valid JSON for the context
                // field — preferable to silently dropping data.
                $payload['context'] = substr($encodedContext, 0, self::CONTEXT_BYTE_CAP) . '...[truncated]';
            } else {
                $payload['context'] = $context;
            }
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ($encoded === false ? '{"ts":"' . date('c') . '","level":"error","message":"[non-encodable log payload]"}' : $encoded) . "\n";
    }
}
