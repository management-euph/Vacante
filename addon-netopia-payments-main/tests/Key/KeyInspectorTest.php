<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Key;

use Netopia\CsCart\Key\KeyInspector;
use Netopia\CsCart\Key\KeyStorage;
use Netopia\CsCart\Tests\Support\RsaTestFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyInspector::class)]
final class KeyInspectorTest extends TestCase
{
    public function testMissingAndUnreadablePublicKeys(): void
    {
        self::assertSame(KeyInspector::MISSING, KeyInspector::inspectPublic('  ', time())['state']);
        self::assertSame(KeyInspector::INVALID, KeyInspector::inspectPublic("-----BEGIN PUBLIC KEY-----\nnope\n-----END PUBLIC KEY-----", time())['state']);
    }

    public function testBarePublicKeyIsOkWithoutAnExpiry(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();

        $result = KeyInspector::inspectPublic($public, time());

        self::assertSame(KeyInspector::OK, $result['state']);
        self::assertSame(0, $result['expires_at']);
        self::assertFalse($result['expires_soon']);
    }

    public function testCertificateExpiryIsReadAndFlagged(): void
    {
        $cert = self::certificate(365);
        $valid = KeyInspector::inspectPublic($cert, time());
        self::assertSame(KeyInspector::OK, $valid['state']);
        self::assertGreaterThan(time(), $valid['expires_at']);
        self::assertFalse($valid['expires_soon']);

        // Seen from 20 days before expiry: still OK, but flagged.
        $soon = KeyInspector::inspectPublic($cert, $valid['expires_at'] - 20 * 86400);
        self::assertSame(KeyInspector::OK, $soon['state']);
        self::assertTrue($soon['expires_soon']);

        $expired = KeyInspector::inspectPublic($cert, $valid['expires_at'] + 60);
        self::assertSame(KeyInspector::EXPIRED, $expired['state']);
    }

    public function testPrivateKeyStates(): void
    {
        [, $private] = RsaTestFixtures::generateKeyPair();

        self::assertSame(KeyInspector::OK, KeyInspector::inspectPrivate($private));
        self::assertSame(KeyInspector::MISSING, KeyInspector::inspectPrivate(''));
        self::assertSame(KeyInspector::INVALID, KeyInspector::inspectPrivate('garbage'));
    }

    public function testOverviewPerModeAndOnlyThePublicKeyBlocks(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();
        $dir = sys_get_temp_dir() . '/netopia_inspect_' . bin2hex(random_bytes(6)) . '/';
        $inspector = new KeyInspector(new KeyStorage($dir));

        $overview = $inspector->overview(['sandbox_public_key' => $public], 5, time());

        self::assertSame('ok', $overview['sandbox']['public_key']['state']);
        self::assertSame('pasted', $overview['sandbox']['public_key']['source']);
        self::assertTrue($overview['sandbox']['public_key']['required']);
        self::assertSame('missing', $overview['sandbox']['private_key']['state']);
        self::assertFalse($overview['sandbox']['private_key']['required']);
        self::assertSame('missing', $overview['live']['public_key']['state']);

        // A missing private key never blocks; a missing public key does.
        self::assertFalse(KeyInspector::modeBlocked($overview, 'sandbox'));
        self::assertTrue(KeyInspector::modeBlocked($overview, 'live'));
    }

    public function testSourcePrefersTheUploadedFile(): void
    {
        self::assertSame('file', KeyInspector::source(['live_public_key_file' => 'a.cer', 'live_public_key' => 'x'], 'live_public_key'));
        self::assertSame('pasted', KeyInspector::source(['live_public_key' => 'x'], 'live_public_key'));
        self::assertSame('', KeyInspector::source([], 'live_public_key'));
    }

    private static function certificate(int $days): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'netopia-test'], $key);
        self::assertNotFalse($csr);
        $x509 = openssl_csr_sign($csr, null, $key, $days);
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $pem);

        return $pem;
    }
}
