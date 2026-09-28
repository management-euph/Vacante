<?php

declare(strict_types=1);

namespace Netopia\CsCart\Log;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that delegates to CS-Cart's fn_log_event().
 *
 * Allows addon code to depend on Psr\Log\LoggerInterface instead of
 * CS-Cart's global functions — keeps business logic portable and testable.
 */
final class CsCartLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $eventType = 'general',
        private readonly string $eventName = 'runtime',
    ) {
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, $message, array $context = []): void
    {
        if (!function_exists('fn_log_event')) {
            return;
        }

        $payload = [
            'message' => '[NETOPIA][' . (is_scalar($level) ? (string) $level : 'unknown') . '] ' . (string) $message,
        ];
        if ($context !== []) {
            $payload['context'] = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        fn_log_event($this->eventType, $this->eventName, $payload);
    }
}
