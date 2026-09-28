<?php

declare(strict_types=1);

namespace Netopia\CsCart\Support;

use DateTimeImmutable;
use Override;

/**
 * Wall-clock implementation of ClockInterface.
 */
final class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function iso8601(): string
    {
        return $this->now()->format('c');
    }
}
