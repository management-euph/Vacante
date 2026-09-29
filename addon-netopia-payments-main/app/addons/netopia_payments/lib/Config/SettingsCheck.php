<?php

declare(strict_types=1);

namespace Netopia\CsCart\Config;

use Netopia\CsCart\Key\KeyInspector;
use Netopia\Payment2\Enum\PaymentMode;

/**
 * The settings screen's "Check settings": what can be verified about the
 * selected mode's settings without taking a payment. Makes NO call to NETOPIA.
 *
 * NETOPIA's merchant API (https://secure.sandbox.netopia-payments.com/spec)
 * documents no read-only way to validate an API key: operation/status is
 * marked "will be available at a future date", and no endpoint of the
 * online card flow documents a 401. So the API key is checked here only for
 * being filled in and for not being the other mode's key; NETOPIA confirms
 * it on the first payment (a sandbox test order).
 *
 *  - api_key        filled in; a warning when it equals the other mode's key
 *  - pos_signature  filled in and in the XXXX-XXXX-XXXX-XXXX-XXXX form
 *  - public_key     readable and not expired (KeyInspector). IPN signatures
 *                   are verified with it, so without it no order is confirmed.
 *
 * Results carry language keys and placeholders; the controller translates.
 */
final class SettingsCheck
{
    private const string POS_SIGNATURE_PATTERN = '/^[A-Z0-9]{4}(-[A-Z0-9]{4}){4}$/i';

    /**
     * @param string $otherModeApiKey the other mode's saved or typed key ('' = none)
     * @return array{ready: bool, checks: list<array{id: string, state: string, lang_key: string, params: array<string, string>}>}
     */
    public static function run(string $apiKey, string $otherModeApiKey, string $posSignature, PaymentMode $mode, string $publicKeyPem, int $now): array
    {
        $checks = [
            self::checkApiKey(trim($apiKey), trim($otherModeApiKey), $mode),
            self::checkPosSignature(trim($posSignature)),
            self::checkPublicKey($publicKeyPem, $now, $mode),
        ];

        $ready = true;
        foreach ($checks as $check) {
            if ($check['state'] === 'bad') {
                $ready = false;
            }
        }

        return ['ready' => $ready, 'checks' => $checks];
    }

    /**
     * @return array{id: string, state: string, lang_key: string, params: array<string, string>}
     */
    private static function checkApiKey(string $apiKey, string $otherModeApiKey, PaymentMode $mode): array
    {
        $modeParam = ['[mode]' => $mode->value];
        if ($apiKey === '') {
            return self::result('api_key', 'bad', 'netopia_test_api_key_missing', $modeParam);
        }
        if ($otherModeApiKey !== '' && hash_equals($otherModeApiKey, $apiKey)) {
            return self::result('api_key', 'warn', 'netopia_test_api_key_same_as_other', $modeParam);
        }

        return self::result('api_key', 'ok', 'netopia_test_api_key_set', $modeParam);
    }

    /**
     * @return array{id: string, state: string, lang_key: string, params: array<string, string>}
     */
    private static function checkPosSignature(string $posSignature): array
    {
        if ($posSignature === '') {
            return self::result('pos_signature', 'bad', 'netopia_test_pos_missing');
        }

        return preg_match(self::POS_SIGNATURE_PATTERN, $posSignature) === 1
            ? self::result('pos_signature', 'ok', 'netopia_test_pos_ok')
            : self::result('pos_signature', 'warn', 'netopia_test_pos_format');
    }

    /**
     * @return array{id: string, state: string, lang_key: string, params: array<string, string>}
     */
    private static function checkPublicKey(string $pem, int $now, PaymentMode $mode): array
    {
        $key = KeyInspector::inspectPublic($pem, $now);
        $modeParam = ['[mode]' => $mode->value];

        return match ($key['state']) {
            KeyInspector::MISSING => self::result('public_key', 'bad', 'netopia_test_public_key_missing', $modeParam),
            KeyInspector::INVALID => self::result('public_key', 'bad', 'netopia_test_public_key_invalid', $modeParam),
            KeyInspector::EXPIRED => self::result('public_key', 'bad', 'netopia_test_public_key_expired', $modeParam + ['[date]' => gmdate('d.m.Y', $key['expires_at'])]),
            default => $key['expires_soon']
                ? self::result('public_key', 'warn', 'netopia_test_public_key_expires_soon', $modeParam + ['[date]' => gmdate('d.m.Y', $key['expires_at'])])
                : self::result('public_key', 'ok', 'netopia_test_public_key_ok', $modeParam),
        };
    }

    /**
     * @param array<string, string> $params
     * @return array{id: string, state: string, lang_key: string, params: array<string, string>}
     */
    private static function result(string $id, string $state, string $langKey, array $params = []): array
    {
        return ['id' => $id, 'state' => $state, 'lang_key' => $langKey, 'params' => $params];
    }
}
