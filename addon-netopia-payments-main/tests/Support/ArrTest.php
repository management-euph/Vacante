<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Support;

use Netopia\CsCart\Support\Arr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Arr::class)]
final class ArrTest extends TestCase
{
    public function testStringCoercesScalarsButFallsBackForNonScalars(): void
    {
        self::assertSame('abc', Arr::string(['k' => 'abc'], 'k'));
        self::assertSame('1', Arr::string(['k' => 1], 'k'));
        self::assertSame('default', Arr::string(['k' => ['nested']], 'k', 'default'));
        self::assertSame('default', Arr::string([], 'missing', 'default'));
    }

    public function testIntAndFloatAcceptNumericStrings(): void
    {
        self::assertSame(42, Arr::int(['k' => '42'], 'k'));
        self::assertSame(0, Arr::int(['k' => 'not-numeric'], 'k'));
        self::assertSame(3.14, Arr::float(['k' => '3.14'], 'k'));
    }

    public function testArrayNormalisesKeysToStrings(): void
    {
        $result = Arr::array(['k' => [0 => 'a', 'b' => 'c']], 'k');

        self::assertSame(['0' => 'a', 'b' => 'c'], $result);
    }

    public function testArrayReturnsEmptyForNonArrayValue(): void
    {
        self::assertSame([], Arr::array(['k' => 'not an array'], 'k'));
    }

    public function testStringKeysDropsIntegerKeys(): void
    {
        $result = Arr::stringKeys(['a' => 1, 0 => 'drop', 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testFirstStringWalksFallbackKeys(): void
    {
        $data = ['primary' => '', 'secondary' => 'hit'];

        self::assertSame('hit', Arr::firstString($data, 'primary', 'secondary'));
        self::assertSame('', Arr::firstString($data, 'primary', 'missing'));
    }
}
