<?php

declare(strict_types=1);

namespace Netopia\CsCart\Http;

use Netopia\CsCart\Dto\ApiResponse;
use Netopia\CsCart\Exception\ApiException;
use Netopia\Payment2\Enum\PaymentMode;
use Override;
use Psr\Log\LoggerInterface;

/**
 * NETOPIA API client for addon-level calls.
 *
 * Uses cURL directly (kept separate from SDK BaseHttpClient so that
 * CS-Cart addon error handling returns normalized ApiResponse objects
 * instead of throwing — CS-Cart hooks expect flow continuation).
 */
final class ApiClient implements ApiPoster
{
    public const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly string $apiKey,
        private readonly PaymentMode $mode,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws ApiException on missing API key
     */
    #[Override]
    public function post(string $endpoint, string $jsonBody): ApiResponse
    {
        if ($this->apiKey === '') {
            throw new ApiException('API key is not configured.');
        }

        $url = rtrim($this->mode->baseUrl(), '/') . '/' . ltrim($endpoint, '/');

        $this->logger?->info('NETOPIA API request', [
            'url' => $url,
            'body' => self::redactRequestBody($jsonBody),
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return ApiResponse::failure('Failed to initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $error !== '') {
            $this->logger?->warning('NETOPIA API connection error', ['endpoint' => $endpoint, 'error' => $error]);
            return ApiResponse::failure('Connection error occurred.');
        }

        $body = is_string($result) ? $result : '';
        if (!json_validate($body)) {
            $bodySnippet = mb_substr(strip_tags($body), 0, 200);
            $this->logger?->warning('NETOPIA API returned invalid JSON', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'body' => $bodySnippet]);
            return new ApiResponse(0, $httpCode, 'Invalid JSON response from NETOPIA (HTTP ' . $httpCode . '): ' . $bodySnippet, null);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $response = ApiResponse::fromHttp($httpCode, $data);

        if (!$response->isSuccess()) {
            $this->logger?->warning('NETOPIA API error response', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => self::redactResponseBody($body),
            ]);
        }

        return $response;
    }

    /**
     * Strip payment instrument and customer PII from the outbound JSON
     * request so that log sinks never hold card numbers, CVVs, tokens,
     * or billing addresses.
     */
    private static function redactRequestBody(string $jsonBody): string
    {
        if (!json_validate($jsonBody)) {
            return '[non-JSON body]';
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($jsonBody, true, flags: JSON_THROW_ON_ERROR);

        if (isset($decoded['payment']) && is_array($decoded['payment'])) {
            if (isset($decoded['payment']['instrument'])) {
                $decoded['payment']['instrument'] = '[redacted]';
            }
        }

        if (isset($decoded['order']) && is_array($decoded['order'])) {
            foreach (['billing', 'shipping'] as $addressKey) {
                if (isset($decoded['order'][$addressKey])) {
                    $decoded['order'][$addressKey] = '[redacted]';
                }
            }
        }

        return mb_substr((string) json_encode($decoded, JSON_UNESCAPED_SLASHES), 0, 2000);
    }

    /**
     * Keep only the shape of an API response body in logs — omit any
     * payment/customer data the server may echo back.
     */
    private static function redactResponseBody(string $body): string
    {
        if (!json_validate($body)) {
            return mb_substr(strip_tags($body), 0, 500);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if (isset($decoded['payment']) && is_array($decoded['payment'])) {
            if (isset($decoded['payment']['instrument'])) {
                $decoded['payment']['instrument'] = '[redacted]';
            }
            if (isset($decoded['payment']['data'])) {
                $decoded['payment']['data'] = '[redacted]';
            }
        }

        if (isset($decoded['customerAction']) && is_array($decoded['customerAction'])) {
            if (isset($decoded['customerAction']['formData'])) {
                $decoded['customerAction']['formData'] = '[redacted]';
            }
        }

        return mb_substr((string) json_encode($decoded, JSON_UNESCAPED_SLASHES), 0, 2000);
    }
}
