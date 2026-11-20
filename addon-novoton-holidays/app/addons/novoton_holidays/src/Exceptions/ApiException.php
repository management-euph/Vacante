<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Exceptions;

/**
 * Thrown when the Novoton HTTP API returns an error, times out,
 * or is blocked by the circuit breaker.
 */
class ApiException extends NovotonException
{
    private int $httpCode;
    private string $apiFunction;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, string $apiFunction = '', int $httpCode = 0, array $context = [], ?\Throwable $previous = null)
    {
        $this->httpCode = $httpCode;
        $this->apiFunction = $apiFunction;
        parent::__construct($message, array_merge($context, [
            'api_function' => $apiFunction,
            'http_code' => $httpCode,
        ]), $httpCode, $previous);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getApiFunction(): string
    {
        return $this->apiFunction;
    }

    public static function circuitBreakerOpen(string $function, int $secondsUntilRetry): self
    {
        return new self(
            "Circuit breaker open — API temporarily unavailable ({$secondsUntilRetry}s until retry)",
            $function,
            503,
            ['seconds_until_retry' => $secondsUntilRetry],
        );
    }

    public static function requestFailed(string $function, string $error, int $httpCode, int $attempts): self
    {
        return new self(
            "API request failed after {$attempts} attempts: {$error}",
            $function,
            $httpCode,
            ['attempts' => $attempts],
        );
    }

    /** A customer's page gave up waiting for a free slot (NovotonHttpClient::CUSTOMER_GATE_WAIT); nothing was sent. */
    public static function busy(string $function, float $waited): self
    {
        return new self("Novoton is busy: no free request slot within {$waited}s", $function, 503, ['busy' => true]);
    }

    /**
     * A request that creates something (a reservation) failed after it may
     * have reached Novoton: a timeout, a cut connection, an error status. It
     * was not sent again; whether Novoton made it is to be checked there.
     */
    public static function maybeProcessed(string $function, string $error, int $httpCode, int $attempts): self
    {
        return new self(
            "API request failed after {$attempts} attempts, and Novoton may have received it: {$error}",
            $function,
            $httpCode,
            ['attempts' => $attempts, 'maybe_processed' => true],
        );
    }

    /** True when Novoton may have acted on the request (maybeProcessed()). */
    public function mayHaveReachedNovoton(): bool
    {
        return ($this->getContext()['maybe_processed'] ?? false) === true;
    }

    /** Novoton answered with a refusal (Api\Refusal), e.g. a wrong username or password. */
    public static function refused(string $function, string $reason): self
    {
        return new self("Novoton refused {$function}: {$reason}", $function, 200, ['refusal' => $reason]);
    }
}
