<?php

declare(strict_types=1);

namespace Netopia\CsCart\Key;

use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;

/**
 * Tells the admin what state each key is in, for the settings screen's key
 * cards and the "Check settings".
 *
 * Only NETOPIA's public key is used by the addon: IpnVerifier checks every
 * IPN's signature with it, so a missing, unreadable or expired public key
 * means no order is ever confirmed. The private key field is kept for
 * merchants who store it here, but no code path reads it, so it is
 * reported as optional and never blocks anything.
 */
final class KeyInspector
{
    public const string OK = 'ok';
    public const string MISSING = 'missing';
    public const string INVALID = 'invalid';
    public const string EXPIRED = 'expired';

    /** Days before expiry from which a still-valid certificate is flagged. */
    public const int EXPIRY_WARNING_DAYS = 30;

    public function __construct(
        private readonly KeyStorage $storage,
    ) {
    }

    /**
     * @return array{state: string, expires_at: int, expires_soon: bool}
     */
    public static function inspectPublic(string $pem, int $now): array
    {
        $pem = trim($pem);
        if ($pem === '') {
            return ['state' => self::MISSING, 'expires_at' => 0, 'expires_soon' => false];
        }

        if (@openssl_pkey_get_public($pem) === false) {
            return ['state' => self::INVALID, 'expires_at' => 0, 'expires_soon' => false];
        }

        // A bare "PUBLIC KEY" block has no dates; a certificate does.
        $expiresAt = 0;
        $cert = str_contains($pem, 'CERTIFICATE') ? @openssl_x509_parse($pem) : false;
        if (is_array($cert) && is_numeric($cert['validTo_time_t'] ?? null)) {
            $expiresAt = (int) $cert['validTo_time_t'];
        }

        if ($expiresAt > 0 && $expiresAt < $now) {
            return ['state' => self::EXPIRED, 'expires_at' => $expiresAt, 'expires_soon' => false];
        }

        return [
            'state' => self::OK,
            'expires_at' => $expiresAt,
            'expires_soon' => $expiresAt > 0 && $expiresAt - $now < self::EXPIRY_WARNING_DAYS * 86400,
        ];
    }

    public static function inspectPrivate(string $pem): string
    {
        $pem = trim($pem);
        if ($pem === '') {
            return self::MISSING;
        }

        return @openssl_pkey_get_private($pem) === false ? self::INVALID : self::OK;
    }

    /**
     * Where a key comes from: an uploaded file, the paste box, or nowhere.
     *
     * @param array<string, mixed> $params
     */
    public static function source(array $params, string $slot): string
    {
        if (Arr::string($params, $slot . '_file') !== '') {
            return 'file';
        }

        return trim(Arr::string($params, $slot)) !== '' ? 'pasted' : '';
    }

    /**
     * Everything the key cards show, per mode and key type.
     *
     * `wrong_mode` is the mode the stored file's name is for when that is not
     * the slot's mode (a sandbox.* file in a live slot): '' when it fits.
     * Files stored before uploads were checked (KeyFileName) can still sit in
     * the wrong slot, and the card says so.
     *
     * @param array<string, mixed> $params the saved processor_params
     * @return array<string, array<string, array{slot: string, file: string, source: string, state: string, expires_at: int, expires_soon: bool, required: bool, wrong_mode: string}>>
     */
    public function overview(array $params, int $paymentId, int $now): array
    {
        $overview = [];
        foreach (PaymentMode::cases() as $mode) {
            foreach (['public_key', 'private_key'] as $type) {
                $slot = $mode->value . '_' . $type;
                $pem = $this->storage->load($params, $type, $paymentId, $mode);
                $public = $type === 'public_key';
                $inspected = $public
                    ? self::inspectPublic($pem, $now)
                    : ['state' => self::inspectPrivate($pem), 'expires_at' => 0, 'expires_soon' => false];

                $overview[$mode->value][$type] = [
                    'slot' => $slot,
                    'file' => Arr::string($params, $slot . '_file'),
                    'source' => self::source($params, $slot),
                    'state' => $inspected['state'],
                    'expires_at' => $inspected['expires_at'],
                    'expires_soon' => $inspected['expires_soon'],
                    'required' => $public,
                    'wrong_mode' => KeyFileName::wrongMode(Arr::string($params, $slot . '_file'), $mode)->value ?? '',
                ];
            }
        }

        return $overview;
    }

    /**
     * True when the mode's required key would stop payments.
     *
     * @param array<string, array<string, array{state: string, required: bool}>> $overview
     */
    public static function modeBlocked(array $overview, string $mode): bool
    {
        foreach ($overview[$mode] ?? [] as $key) {
            if ($key['required'] && $key['state'] !== self::OK) {
                return true;
            }
        }

        return false;
    }
}
