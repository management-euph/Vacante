<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

/**
 * HTTP client for the Sphinx REST API.
 *
 * Handles Bearer token authentication, rate limiting, retry logic with
 * exponential backoff, and circuit breaker pattern.
 *
 * A booking must never be sent twice: postOnce() resends it only when it
 * surely never left (no connection, a rate-limit refusal). After an HTTP
 * 5xx or a timeout the booking may exist at Sphinx — maybeProcessed() says
 * so, and the caller checks before booking again.
 */
class SphinxHttpClient
{
    private const int CURL_TIMEOUT = 30;
    private const int CURL_CONNECT_TIMEOUT = 10;
    private const int RATE_LIMIT_PAUSE_DEFAULT = 5;
    private const int RATE_LIMIT_MAX_WAIT = 120;
    private const int RATE_LIMIT_FALLBACK_WAIT = 60;

    /** cURL errors that prove the request never reached the server: no proxy, no host, no connection, no TLS. */
    private const array NOT_SENT_ERRNOS = [5, 6, 7, 35];

    private string $baseUrl;
    private string $apiKey;
    private int $maxRetries;
    private int $cbTimeout;
    private bool $debugLogging;

    // Shared retry-backoff + circuit-breaker state machine (travel_core).
    // Half-open RESETS the failure count (this client's historical behavior:
    // after cooldown it takes a full new threshold of failures to re-open).
    private ResiliencePolicy $resilience;

    private int $lastHttpCode = 0;
    private string $lastError = '';
    private ?string $lastResponseRaw = null;
    private ?int $rateLimitLimit = null;
    private ?int $rateLimitRemaining = null;
    private ?int $rateLimitReset = null;
    private ?int $retryAfter = null;
    private int $rateLimitHitCount = 0;

    /** The last request got no answer, but may have been processed (an HTTP 5xx, a timeout). */
    private bool $maybeProcessed = false;

    /** @var (\Closure(string, string, list<string>, ?string): array{body: string|false, code: int, errno?: int, error?: string, headers?: list<string>})|null */
    private ?\Closure $exec;

    /**
     * @param string $baseUrl the API's base URL
     * @param string $apiKey the Bearer token
     * @param int $maxRetries retries of a repeatable request
     * @param int $retryDelayMs the first retry's delay
     * @param float $retryMultiplier each next delay's factor
     * @param int $cbThreshold failures that open the circuit
     * @param int $cbTimeout seconds the circuit stays open
     * @param bool $debugLogging log rate limits and pauses
     * @param ResiliencePolicy|null $resilience the backoff and circuit state (built from the above when null)
     * @param (\Closure(string, string, list<string>, ?string): array{body: string|false, code: int, errno?: int, error?: string, headers?: list<string>})|null $exec test seam: one HTTP exchange (method, url, headers, body) instead of cURL
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        int $maxRetries = 3,
        int $retryDelayMs = 500,
        float $retryMultiplier = 2.0,
        int $cbThreshold = 5,
        int $cbTimeout = 60,
        bool $debugLogging = false,
        ?ResiliencePolicy $resilience = null,
        ?\Closure $exec = null,
    ) {
        $this->exec = $exec;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->maxRetries = $maxRetries;
        $this->cbTimeout = $cbTimeout;
        $this->debugLogging = $debugLogging;
        $this->resilience = $resilience ?? new ResiliencePolicy(
            failureThreshold: $cbThreshold,
            cooldownSeconds: $cbTimeout,
            initialDelayMs: $retryDelayMs,
            delayMultiplier: $retryMultiplier,
            resetCountAtHalfOpen: true,
        );
    }

    /**
     * Send a GET request.
     *
     * @param string $endpoint API endpoint (e.g., '/api/v1/static/destinations')
     * @param array<string, mixed> $query Query parameters
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function get(string $endpoint, array $query = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url);
    }

    /**
     * Send a POST request.
     *
     * @param string $endpoint API endpoint
     * @param array<string, mixed> $data Request body (will be JSON-encoded)
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function post(string $endpoint, array $data = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        return $this->request('POST', $url, $data);
    }

    /**
     * Send a POST that must not be sent twice (a booking): resent only when
     * it surely never reached Sphinx. On an HTTP 5xx or a timeout it is not
     * resent, and maybeProcessed() is true.
     *
     * @param string $endpoint API endpoint
     * @param array<string, mixed> $data Request body (will be JSON-encoded)
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function postOnce(string $endpoint, array $data = []): ?array
    {
        return $this->request('POST', $this->baseUrl . $endpoint, $data, false);
    }

    /**
     * Whether the last request, which got no answer, may still have been
     * processed (an HTTP 5xx, a timeout) — false for a refusal (4xx) or a
     * request that never left (circuit open, no connection).
     */
    public function maybeProcessed(): bool
    {
        return $this->maybeProcessed;
    }

    /**
     * Execute an HTTP request with retry and circuit breaker logic.
     * @param string $method GET or POST
     * @param string $url the full URL
     * @param array<string, mixed> $body
     * @param bool $resend false: never resend once the request may have reached the server
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, ?array $body = null, bool $resend = true): ?array
    {
        $this->maybeProcessed = false;

        // Circuit breaker check
        if ($this->isCircuitOpen()) {
            $this->lastError = 'Circuit breaker is open — API calls suspended for ' . $this->cbTimeout . 's';
            return null;
        }

        $attempt = 0;
        $delaysTaken = 0;

        while ($attempt <= $this->maxRetries) {
            // Proactive throttle: pause when approaching rate limit
            if ($this->rateLimitRemaining !== null && $this->rateLimitRemaining <= 2) {
                $pauseSeconds = ($this->rateLimitReset !== null)
                    ? max(1, min(30, $this->rateLimitReset - time()))
                    : self::RATE_LIMIT_PAUSE_DEFAULT;
                $this->log("Approaching rate limit (remaining={$this->rateLimitRemaining}). Pausing {$pauseSeconds}s.");
                sleep($pauseSeconds);
            }

            // Reset per-attempt header state
            $this->rateLimitLimit = null;
            $this->rateLimitRemaining = null;
            $this->rateLimitReset = null;
            $this->retryAfter = null;

            $headers = [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ];
            $payload = $method === 'POST' && $body !== null ? (string) json_encode($body) : null;

            [$response, $this->lastHttpCode, $curlErrno, $curlError] = $this->send($method, $url, $headers, $payload);

            $this->lastResponseRaw = is_string($response) ? $response : null;

            // cURL error (network failure)
            if ($response === false) {
                $this->lastError = "cURL error: {$curlError}";
                $this->recordFailure();
                if (!in_array($curlErrno, self::NOT_SENT_ERRNOS, true)) {
                    // A timeout or a cut connection: the request may have reached Sphinx.
                    $this->maybeProcessed = true;
                    if (!$resend) {
                        $this->lastError .= ' (not resent: the request may have reached Sphinx)';

                        return null;
                    }
                }
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep($this->resilience->delayMsForRetry(++$delaysTaken) * 1000);
                }
                continue;
            }

            // Rate limited — wait and retry
            if ($this->lastHttpCode === 429) {
                $this->rateLimitHitCount++;
                // Priority: Retry-After (seconds) > X-RateLimit-Reset (timestamp) > fallback 60s
                // Properties are set by curl HEADERFUNCTION callback (parseResponseHeaders)
                /** @var int|null $retryAfter */
                $retryAfter = $this->retryAfter;
                /** @var int|null $rateLimitReset */
                $rateLimitReset = $this->rateLimitReset;
                if ($retryAfter !== null && $retryAfter > 0) {
                    $waitSeconds = $retryAfter;
                } elseif ($rateLimitReset !== null) {
                    $waitSeconds = max(1, $rateLimitReset - time());
                } else {
                    $waitSeconds = self::RATE_LIMIT_FALLBACK_WAIT;
                }
                $waitSeconds = min($waitSeconds, self::RATE_LIMIT_MAX_WAIT);
                $this->lastError = "Rate limited. Waiting {$waitSeconds}s.";
                if ($this->debugLogging) {
                    fn_log_event('general', 'runtime', ['message' => "[SphinxHttpClient] Rate limited on {$method} {$url}. Waiting {$waitSeconds}s. Remaining: {$this->rateLimitRemaining}, Limit: {$this->rateLimitLimit}"]);
                }
                sleep($waitSeconds);
                $attempt++;
                continue;
            }

            // Server error — retry (never a request that must not be sent twice)
            if ($this->lastHttpCode >= 500) {
                $this->lastError = "Server error HTTP {$this->lastHttpCode}";
                $this->recordFailure();
                $this->maybeProcessed = true;
                if (!$resend) {
                    $this->lastError .= ' (not resent: the request may have reached Sphinx)';

                    return null;
                }
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep($this->resilience->delayMsForRetry(++$delaysTaken) * 1000);
                }
                continue;
            }

            // Client error (4xx except 429) — do not retry
            if ($this->lastHttpCode >= 400) {
                $this->lastError = "Client error HTTP {$this->lastHttpCode}: {$response}";
                return null;
            }

            // Success
            $this->resetFailures();
            $decoded = json_decode((string) $response, true);
            if (!is_array($decoded)) {
                // Distinguish a genuine parse failure from a valid-but-scalar
                // body (e.g. the /ping endpoint replies with the bare string
                // "pong"). Reporting "JSON decode error: No error" on the
                // latter is misleading during diagnostics.
                $this->lastError = json_last_error() !== JSON_ERROR_NONE
                    ? 'JSON decode error: ' . json_last_error_msg()
                    : 'Unexpected response shape: expected a JSON object/array';
                return null;
            }

            // Clear any error left over from a previous request on this client
            // instance so getLastError() reflects only the call that just ran.
            $this->lastError = '';

            return TypeCoerce::toStringMap($decoded);
        }

        // All retries exhausted
        return null;
    }

    /**
     * One HTTP exchange: through the test seam when one was given, else cURL.
     *
     * @param string $method GET or POST
     * @param string $url the full URL
     * @param list<string> $headers request headers
     * @param string|null $payload the JSON body of a POST
     *
     * @return array{0: string|false, 1: int, 2: int, 3: string} the body (false on a transport error), HTTP code, cURL errno, cURL error
     */
    private function send(string $method, string $url, array $headers, ?string $payload): array
    {
        if ($this->exec !== null) {
            $result = ($this->exec)($method, $url, $headers, $payload);
            foreach ($result['headers'] ?? [] as $line) {
                $this->parseHeaderLine($line);
            }

            return [$result['body'], $result['code'], $result['errno'] ?? 0, $result['error'] ?? ''];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => [$this, 'parseResponseHeaders'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($payload !== null) {
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        return [is_string($response) ? $response : false, $code, $errno, $error];
    }

    /**
     * Parse rate limit headers from response.
     *
     * Note: called via `CURLOPT_HEADERFUNCTION => [$this, 'parseResponseHeaders']`,
     * which PHPMD's UnusedPrivateMethod sniff cannot detect.
     */
    private function parseResponseHeaders(\CurlHandle $ch, string $header): int
    {
        $this->parseHeaderLine($header);

        return strlen($header);
    }

    /** One response header line: the rate-limit ones are kept. */
    private function parseHeaderLine(string $header): void
    {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($name === 'x-ratelimit-limit') {
                $this->rateLimitLimit = (int) $value;
            } elseif ($name === 'x-ratelimit-remaining') {
                $this->rateLimitRemaining = (int) $value;
            } elseif ($name === 'x-ratelimit-reset') {
                $this->rateLimitReset = (int) $value;
            } elseif ($name === 'retry-after') {
                $this->retryAfter = (int) $value;
            }
        }
    }

    public function isCircuitOpen(): bool
    {
        return $this->resilience->isOpen();
    }

    private function recordFailure(): void
    {
        $this->resilience->recordFailure();
    }

    private function resetFailures(): void
    {
        $this->resilience->recordSuccess();
    }

    private function log(string $message): void
    {
        if ($this->debugLogging) {
            fn_log_event('general', 'runtime', ['message' => '[SphinxHttpClient] ' . $message]);
        }
    }

    public function getCircuitBreakerTimeout(): int
    {
        return $this->cbTimeout;
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }
    public function getLastError(): string
    {
        return $this->lastError;
    }
    public function getLastResponseRaw(): ?string
    {
        return $this->lastResponseRaw;
    }
    public function getRateLimitLimit(): ?int
    {
        return $this->rateLimitLimit;
    }
    public function getRateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }
    public function getRateLimitReset(): ?int
    {
        return $this->rateLimitReset;
    }
    public function getRateLimitHitCount(): int
    {
        return $this->rateLimitHitCount;
    }

    /**
     * Get full rate limit state for monitoring/logging.
     * @return array<string, mixed>
     */
    public function getRateLimitState(): array
    {
        return [
            'limit' => $this->rateLimitLimit,
            'remaining' => $this->rateLimitRemaining,
            'reset' => $this->rateLimitReset,
            'reset_in' => $this->rateLimitReset !== null ? max(0, $this->rateLimitReset - time()) : null,
        ];
    }
}
