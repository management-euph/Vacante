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

    /** Novoton answered with a refusal (Api\Refusal), e.g. a wrong username or password. */
    public static function refused(string $function, string $reason): self
    {
        return new self("Novoton refused {$function}: {$reason}", $function, 200, ['refusal' => $reason]);
    }
}
