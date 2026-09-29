<?php

declare(strict_types=1);

namespace Netopia\CsCart\Http;

use Netopia\CsCart\Dto\ApiResponse;

/**
 * Minimal HTTP-poster contract for NETOPIA API calls. Extracted from
 * ApiClient so callers (e.g. RefundService) can be unit-tested with a
 * fake while ApiClient itself stays `final` and uses cURL directly.
 */
interface ApiPoster
{
    public function post(string $endpoint, string $jsonBody): ApiResponse;
}
