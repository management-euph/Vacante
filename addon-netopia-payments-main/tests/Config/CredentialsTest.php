<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Config;

use Netopia\CsCart\Config\Credentials;
use Netopia\Payment2\Enum\PaymentMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Credentials::class)]
final class CredentialsTest extends TestCase
{
    private const array SPLIT = [
        'mode' => 'live',
        'sandbox_api_key' => 'ApiKey_sandbox',
        'sandbox_pos_signature' => 'SAND-BOX1-AAAA-BBBB-CCCC',
        'live_api_key' => 'ApiKey_live',
        'live_pos_signature' => 'LIVE-POS1-AAAA-BBBB-CCCC',
    ];

    public function testEachModeHasItsOwnPair(): void
    {
        self::assertSame(
            ['api_key' => 'ApiKey_sandbox', 'pos_signature' => 'SAND-BOX1-AAAA-BBBB-CCCC'],
            Credentials::forMode(self::SPLIT, PaymentMode::Sandbox),
        );
        self::assertSame(
            ['api_key' => 'ApiKey_live', 'pos_signature' => 'LIVE-POS1-AAAA-BBBB-CCCC'],
            Credentials::forMode(self::SPLIT, PaymentMode::Live),
        );
    }

    public function testSaveCopiesTheSelectedModesPairToWhatTheRuntimeReads(): void
    {
        $live = Credentials::applyActive(self::SPLIT);
        self::assertSame('ApiKey_live', $live['api_key']);
        self::assertSame('LIVE-POS1-AAAA-BBBB-CCCC', $live['pos_signature']);

        // Switching back to Sandbox needs no retyping: the sandbox pair was kept.
        $sandbox = Credentials::applyActive(['mode' => 'sandbox'] + self::SPLIT);
        self::assertSame('ApiKey_sandbox', $sandbox['api_key']);
        self::assertSame('ApiKey_live', $sandbox['live_api_key']);
    }

    public function testAnEmptyModeKeyIsNotFilledFromTheOtherMode(): void
    {
        $params = ['mode' => 'live', 'sandbox_api_key' => 'ApiKey_sandbox', 'live_api_key' => '', 'api_key' => 'ApiKey_sandbox'];

        // Live must never charge with the sandbox key.
        self::assertSame('', Credentials::applyActive($params)['api_key']);
    }

    public function testPreSplitInstallKeepsItsKeyForTheSavedModeOnly(): void
    {
        $legacy = ['mode' => 'sandbox', 'api_key' => 'ApiKey_old', 'pos_signature' => 'OLDP-OS12-AAAA-BBBB-CCCC'];

        self::assertSame('ApiKey_old', Credentials::forMode($legacy, PaymentMode::Sandbox)['api_key']);
        self::assertSame('', Credentials::forMode($legacy, PaymentMode::Live)['api_key']);
        // Nothing to copy until the screen has saved the per-mode fields once.
        self::assertSame($legacy, Credentials::applyActive($legacy));
    }

    public function testEmptyPosSignatureComesFromTheKeyFileName(): void
    {
        $params = [
            'mode' => 'sandbox',
            'sandbox_api_key' => 'ApiKey_sandbox',
            'sandbox_pos_signature' => '',
            'sandbox_public_key_file' => 'sandbox.39eg-nk6h-n6lv-ivp3-sljc.public.cer',
        ];

        self::assertSame('39EG-NK6H-N6LV-IVP3-SLJC', Credentials::forMode($params, PaymentMode::Sandbox)['pos_signature']);
        self::assertSame(Credentials::SOURCE_KEY_FILE, Credentials::posSignatureSource($params, PaymentMode::Sandbox));
        // What checkout and the IPN read.
        self::assertSame('39EG-NK6H-N6LV-IVP3-SLJC', Credentials::applyActive($params)['pos_signature']);
        self::assertSame([], Credentials::missing(Credentials::forMode($params, PaymentMode::Sandbox)));
    }

    public function testTypedPosSignatureWinsAndOtherModeFilesAreIgnored(): void
    {
        $params = [
            'mode' => 'live',
            'live_api_key' => 'ApiKey_live',
            'live_pos_signature' => 'LIVE-POS1-AAAA-BBBB-CCCC',
            'sandbox_public_key_file' => 'sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer',
        ];
        self::assertSame('LIVE-POS1-AAAA-BBBB-CCCC', Credentials::forMode($params, PaymentMode::Live)['pos_signature']);
        self::assertSame(Credentials::SOURCE_TYPED, Credentials::posSignatureSource($params, PaymentMode::Live));

        // A sandbox file sitting in the live slot never gives Live its signature.
        $wrongSlot = ['mode' => 'live', 'live_api_key' => 'k', 'live_public_key_file' => 'sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer'];
        self::assertSame('', Credentials::forMode($wrongSlot, PaymentMode::Live)['pos_signature']);
        self::assertSame(['pos_signature'], Credentials::missing(Credentials::forMode($wrongSlot, PaymentMode::Live)));
    }

    public function testPreSplitInstallGetsTheSignatureFromTheKeyFile(): void
    {
        $legacy = ['mode' => 'sandbox', 'api_key' => 'ApiKey_old', 'pos_signature' => '', 'sandbox_public_key_file' => 'sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer'];

        $resolved = Credentials::applyActive($legacy);
        self::assertSame('ApiKey_old', $resolved['api_key']);
        self::assertSame('39EG-NK6H-N6LV-IVP3-SLJC', $resolved['pos_signature']);
    }
}
