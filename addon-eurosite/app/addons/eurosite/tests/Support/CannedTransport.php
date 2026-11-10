<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Support;

use Tygh\Addons\Eurosite\EurositeTransportInterface;

/**
 * A transport that answers every request with the same text and keeps the
 * last request sent, so a test can check both sides of a call.
 */
final class CannedTransport implements EurositeTransportInterface
{
    public string $lastRequest = '';

    public function __construct(private readonly string $answer)
    {
    }

    #[\Override]
    public function post(string $xml): string
    {
        $this->lastRequest = $xml;

        return $this->answer;
    }
}
