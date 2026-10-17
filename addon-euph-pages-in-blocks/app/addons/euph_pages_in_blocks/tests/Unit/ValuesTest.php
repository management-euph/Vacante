<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The untyped values CS-Cart hands over, narrowed.
 */
final class ValuesTest extends TestCase
{
    public function testInt(): void
    {
        self::assertSame(5, fn_euph_pages_in_blocks_int(5));
        self::assertSame(12, fn_euph_pages_in_blocks_int(' 12 '));
        self::assertSame(-3, fn_euph_pages_in_blocks_int('-3'));
        self::assertSame(0, fn_euph_pages_in_blocks_int('12a'));
        self::assertSame(7, fn_euph_pages_in_blocks_int('x', 7));
        self::assertSame(2, fn_euph_pages_in_blocks_int(2.9));
        self::assertSame(0, fn_euph_pages_in_blocks_int(INF));
        self::assertSame(1, fn_euph_pages_in_blocks_int(true));
        self::assertSame(0, fn_euph_pages_in_blocks_int(null));
    }

    public function testString(): void
    {
        self::assertSame('a', fn_euph_pages_in_blocks_string('a'));
        self::assertSame('5', fn_euph_pages_in_blocks_string(5));
        self::assertSame('1.5', fn_euph_pages_in_blocks_string(1.5));
        self::assertSame('x', fn_euph_pages_in_blocks_string(new class () implements \Stringable {
            public function __toString(): string
            {
                return 'x';
            }
        }));
        self::assertSame('fallback', fn_euph_pages_in_blocks_string(null, 'fallback'));
        self::assertSame('', fn_euph_pages_in_blocks_string([]));
    }

    public function testFlag(): void
    {
        foreach ([true, 'Y', 1, '1'] as $yes) {
            self::assertTrue(fn_euph_pages_in_blocks_flag($yes));
        }
        foreach ([false, 'N', 0, '0', '', null, 'y', 'yes'] as $no) {
            self::assertFalse(fn_euph_pages_in_blocks_flag($no));
        }
    }

    public function testMap(): void
    {
        self::assertSame(['0' => 'a', 'k' => 'b'], fn_euph_pages_in_blocks_map(['a', 'k' => 'b']));
        self::assertSame([], fn_euph_pages_in_blocks_map('x'));
        self::assertSame([], fn_euph_pages_in_blocks_map(null));
    }

    public function testIds(): void
    {
        self::assertSame([3, 1, 2], fn_euph_pages_in_blocks_ids('3, 1,2,3,0,-4,x'));
        self::assertSame([4, 5], fn_euph_pages_in_blocks_ids([4, '5', 4, null]));
        self::assertSame([9], fn_euph_pages_in_blocks_ids(9));
        self::assertSame([], fn_euph_pages_in_blocks_ids('  '));
        self::assertSame([], fn_euph_pages_in_blocks_ids(false));
    }
}
