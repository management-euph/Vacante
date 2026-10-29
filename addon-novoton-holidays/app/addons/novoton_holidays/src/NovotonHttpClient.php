<?php

declare(strict_types=1);

/**
 * Novoton HTTP Client
 * Handles HTTP requests, retries with exponential backoff, and circuit breaker.
 *
 * Path: app/addons/novoton_holidays/src/NovotonHttpClient.php
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

class NovotonHttpClient implements HttpClientInterface
{
    private string $apiUrl;
    private bool $allowInsecureApi;
    private string $apiKey;
    private string $apiId;
    private string $apiUser;
    private string $apiPassword;

    // Retry configuration
    private int $maxRetries;

    // Shared retry-backoff + circuit-breaker state machine (travel_core).
    // Half-open KEEPS the failure count (this client's historical behavior:
    // one failure after cooldown re-opens the circuit immediately).
    private ResiliencePolicy $resilience;
    private int $circuitBreakerThreshold;
    private int $circuitBreakerTimeout;

    // Every request of the store, jobs and customers' searches alike, within
    // what Novoton asked for: 5 at the same time, 5 a second (RequestGate).
    private RequestGate $gate;

    // Debug properties
    public int $lastHttpCode = 0;
    public string $lastError = '';
    public string $lastResponseRaw = '';

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings, ?ResiliencePolicy $resilience = null, ?RequestGate $gate = null)
    {
        $this->gate = $gate ?? RequestGate::shared();
        if (empty($settings['api_url'])) {
            throw new \InvalidArgumentException(
                'Novoton API URL not configured — set api_url in addon settings',
            );
        }
        $apiUrl = TypeCoerce::toString($settings['api_url']);
        // Preserve scheme if provided in settings, otherwise default to http://
        // (Novoton API provider currently specifies http:// only)
        if (preg_match('#^https?://#', $apiUrl) === 1) {
            $this->apiUrl = $apiUrl;
        } else {
            $this->apiUrl = 'http://' . $apiUrl;
        }

        // Insecure-transport policy. The Novoton API provider currently exposes
        // http:// only, so the opt-in defaults to enabled to keep the addon
        // working out of the box; operators can switch it off once the provider
        // offers https:// to hard-fail any accidental plaintext credential leak.
        $insecureSetting = $settings['allow_insecure_api'] ?? 'Y';
        $this->allowInsecureApi = $insecureSetting === true
            || $insecureSetting === 'Y'
            || $insecureSetting === '1'
            || $insecureSetting === 1;

        // Refuse to send credentials over unencrypted HTTP unless explicitly allowed.
        if (str_starts_with(strtolower($this->apiUrl), 'http://')) {
            if (!$this->allowInsecureApi) {
                throw new \InvalidArgumentException(
                    'Novoton API URL uses insecure HTTP but "allow_insecure_api" is disabled. '
                    . 'Set api_url to an https:// endpoint, or re-enable '
                    . '"Allow insecure (HTTP) API connection" in addon settings to permit '
                    . 'sending credentials unencrypted.',
                );
            }
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API URL uses HTTP — credentials are sent unencrypted. '
                    . 'When the provider supports HTTPS, update api_url in addon settings '
                    . 'and disable "Allow insecure (HTTP) API connection".',
            ]);
        }
        $this->apiKey = TypeCoerce::toString($settings['api_key'] ?? '');
        $this->apiId = TypeCoerce::toString($settings['api_id'] ?? '');
        $this->apiUser = TypeCoerce::toString($settings['api_user'] ?? '');
        $this->apiPassword = TypeCoerce::toString($settings['api_password'] ?? '');

        if (empty($this->apiKey) || empty($this->apiUser) || empty($this->apiPassword)) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API credentials not configured — set api_key, api_user, and api_password in addon settings',
                'has_key' => !empty($this->apiKey),
                'has_user' => !empty($this->apiUser),
                'has_password' => !empty($this->apiPassword),
            ]);
        }

        $this->maxRetries = TypeCoerce::toInt($settings['api_max_retries'] ?? 3);
        $this->circuitBreakerThreshold = TypeCoerce::toInt($settings['circuit_breaker_threshold'] ?? 5);
        $this->circuitBreakerTimeout = TypeCoerce::toInt($settings['circuit_breaker_timeout'] ?? 60);
        $this->resilience = $resilience ?? new ResiliencePolicy(
            failureThreshold: $this->circuitBreakerThreshold,
            cooldownSeconds: $this->circuitBreakerTimeout,
            initialDelayMs: TypeCoerce::toInt($settings['api_retry_delay_ms'] ?? 1000),
            delayMultiplier: (float) max(1, TypeCoerce::toInt($settings['api_retry_multiplier'] ?? 2)),
            resetCountAtHalfOpen: false,
        );
    }

    /**
     * @return string API username (for XML request building)
     */
    public function getApiUser(): string
    {
        return $this->apiUser;
    }

    /**
     * @return string API password (for XML request building)
     */
    public function getApiPassword(): string
    {
        return $this->apiPassword;
    }

    /**
     * Send POST request to Novoton API with retry and circuit breaker
     *
     * @param string $function API function name
     * @param string $xml XML request body
     * @param string $lang Language code
     * @return string Raw response body
     * @throws ApiException On circuit breaker open or request failure after retries
     */
    public function sendRequest(string $function, string $xml = '', string $lang = 'UK'): string
    {
        if (!$this->isCircuitClosed()) {
            $secondsUntilRetry = $this->resilience->secondsUntilRetry();
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API request blocked by circuit breaker',
                'function' => $function,
                'seconds_until_retry' => $secondsUntilRetry,
            ]);
            $this->lastError = 'Circuit breaker open - API temporarily unavailable';
            throw ApiException::circuitBreakerOpen($function, $secondsUntilRetry);
        }

        $url = $this->apiUrl . '/index.php';

        $postData = [
            'fn' => $function,
            'key' => $this->apiKey,
            'id' => $this->apiId,
            'xml' => $xml,
            'lang' => $lang,
        ];

        $lastError = '';
        $lastHttpCode = 0;
        $response = false;
        $attempt = 1;

        for (; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init();
            if ($ch === false) {
                throw ApiException::requestFailed($function, 'Failed to initialize curl', 0, 1);
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_REFERER, Constants::IMAGE_BASE_URL);
            // Intentionally higher than Constants::API_CONNECT_TIMEOUT/API_TIMEOUT —
            // Novoton API can be slow for search/verify endpoints with complex room configs.
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // One ticket per attempt, given back before any retry wait.
            $tickets = $this->gate->acquire();
            try {
                $response = curl_exec($ch);
            } finally {
                $this->gate->release($tickets);
            }
            $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);

            curl_close($ch);

            if (($lastError === '' || $lastError === '0') && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                $this->recordSuccess();
                break;
            }

            $isRetryable = ResiliencePolicy::isRetryableTransportError($lastError, $lastHttpCode);

            if ($isRetryable && $attempt < $this->maxRetries) {
                $delayMs = $this->resilience->delayMsForRetry($attempt);

                fn_log_event('general', 'runtime', [
                    'message' => "Novoton API retry attempt $attempt/$this->maxRetries",
                    'function' => $function,
                    'error' => $lastError,
                    'http_code' => $lastHttpCode,
                    'delay_ms' => $delayMs,
                ]);

                usleep($delayMs * 1000);
            } elseif (!$isRetryable) {
                break;
            }
        }

        $this->lastHttpCode = $lastHttpCode;
        $this->lastError = $lastError;
        $this->lastResponseRaw = is_string($response) ? $response : '';

        if (($lastError !== '' && $lastError !== '0') || $lastHttpCode < 200 || $lastHttpCode >= 300) {
            $this->recordFailure();
            $attempts = min($attempt, $this->maxRetries);
            fn_log_event('general', 'runtime', [
                'message' => "Novoton API Error after {$attempts} attempts: {$lastError}",
                'function' => $function,
                'http_code' => $lastHttpCode,
                'circuit_status' => $this->getCircuitStatus(),
            ]);
            throw ApiException::requestFailed($function, $lastError, $lastHttpCode, $attempts);
        }

        return is_string($response) ? $response : '';
    }

    /**
     * Send multiple requests in parallel using curl_multi
     *
     * Each round sends as many requests as the gate has tickets free (at most
     * $concurrency), so a batch shares Novoton's 5-at-once, 5-a-second
     * allowance with every other job and with customers' searches.
     *
     * @param array<string, mixed> $requests Keyed array: key => ['function' => ..., 'xml' => ..., 'lang' => ...]
     * @param int $concurrency Max simultaneous requests
     * @return array<string, mixed> key => raw response string or false
     */
    public function sendBatchRequests(array $requests, int $concurrency = 5): array
    {
        if (empty($requests)) {
            return [];
        }

        if (!$this->isCircuitClosed()) {
            return array_fill_keys(array_keys($requests), false);
        }

        $url = $this->apiUrl . '/index.php';
        $results = [];
        $mh = curl_multi_init();

        $pending = $requests;

        while ($pending !== []) {
            $want = min(max(1, $concurrency), count($pending));
            $tickets = $this->gate->acquire($want);
            // No tickets means the gate is off (its file cannot be used).
            $take = $tickets === [] ? $want : count($tickets);
            $chunk = array_slice($pending, 0, $take, true);
            $pending = array_slice($pending, $take, null, true);
            $handles = [];

            foreach ($chunk as $key => $req) {
                $reqData = TypeCoerce::toStringMap($req);
                $postData = [
                    'fn' => $reqData['function'],
                    'key' => $this->apiKey,
                    'id' => $this->apiId,
                    'xml' => $reqData['xml'],
                    'lang' => $reqData['lang'] ?? 'UK',
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                curl_setopt($ch, CURLOPT_REFERER, Constants::IMAGE_BASE_URL);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                // Enable HTTP/2 multiplexing for better batch throughput
                if (defined('CURL_HTTP_VERSION_2_0')) {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
                }

                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            $running = null;
            try {
                do {
                    curl_multi_exec($mh, $running);
                    curl_multi_select($mh);
                } while ($running > 0);
            } finally {
                $this->gate->release($tickets);
            }

            foreach ($handles as $key => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);

                if (($error === '' || $error === '0') && $httpCode >= 200 && $httpCode < 300 && !empty($response)) {
                    $this->recordSuccess();
                    $results[$key] = $response;
                } else {
                    $this->recordFailure();
                    $results[$key] = false;
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            usleep(Constants::API_DELAY_LIGHT);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Check if circuit breaker allows requests
     */
    public function isCircuitClosed(): bool
    {
        return !$this->resilience->isOpen();
    }

    /**
     * Record API failure for circuit breaker
     */
    public function recordFailure(): void
    {
        if ($this->resilience->recordFailure()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API circuit breaker OPENED after ' . $this->resilience->failureCount() . ' failures',
                'threshold' => $this->circuitBreakerThreshold,
                'timeout_seconds' => $this->circuitBreakerTimeout,
            ]);
        }
    }

    /**
     * Record API success - reset circuit breaker
     */
    public function recordSuccess(): void
    {
        $previousFailures = $this->resilience->failureCount();
        if ($this->resilience->recordSuccess()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API circuit breaker RESET after success',
                'previous_failures' => $previousFailures,
            ]);
        }
    }

    /**
     * Get circuit breaker status (for monitoring)
     * @return array{is_open: bool, failure_count: int, threshold: int, last_failure: string|null, timeout_seconds: int, seconds_until_retry: int}
     */
    public function getCircuitStatus(): array
    {
        $lastFailureAt = $this->resilience->lastFailureAt();

        return [
            'is_open' => $this->resilience->isOpen(),
            'failure_count' => $this->resilience->failureCount(),
            'threshold' => $this->circuitBreakerThreshold,
            'last_failure' => $lastFailureAt > 0 ? date('Y-m-d H:i:s', $lastFailureAt) : null,
            'timeout_seconds' => $this->circuitBreakerTimeout,
            'seconds_until_retry' => $this->resilience->secondsUntilRetry(),
        ];
    }

    /**
     * Manually reset circuit breaker (for admin use)
     */
    public function resetCircuitBreaker(): void
    {
        $wasOpen = $this->resilience->isOpen();
        $previousFailures = $this->resilience->failureCount();

        $this->resilience->forceReset();

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton API circuit breaker manually reset',
            'was_open' => $wasOpen,
            'previous_failures' => $previousFailures,
        ]);
    }
}
