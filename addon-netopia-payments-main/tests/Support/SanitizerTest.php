<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Support;

use Netopia\CsCart\Support\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sanitizer::class)]
final class SanitizerTest extends TestCase
{
    public function testThreeDsFieldStripsControlCharacters(): void
    {
        self::assertSame('Chrome 120', Sanitizer::threeDsField("Chrome\x00 120\x1F"));
    }

    public function testThreeDsFieldTruncatesToMaxLength(): void
    {
        $input = str_repeat('a', Sanitizer::MAX_3DS_FIELD_LENGTH + 100);

        self::assertSame(Sanitizer::MAX_3DS_FIELD_LENGTH, strlen(Sanitizer::threeDsField($input)));
    }

    public function testIpAddressAcceptsIpv4(): void
    {
        self::assertSame('192.0.2.1', Sanitizer::ipAddress('192.0.2.1'));
    }

    public function testIpAddressAcceptsIpv6(): void
    {
        self::assertSame('2001:db8::1', Sanitizer::ipAddress('2001:db8::1'));
    }

    public function testIpAddressReturnsEmptyStringForInvalidInput(): void
    {
        // Empty string preserves the fraud-detection signal at NETOPIA's end;
        // substituting a fake valid IP would hide the anomaly.
        self::assertSame('', Sanitizer::ipAddress('not an ip'));
    }

    public function testIpAddressReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', Sanitizer::ipAddress(''));
    }

    #[DataProvider('unsafeUrls')]
    public function testIsSafeHttpsUrlRejectsUnsafeSchemes(string $url): void
    {
        self::assertFalse(Sanitizer::isSafeHttpsUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'http' => ['http://example.com/pay'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data uri' => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'relative' => ['/pay'];
        yield 'missing host' => ['https:///pay'];
    }

    public function testIsSafeHttpsUrlAcceptsHttps(): void
    {
        self::assertTrue(Sanitizer::isSafeHttpsUrl('https://secure.sandbox.netopia-payments.com/pay/1'));
    }
}
