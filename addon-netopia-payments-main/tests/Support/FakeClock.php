<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Support;

use DateTimeImmutable;
use Netopia\CsCart\Support\ClockInterface;
use Override;

/**
 * Test clock that returns a fixed timestamp and can be advanced manually.
 * Used by tests that drive time-sensitive logic (TTL expiry,
 * `created_at` round-trips) without sleeping.
 */
final class FakeClock implements ClockInterface
{
    public function __construct(private int $epoch)
    {
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->epoch);
    }

    public function advance(int $seconds): void
    {
        $this->epoch += $seconds;
    }
}
