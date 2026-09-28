<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\PayloadBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadBuilder::class)]
final class PayloadBuilderSignatureTest extends TestCase
{
    public function testSignatureRoundTrips(): void
    {
        $signature = PayloadBuilder::signOrderId('42', 'ApiKey_secret');

        self::assertTrue(PayloadBuilder::verifyOrderIdSignature('42', $signature, 'ApiKey_secret'));
    }

    public function testSignatureFailsWithWrongSecret(): void
    {
        $signature = PayloadBuilder::signOrderId('42', 'ApiKey_secret');

        self::assertFalse(PayloadBuilder::verifyOrderIdSignature('42', $signature, 'ApiKey_other'));
    }

    public function testSignatureFailsWithTamperedOrderId(): void
    {
        $signature = PayloadBuilder::signOrderId('42', 'ApiKey_secret');

        self::assertFalse(PayloadBuilder::verifyOrderIdSignature('43', $signature, 'ApiKey_secret'));
    }

    public function testEmptyInputsAreRejected(): void
    {
        self::assertFalse(PayloadBuilder::verifyOrderIdSignature('', 'sig', 'secret'));
        self::assertFalse(PayloadBuilder::verifyOrderIdSignature('42', '', 'secret'));
        self::assertFalse(PayloadBuilder::verifyOrderIdSignature('42', 'sig', ''));
    }

    public function testSignatureIsHexSha256(): void
    {
        $signature = PayloadBuilder::signOrderId('42', 'ApiKey_secret');

        self::assertSame(64, strlen($signature));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }
}
