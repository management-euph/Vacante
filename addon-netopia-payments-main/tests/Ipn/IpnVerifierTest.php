<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Ipn;

use Netopia\CsCart\Ipn\IpnVerifier;
use Netopia\CsCart\Tests\Support\RsaTestFixtures;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpnVerifier::class)]
final class IpnVerifierTest extends TestCase
{
    private const string POS_SIGNATURE = 'TEST-POS-SIGNATURE';

    private string $publicKeyPem;

    private string $privateKeyPem;

    #[Override]
    protected function setUp(): void
    {
        [$publicKey, $privateKey] = RsaTestFixtures::generateKeyPair();
        $this->publicKeyPem = $publicKey;
        $this->privateKeyPem = $privateKey;
    }

    public function testValidTokenVerifies(): void
    {
        $body = (string) json_encode(['order' => ['orderID' => 42]]);
        $token = $this->signJwt($body, self::POS_SIGNATURE, 'RS512');

        $result = (new IpnVerifier())->verify($this->publicKeyPem, self::POS_SIGNATURE, $body, $token);

        self::assertTrue($result['verified']);
        self::assertIsArray($result['payload']);
        self::assertSame('', $result['error']);
    }

    public function testMissingTokenIsRejected(): void
    {
        $body = (string) json_encode(['order' => ['orderID' => 42]]);

        $result = (new IpnVerifier())->verify($this->publicKeyPem, self::POS_SIGNATURE, $body, null);

        self::assertFalse($result['verified']);
        self::assertStringContainsString('Missing Verification-Token', $result['error']);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $body = (string) json_encode(['order' => ['orderID' => 42]]);
        $token = $this->signJwt($body, self::POS_SIGNATURE, 'RS512');

        $tampered = (string) json_encode(['order' => ['orderID' => 99]]);

        $result = (new IpnVerifier())->verify($this->publicKeyPem, self::POS_SIGNATURE, $tampered, $token);

        self::assertFalse($result['verified']);
        self::assertStringContainsString('integrity', $result['error']);
    }

    public function testAudienceMismatchIsRejected(): void
    {
        $body = (string) json_encode(['order' => ['orderID' => 42]]);
        $token = $this->signJwt($body, 'WRONG-POS', 'RS512');

        $result = (new IpnVerifier())->verify($this->publicKeyPem, self::POS_SIGNATURE, $body, $token);

        self::assertFalse($result['verified']);
        self::assertStringContainsString('audience', $result['error']);
    }

    public function testDisallowedAlgorithmIsRejected(): void
    {
        $body = (string) json_encode(['order' => ['orderID' => 42]]);
        // 'none' must never verify even if the rest of the token looks plausible.
        $header = RsaTestFixtures::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = RsaTestFixtures::base64UrlEncode((string) json_encode([
            'iss' => 'NETOPIA Payments',
            'aud' => self::POS_SIGNATURE,
            'sub' => base64_encode(hash('sha512', $body, true)),
        ]));
        $unsignedToken = $header . '.' . $payload . '.';

        $result = (new IpnVerifier())->verify($this->publicKeyPem, self::POS_SIGNATURE, $body, $unsignedToken);

        self::assertFalse($result['verified']);
    }

    public function testOversizedTokenIsRejected(): void
    {
        $body = (string) json_encode(['order' => ['orderID' => 42]]);
        $oversized = str_repeat('a', IpnVerifier::MAX_JWT_TOKEN_SIZE + 1);

        $result = (new IpnVerifier())->verify($this->publicKeyPem, self::POS_SIGNATURE, $body, $oversized);

        self::assertFalse($result['verified']);
        self::assertStringContainsString('maximum', $result['error']);
    }

    private function signJwt(string $body, string $audience, string $alg): string
    {
        $hashAlg = match ($alg) {
            'RS256' => 'sha256',
            'RS384' => 'sha384',
            default => 'sha512',
        };
        $opensslAlg = match ($alg) {
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            default => OPENSSL_ALGO_SHA512,
        };

        $header = RsaTestFixtures::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $payload = RsaTestFixtures::base64UrlEncode((string) json_encode([
            'iss' => 'NETOPIA Payments',
            'aud' => $audience,
            'sub' => base64_encode(hash($hashAlg, $body, true)),
        ]));

        $key = openssl_pkey_get_private($this->privateKeyPem);
        self::assertNotFalse($key);

        $signature = '';
        openssl_sign($header . '.' . $payload, $signature, $key, $opensslAlg);

        return $header . '.' . $payload . '.' . RsaTestFixtures::base64UrlEncode($signature);
    }
}
