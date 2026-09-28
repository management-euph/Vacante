<?php

declare(strict_types=1);

namespace Netopia\CsCart\Log;

use Override;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * PSR-3 fan-out: forwards every log() call to each of the inner
 * loggers. Lets Bootstrap pair CsCartLogger (CS-Cart admin Logs UI)
 * with FileLogger (tail-able var/log file) without forcing every
 * caller to hold both.
 *
 * Each inner is wrapped in its own try/catch — one failing inner
 * cannot suppress the others. A logger must never throw.
 */
final class CompositeLogger extends AbstractLogger
{
    /**
     * @param list<LoggerInterface> $loggers
     */
    public function __construct(
        private readonly array $loggers,
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
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (Throwable) {
                // Swallow: a noisy inner mustn't suppress the others,
                // and a logger must never bubble into business logic.
            }
        }
    }
}
