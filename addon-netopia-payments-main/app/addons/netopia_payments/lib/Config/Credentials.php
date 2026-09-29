<?php

declare(strict_types=1);

namespace Netopia\CsCart\Config;

use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;

/**
 * Per-mode API credentials.
 *
 * NETOPIA issues a separate API key (and a sandbox account has its own POS
 * signature) for Sandbox and Live, so the settings screen keeps one pair per
 * mode: sandbox_api_key / sandbox_pos_signature and live_api_key /
 * live_pos_signature.
 *
 * Every runtime reader (checkout, IPN, 3DS return, payment links, refunds)
 * reads the plain `api_key` / `pos_signature`. Those are the ACTIVE mode's
 * pair, copied on every save by applyActive(), so switching modes is a
 * matter of picking the mode and saving: no key is ever retyped.
 *
 * Installs from before the split have only the plain pair; forMode() then
 * falls back to it for the mode that was saved, so nothing is lost.
 */
final class Credentials
{
    public const array FIELDS = ['api_key', 'pos_signature'];

    /**
     * The pair a mode uses.
     *
     * @param array<string, mixed> $params
     * @return array{api_key: string, pos_signature: string}
     */
    public static function forMode(array $params, PaymentMode $mode): array
    {
        $savedMode = PaymentMode::fromMixed($params['mode'] ?? null);
        $pair = [];
        foreach (self::FIELDS as $field) {
            $value = trim(Arr::string($params, $mode->value . '_' . $field));
            if ($value === '' && !self::hasSplitFields($params) && $mode === $savedMode) {
                // Pre-split install: the plain value belongs to the saved mode.
                $value = trim(Arr::string($params, $field));
            }
            $pair[$field] = $value;
        }

        return ['api_key' => $pair['api_key'], 'pos_signature' => $pair['pos_signature']];
    }

    /**
     * The params with the plain pair set to the active mode's pair.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function applyActive(array $params): array
    {
        if (!self::hasSplitFields($params)) {
            return $params;
        }

        return array_merge($params, self::forMode($params, PaymentMode::fromMixed($params['mode'] ?? null)));
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function hasSplitFields(array $params): bool
    {
        foreach (PaymentMode::cases() as $mode) {
            foreach (self::FIELDS as $field) {
                if (array_key_exists($mode->value . '_' . $field, $params)) {
                    return true;
                }
            }
        }

        return false;
    }
}
