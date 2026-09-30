<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Key;

use Netopia\CsCart\Key\KeySlots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeySlots::class)]
final class KeySlotsTest extends TestCase
{
    private const string SB_PUBLIC = 'sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer';
    private const string SB_PRIVATE = 'sandbox.39EG-NK6H-N6LV-IVP3-SLJCprivate.key';

    public function testSandboxFilesInTheLiveSlotsMoveToTheSandboxSlots(): void
    {
        $params = [
            'live_public_key_file' => self::SB_PUBLIC,
            'live_public_key' => 'PEM-PUBLIC',
            'live_private_key_file' => self::SB_PRIVATE,
            'live_private_key' => 'PEM-PRIVATE',
        ];

        self::assertSame([
            ['from' => 'live_public_key', 'to' => 'sandbox_public_key', 'file' => self::SB_PUBLIC],
            ['from' => 'live_private_key', 'to' => 'sandbox_private_key', 'file' => self::SB_PRIVATE],
        ], KeySlots::moves($params));

        $moved = KeySlots::relocate($params);
        self::assertSame(self::SB_PUBLIC, $moved['sandbox_public_key_file']);
        self::assertSame('PEM-PUBLIC', $moved['sandbox_public_key']);
        self::assertSame(self::SB_PRIVATE, $moved['sandbox_private_key_file']);
        self::assertSame('PEM-PRIVATE', $moved['sandbox_private_key']);
        self::assertSame('', $moved['live_public_key_file']);
        self::assertSame('', $moved['live_public_key']);
    }

    public function testAnOccupiedSlotIsNeverOverwritten(): void
    {
        $params = [
            'live_public_key_file' => self::SB_PUBLIC,
            'sandbox_public_key_file' => 'sandbox.AAAA-BBBB-CCCC-DDDD-EEEE.public.cer',
        ];

        self::assertSame([], KeySlots::moves($params));
        self::assertSame($params, KeySlots::relocate($params));
    }

    public function testFilesInTheirOwnSlotOrWithoutAModeStay(): void
    {
        $params = [
            'sandbox_public_key_file' => self::SB_PUBLIC,
            'live_public_key_file' => 'netopia.cer',
        ];

        self::assertSame($params, KeySlots::relocate($params));
    }

    public function testRelocationIsIdempotent(): void
    {
        $once = KeySlots::relocate(['live_public_key_file' => self::SB_PUBLIC]);

        self::assertSame($once, KeySlots::relocate($once));
    }
}
