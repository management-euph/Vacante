<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * TypeCoerce is the boundary every `mixed` value from CS-Cart crosses before
 * it reaches a DTO or an API field (Registry entries, $_REQUEST, $order_info,
 * db rows). A silent behaviour change here rewrites invoice payloads, so each
 * branch is pinned.
 */
#[CoversClass(TypeCoerce::class)]
final class TypeCoerceTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function stringCases(): array
    {
        return [
            'string passes through'  => ['abc', 'abc'],
            'empty string'           => ['', ''],
            'int'                    => [42, '42'],
            'negative int'           => [-7, '-7'],
            'float'                  => [1.5, '1.5'],
            'true'                   => [true, '1'],
            'false'                  => [false, '0'],
            'null'                   => [null, ''],
            'array'                  => [['a'], ''],
            'object'                 => [new \stdClass(), ''],
        ];
    }

    #[DataProvider('stringCases')]
    public function testToString(mixed $input, string $expected): void
    {
        self::assertSame($expected, TypeCoerce::toString($input));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function intCases(): array
    {
        return [
            'int passes through'   => [42, 42],
            'float truncates'      => [1.9, 1],
            'negative float'       => [-1.9, -1],
            'numeric string'       => ['17', 17],
            'numeric float string' => ['17.8', 17],
            'non-numeric string'   => ['abc', 0],
            'empty string'         => ['', 0],
            'true'                 => [true, 1],
            'false'                => [false, 0],
            'null'                 => [null, 0],
            'array'                => [[1], 0],
        ];
    }

    #[DataProvider('intCases')]
    public function testToInt(mixed $input, int $expected): void
    {
        self::assertSame($expected, TypeCoerce::toInt($input));
    }

    /**
     * @return array<string, array{mixed, float}>
     */
    public static function floatCases(): array
    {
        return [
            'float passes through' => [1.5, 1.5],
            'int widens'           => [3, 3.0],
            'numeric string'       => ['2.25', 2.25],
            'numeric int string'   => ['2', 2.0],
            'non-numeric string'   => ['abc', 0.0],
            // bool is NOT numeric for toFloat — unlike toInt. Pinned on purpose:
            // a 'Y'/'N' setting must never become a money amount.
            'true'                 => [true, 0.0],
            'null'                 => [null, 0.0],
            'array'                => [[1.0], 0.0],
        ];
    }

    #[DataProvider('floatCases')]
    public function testToFloat(mixed $input, float $expected): void
    {
        self::assertSame($expected, TypeCoerce::toFloat($input));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function boolCases(): array
    {
        return [
            'true passes through' => [true, true],
            'false passes through' => [false, false],
            'CS-Cart Y'           => ['Y', true],
            'CS-Cart N'           => ['N', false],
            'string 1'            => ['1', true],
            'string 0'            => ['0', false],
            'lowercase true'      => ['true', true],
            'mixed-case TRUE'     => ['TrUe', true],
            'lowercase y is not Y' => ['y', false],
            'empty string'        => ['', false],
            'non-zero int'        => [5, true],
            'zero int'            => [0, false],
            'negative int'        => [-1, true],
            // floats fall through to the default — 1.0 is not truthy here.
            'float'               => [1.0, false],
            'null'                => [null, false],
            'array'               => [['Y'], false],
        ];
    }

    #[DataProvider('boolCases')]
    public function testToBool(mixed $input, bool $expected): void
    {
        self::assertSame($expected, TypeCoerce::toBool($input));
    }

    /**
     * The cast normalises keys, but PHP re-folds decimal-integer strings back
     * to int keys on insert — so the declared array<string, mixed> shape is
     * what static analysis sees, not what the array literally holds. Pinned so
     * nobody "fixes" the helper by trusting the docblock over the engine.
     */
    public function testToAssocArrayNormalisesKeys(): void
    {
        $out = TypeCoerce::toAssocArray([0 => 'a', 'b' => 'c', 7 => 'd', '08' => 'e']);

        self::assertSame([0 => 'a', 'b' => 'c', 7 => 'd', '08' => 'e'], $out);
        self::assertSame(['a', 'c', 'd', 'e'], array_values($out));
        // '08' is not a canonical decimal integer, so it stays a string key.
        self::assertIsString(array_keys($out)[3]);
    }

    public function testToAssocArrayPreservesValueTypes(): void
    {
        $nested = ['x' => 1];

        self::assertSame(
            ['i' => 1, 'f' => 1.5, 'b' => true, 'n' => null, 'a' => $nested],
            TypeCoerce::toAssocArray(['i' => 1, 'f' => 1.5, 'b' => true, 'n' => null, 'a' => $nested]),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonArrayCases(): array
    {
        return [
            'null'   => [null],
            'string' => ['not an array'],
            'int'    => [1],
            'bool'   => [false],
            'object' => [new \stdClass()],
        ];
    }

    #[DataProvider('nonArrayCases')]
    public function testToAssocArrayRejectsNonArrays(mixed $input): void
    {
        self::assertSame([], TypeCoerce::toAssocArray($input));
    }
}
