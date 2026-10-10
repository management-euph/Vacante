<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

/**
 * Eurosite HTTP transport.
 *
 * POSTs a raw XML request body to the Eurosite web-service endpoint and returns
 * the raw response, with retry/backoff + a circuit breaker via the shared
 * travel_core ResiliencePolicy (same resilience novoton/sphinx use). The
 * endpoint URL, credentials and tuning come from the settings map handed in by
 * the Container (Registry stays out of src/).
 */
final class EurositeHttpClient implements EurositeTransportInterface
{
    /**
     * curl error numbers that mean the server's certificate could not be
     * verified: 51 (legacy peer verification), 60 (CA/peer/hostname check),
     * 77 (CA bundle unreadable), 82 (CRL unreadable), 83 (issuer check),
     * 90 (pinned key mismatch), 91 (OCSP status). Never retried: a bad
     * certificate does not fix itself, and retrying only repeats the risk.
     */
    private const array TLS_VERIFY_ERRNOS = [51, 60, 77, 82, 83, 90, 91];

    private readonly string $apiUrl;

    private readonly string $apiHost;

    private readonly int $maxRetries;

    private readonly int $timeout;

    private readonly ResiliencePolicy $resilience;

    /**
     * One curl round trip, injectable for tests. Receives (url, curl options),
     * returns [body or false, HTTP code, curl error text, curl errno].
     *
     * @var callable(string, array<int, mixed>): array{string|false, int, string, int}
     */
    private $curlExec;

    // Debug state — last transport result, for probes/logging.
    public int $lastHttpCode = 0;

    public string $lastError = '';

    public string $lastResponseRaw = '';

    /**
     * @param array<string, mixed> $settings ConfigProvider::toClientSettings()
     * @param (callable(string, array<int, mixed>): array{string|false, int, string, int})|null $curlExec
     */
    public function __construct(array $settings, ?ResiliencePolicy $resilience = null, ?callable $curlExec = null)
    {
        $url = trim(TypeCoerce::toString($settings['api_url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException('Eurosite API URL not configured — set api_url in addon settings.');
        }
        // A bare host/path is an https endpoint: never guess plain HTTP.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $scheme = strtolower(TypeCoerce::toString(parse_url($url, PHP_URL_SCHEME)));
        $this->apiHost = TypeCoerce::toString(parse_url($url, PHP_URL_HOST));
        if (!in_array($scheme, ['http', 'https'], true) || $this->apiHost === '') {
            throw new \InvalidArgumentException(
                'Eurosite API URL must be an https:// URL with a host (http:// only with "Allow plain-HTTP '
                . '(unencrypted) API URL" on); got scheme "' . $scheme . '" and host "' . $this->apiHost . '".',
            );
        }
        $this->apiUrl = $url;

        // The flag only unlocks a plain http:// URL. https:// is always
        // certificate-verified (see curlOptions()); nothing turns that off.
        $flag = $settings['allow_insecure_api'] ?? false;
        $allowInsecure = $flag === true || $flag === 'Y' || $flag === '1' || $flag === 1;
        if ($scheme === 'http' && !$allowInsecure) {
            throw new \InvalidArgumentException(
                "Eurosite API URL for {$this->apiHost} uses plain http://, which would send the API "
                . 'credentials unencrypted, and "Allow plain-HTTP (unencrypted) API URL" is off. '
                . 'Use an https:// endpoint, or turn that setting on to accept unencrypted transport.',
            );
        }

        $this->maxRetries = max(1, TypeCoerce::toInt($settings['api_max_retries'] ?? 3));
        $this->timeout = max(5, TypeCoerce::toInt($settings['api_timeout'] ?? 60));

        $this->resilience = $resilience ?? new ResiliencePolicy(
            failureThreshold: max(1, TypeCoerce::toInt($settings['circuit_breaker_threshold'] ?? 5)),
            cooldownSeconds: max(1, TypeCoerce::toInt($settings['circuit_breaker_timeout'] ?? 60)),
            initialDelayMs: 1000,
            delayMultiplier: 2.0,
            resetCountAtHalfOpen: true,
        );

        $this->curlExec = $curlExec ?? self::curlExec(...);
    }

    /**
     * POST an XML request body; return the raw response text.
     *
     * @throws \RuntimeException On an open circuit or an exhausted-retry failure.
     */
    #[\Override]
    public function post(string $xml): string
    {
        if ($this->resilience->isOpen()) {
            $this->lastError = 'Circuit breaker open — Eurosite API temporarily unavailable';
            throw new \RuntimeException($this->lastError);
        }

        $lastError = '';
        $lastHttpCode = 0;
        $response = false;
        $tlsFailed = false;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            [$response, $lastHttpCode, $lastError, $errno] = ($this->curlExec)($this->apiUrl, $this->curlOptions($xml));

            if (($lastError === '') && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                $this->resilience->recordSuccess();
                break;
            }

            if (in_array($errno, self::TLS_VERIFY_ERRNOS, true)) {
                $tlsFailed = true;
                $lastError = "TLS certificate verification failed for host {$this->apiHost}"
                    . ($lastError !== '' ? " ({$lastError})" : '')
                    . ' — check the server certificate and the local CA bundle';
                break;
            }

            $retryable = ResiliencePolicy::isRetryableTransportError($lastError, $lastHttpCode);
            if ($retryable && $attempt < $this->maxRetries) {
                usleep($this->resilience->delayMsForRetry($attempt) * 1000);
            } elseif (!$retryable) {
                break;
            }
        }

        $this->lastHttpCode = $lastHttpCode;
        $this->lastError = $lastError;
        $this->lastResponseRaw = is_string($response) ? $response : '';

        if ($lastError !== '' || $lastHttpCode < 200 || $lastHttpCode >= 300) {
            $this->resilience->recordFailure();
            if ($tlsFailed) {
                fn_log_event('general', 'runtime', ['message' => 'Eurosite API: ' . $lastError]);
            }
            throw new \RuntimeException(
                "Eurosite API request failed (HTTP {$lastHttpCode})" . ($lastError !== '' ? ": {$lastError}" : ''),
            );
        }

        return is_string($response) ? $response : '';
    }

    /**
     * The curl options for one POST. Certificate and host-name checks are on
     * for every request; they only take effect on https://.
     *
     * @return array<int, mixed>
     */
    private function curlOptions(string $xml): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8', 'Accept: text/xml'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
    }

    /**
     * @param array<int, mixed> $options
     *
     * @return array{string|false, int, string, int}
     */
    private static function curlExec(string $url, array $options): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Eurosite: failed to initialize curl');
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $result = [
            is_string($response) ? $response : false,
            (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            curl_error($ch),
            curl_errno($ch),
        ];
        curl_close($ch);

        return $result;
    }
}
