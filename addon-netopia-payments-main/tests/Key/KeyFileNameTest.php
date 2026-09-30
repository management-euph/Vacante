<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Key;

use Netopia\CsCart\Key\KeyFileName;
use Netopia\Payment2\Enum\PaymentMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyFileName::class)]
final class KeyFileNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function signatures(): iterable
    {
        yield 'public cer' => ['sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer', '39EG-NK6H-N6LV-IVP3-SLJC'];
        yield 'private key glued to "private"' => ['sandbox.39EG-NK6H-N6LV-IVP3-SLJCprivate.key', '39EG-NK6H-N6LV-IVP3-SLJC'];
        yield '2048 txt' => ['sandbox.39EG-NK6H-N6LV-IVP3-SLJC.2048.public.txt', '39EG-NK6H-N6LV-IVP3-SLJC'];
        yield 'lower case name' => ['live.ab12-cd34-ef56-gh78-ij90.public.cer', 'AB12-CD34-EF56-GH78-IJ90'];
        yield 'renamed file' => ['netopia.cer', ''];
    }

    #[DataProvider('signatures')]
    public function testPosSignatureFromName(string $file, string $expected): void
    {
        self::assertSame($expected, KeyFileName::posSignature($file));
    }

    public function testModeAndWrongSlot(): void
    {
        self::assertSame(PaymentMode::Sandbox, KeyFileName::mode('sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer'));
        self::assertSame(PaymentMode::Live, KeyFileName::mode('LIVE.39EG-NK6H-N6LV-IVP3-SLJC.public.cer'));
        self::assertNull(KeyFileName::mode('netopia.cer'));

        // The mistake from the screenshot: sandbox files in the live slots.
        self::assertSame(PaymentMode::Sandbox, KeyFileName::wrongMode('sandbox.39EG-NK6H-N6LV-IVP3-SLJC.2048.public.txt', PaymentMode::Live));
        self::assertNull(KeyFileName::wrongMode('sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer', PaymentMode::Sandbox));
        self::assertNull(KeyFileName::wrongMode('netopia.cer', PaymentMode::Live), 'a name that says nothing is not refused');
    }
}
