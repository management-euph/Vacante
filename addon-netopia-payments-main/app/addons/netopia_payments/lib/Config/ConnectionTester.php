<?php

declare(strict_types=1);

namespace Netopia\CsCart\Config;

use Closure;
use Netopia\CsCart\Exception\ApiException;
use Netopia\CsCart\Http\ApiPoster;
use Netopia\CsCart\Key\KeyInspector;
use Netopia\Payment2\Enum\PaymentMode;
use Throwable;

/**
 * The settings screen's "Test connection": checks what the admin typed,
 * before it is saved, against the mode they picked.
 *
 * Three checks, each reported on its own:
 *  - api_key        a read-only `operation/status` call for an order that
 *                   does not exist. NETOPIA answers 401/403 when it refuses
 *                   the key; any other answer means the key was accepted.
 *                   Nothing is created or changed at NETOPIA.
 *  - pos_signature  present and in NETOPIA's XXXX-XXXX-XXXX-XXXX-XXXX form.
 *                   The API does not confirm a signature on its own, so this
 *                   is a format check and says so.
 *  - public_key     readable and not expired (KeyInspector). IPN signatures
 *                   are verified with it, so without it no order is confirmed.
 *
 * Results carry language keys and placeholders; the controller translates.
 */
final class ConnectionTester
{
    public const string STATUS_ENDPOINT = 'operation/status';

    private const string POS_SIGNATURE_PATTERN = '/^[A-Z0-9]{4}(-[A-Z0-9]{4}){4}$/i';

    /**
     * @param Closure(string $apiKey, PaymentMode $mode): ApiPoster $apiClientFactory
     */
    public function __construct(
        private readonly Closure $apiClientFactory,
    ) {
    }

    /**
     * @return array{ready: bool, checks: list<array{id: string, state: string, lang_key: string, params: array<string, string>}>}
     */
    public function run(string $apiKey, string $posSignature, PaymentMode $mode, string $publicKeyPem, int $now): array
    {
        $checks = [
            $this->checkApiKey(trim($apiKey), trim($posSignature), $mode),
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
    private function checkApiKey(string $apiKey, string $posSignature, PaymentMode $mode): array
    {
        $modeParam = ['[mode]' => $mode->value];
        if ($apiKey === '') {
            return self::result('api_key', 'bad', 'netopia_test_api_key_missing', $modeParam);
        }

        $body = json_encode([
            'posID' => $posSignature,
            'ntpID' => '',
            'orderID' => 'netopia-connection-test',
        ], JSON_THROW_ON_ERROR);

        try {
            $response = ($this->apiClientFactory)($apiKey, $mode)->post(self::STATUS_ENDPOINT, $body);
        } catch (ApiException) {
            return self::result('api_key', 'bad', 'netopia_test_api_key_missing', $modeParam);
        } catch (Throwable) {
            return self::result('api_key', 'bad', 'netopia_test_unreachable', $modeParam);
        }

        if ($response->code === 0) {
            return self::result('api_key', 'bad', 'netopia_test_unreachable', $modeParam);
        }
        if ($response->code === 401 || $response->code === 403) {
            return self::result('api_key', 'bad', 'netopia_test_api_key_rejected', $modeParam);
        }
        if ($response->code >= 500) {
            return self::result('api_key', 'warn', 'netopia_test_api_error', $modeParam + ['[code]' => (string) $response->code]);
        }

        return self::result('api_key', 'ok', 'netopia_test_api_key_ok', $modeParam);
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
