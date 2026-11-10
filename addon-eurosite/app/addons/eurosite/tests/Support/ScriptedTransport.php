<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Support;

use Tygh\Addons\Eurosite\EurositeTransportInterface;

/**
 * A transport that answers each request with what a test's closure makes of
 * it (by its RequestType, its Transport, ...) and keeps every request sent.
 */
final class ScriptedTransport implements EurositeTransportInterface
{
    /** @var list<string> */
    public array $requests = [];

    /** @param \Closure(string): string $answer the request's XML => the answer */
    public function __construct(private readonly \Closure $answer)
    {
    }

    #[\Override]
    public function post(string $xml): string
    {
        $this->requests[] = $xml;

        return ($this->answer)($xml);
    }
}
