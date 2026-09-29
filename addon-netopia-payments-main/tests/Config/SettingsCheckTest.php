<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Config;

use Netopia\CsCart\Config\SettingsCheck;
use Netopia\CsCart\Tests\Support\RsaTestFixtures;
use Netopia\Payment2\Enum\PaymentMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsCheck::class)]
final class SettingsCheckTest extends TestCase
{
    public function testCompleteSettingsAreReady(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();

        $result = SettingsCheck::run('ApiKey_live', 'ApiKey_sandbox', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Live, $public, time());

        self::assertTrue($result['ready']);
        self::assertSame(['ok', 'ok', 'ok'], array_column($result['checks'], 'state'));
        // Filled in, not "accepted": NETOPIA is not asked.
        self::assertSame('netopia_test_api_key_set', $result['checks'][0]['lang_key']);
        self::assertSame(['[mode]' => 'live'], $result['checks'][0]['params']);
    }

    public function testTheSameKeyInBothModesIsFlagged(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();

        $result = SettingsCheck::run('ApiKey_1', 'ApiKey_1', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Live, $public, time());

        self::assertSame('warn', $result['checks'][0]['state']);
        self::assertSame('netopia_test_api_key_same_as_other', $result['checks'][0]['lang_key']);
        self::assertTrue($result['ready'], 'a warning does not block');
    }

    public function testMissingValuesBlock(): void
    {
        $result = SettingsCheck::run('', '', '', PaymentMode::Sandbox, '', time());

        self::assertFalse($result['ready']);
        self::assertSame(
            ['netopia_test_api_key_missing', 'netopia_test_pos_missing', 'netopia_test_public_key_missing'],
            array_column($result['checks'], 'lang_key'),
        );
    }

    public function testPosSignatureFormatIsAWarning(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();

        $result = SettingsCheck::run('k', '', 'not-a-signature', PaymentMode::Sandbox, $public, time());

        self::assertSame('warn', $result['checks'][1]['state']);
        self::assertSame('netopia_test_pos_format', $result['checks'][1]['lang_key']);
    }

    public function testUnreadablePublicKeyBlocks(): void
    {
        $result = SettingsCheck::run('k', '', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Sandbox, 'garbage', time());

        self::assertFalse($result['ready']);
        self::assertSame('netopia_test_public_key_invalid', $result['checks'][2]['lang_key']);
    }
}
