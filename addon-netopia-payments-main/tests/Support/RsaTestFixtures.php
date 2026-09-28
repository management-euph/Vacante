<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Support;

use RuntimeException;

/**
 * RSA key-pair + JWT base64url helpers for tests that exercise the IPN
 * verification path (which requires real RSA-signed JWTs).
 *
 * Centralising these here means a future change to the JWT spec, the key
 * size, or the encoding only touches one file.
 */
final class RsaTestFixtures
{
    /**
     * Generate a fresh 2048-bit RSA key pair as a PEM-encoded `[public,
     * private]` tuple. The keys are random per call — tests should not
     * cache them across cases unless the case is explicitly about key
     * reuse.
     *
     * @return array{string, string} `[publicPem, privatePem]`
     */
    public static function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            throw new RuntimeException('openssl_pkey_new failed in RsaTestFixtures::generateKeyPair');
        }

        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new RuntimeException('openssl_pkey_get_details failed in RsaTestFixtures::generateKeyPair');
        }

        return [$details['key'], $privatePem];
    }

    /**
     * Base64url-encode (RFC 4648 §5) — the JWT header/payload/signature
     * encoding. Equivalent to base64 with `+/` → `-_` and stripped `=`
     * padding.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
