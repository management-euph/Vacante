<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite;

/**
 * The transport seam for the Eurosite API client.
 *
 * EurositeHttpClient is the production implementation (curl + resilience);
 * tests inject a fake that captures the request XML and returns a canned
 * response, so EurositeApiClient's request-building and response-mapping are
 * unit-testable without network.
 */
interface EurositeTransportInterface
{
    /**
     * POST an XML request body; return the raw response text.
     */
    public function post(string $xml): string;
}
