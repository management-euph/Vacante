<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Cast;

final class CastTest extends TestCase
{
    public function testInt(): void
    {
        self::assertSame(5, Cast::int(5));
        self::assertSame(12, Cast::int(' 12 '));
        self::assertSame(-3, Cast::int('-3'));
        self::assertSame(0, Cast::int('12a'));
        self::assertSame(7, Cast::int('x', 7));
        self::assertSame(2, Cast::int(2.9));
        self::assertSame(0, Cast::int(INF));
        self::assertSame(1, Cast::int(true));
        self::assertSame(0, Cast::int(null));
    }

    public function testString(): void
    {
        self::assertSame('a', Cast::string('a'));
        self::assertSame('5', Cast::string(5));
        self::assertSame('1.5', Cast::string(1.5));
        self::assertSame('x', Cast::string(new class () implements \Stringable {
            public function __toString(): string
            {
                return 'x';
            }
        }));
        self::assertSame('fallback', Cast::string(null, 'fallback'));
        self::assertSame('', Cast::string([]));
    }

    public function testFlag(): void
    {
        foreach ([true, 'Y', 1, '1'] as $yes) {
            self::assertTrue(Cast::flag($yes));
        }
        foreach ([false, 'N', 0, '0', '', null, 'y', 'yes'] as $no) {
            self::assertFalse(Cast::flag($no));
        }
    }

    public function testMapAndRows(): void
    {
        self::assertSame(['0' => 'a', 'k' => 'b'], Cast::map(['a', 'k' => 'b']));
        self::assertSame([], Cast::map('x'));
        self::assertSame([['a' => 1], ['b' => 2]], Cast::rows([5 => ['a' => 1], 'x', 9 => ['b' => 2]]));
        self::assertSame([], Cast::rows(null));
    }

    public function testIdList(): void
    {
        self::assertSame([3, 1, 2], Cast::idList('3, 1,2,3,0,-4,x'));
        self::assertSame([4, 5], Cast::idList([4, '5', 4, null]));
        self::assertSame([9], Cast::idList(9));
        self::assertSame([], Cast::idList('  '));
        self::assertSame([], Cast::idList(false));
    }
}
