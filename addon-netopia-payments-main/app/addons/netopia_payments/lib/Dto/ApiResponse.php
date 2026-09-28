<?php

declare(strict_types=1);

namespace Netopia\CsCart\Dto;

use Netopia\CsCart\Support\Arr;

/**
 * Immutable normalized NETOPIA API response.
 */
final readonly class ApiResponse
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public int $status,
        public int $code,
        public string $message,
        public ?array $data,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === 1 && $this->data !== null;
    }

    public static function failure(string $message, int $httpCode = 0): self
    {
        return new self(0, $httpCode, $message, null);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromHttp(int $httpCode, ?array $data): self
    {
        if ($httpCode === 200) {
            return new self(1, $httpCode, 'OK', $data);
        }

        $msg = 'HTTP ' . $httpCode;
        if ($data !== null) {
            $errMsg = $data['message'] ?? $data['error'] ?? $data['errorMessage'] ?? null;
            if (is_scalar($errMsg) && (string) $errMsg !== '') {
                $msg .= ': ' . (string) $errMsg;
            }
        }

        return new self(0, $httpCode, $msg, $data);
    }

    /**
     * Look up a block inside the response envelope, tolerating both the bare
     * `{key: {...}}` layout and the nested `{data: {key: {...}}}` layout.
     *
     * @return array<string, mixed>
     */
    public function envelopeBlock(string $key): array
    {
        if ($this->data === null) {
            return [];
        }

        $direct = Arr::array($this->data, $key);
        if ($direct !== []) {
            return $direct;
        }

        $nested = Arr::array($this->data, 'data');
        if ($nested !== []) {
            return Arr::array($nested, $key);
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function errorBlock(): array
    {
        return $this->envelopeBlock('error');
    }

    /** @return array<string, mixed> */
    public function paymentData(): array
    {
        return $this->envelopeBlock('payment');
    }

    /** @return array<string, mixed> */
    public function customerAction(): array
    {
        return $this->envelopeBlock('customerAction');
    }
}
