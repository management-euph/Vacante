<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Log;

use Override;
use Psr\Log\AbstractLogger;

/**
 * Recording PSR-3 spy: collects every log() call into `$calls` so
 * tests can assert on level/message/context. Used by CompositeLoggerTest
 * (and any other test that needs to verify what a logger received).
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $calls = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, $message, array $context = []): void
    {
        $this->calls[] = [
            'level' => is_scalar($level) ? (string) $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
