<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Helpers\OrderIdList;

/**
 * The ids a bulk action acts on arrive as the list form's order_ids[] or as
 * a CSV in a URL / $.performPostRequest() form. Whatever is not a positive
 * integer must be dropped, never cast into some other order's id.
 */
#[CoversClass(OrderIdList::class)]
final class OrderIdListTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<int>}>
     */
    public static function inputs(): iterable
    {
        yield 'checkbox array' => [['12', '7', '30'], [12, 7, 30]];
        yield 'csv' => ['12,7,30', [12, 7, 30]];
        yield 'csv with spaces' => [' 12 , 7 ,30 ', [12, 7, 30]];
        yield 'ints' => [[5, 6], [5, 6]];
        yield 'single int' => [9, [9]];
        yield 'array element holding a csv' => [['1,2', '3'], [1, 2, 3]];
        yield 'duplicates keep first order' => ['3,1,3,2,1', [3, 1, 2]];
        yield 'leading zeros' => ['007', [7]];
        yield 'zero dropped' => ['0,4', [4]];
        yield 'negative dropped' => [['-3', '4'], [4]];
        yield 'float dropped' => ['1.5,2', [2]];
        yield 'junk dropped' => ['7abc,abc,,8', [8]];
        yield 'nested array dropped' => [[['1'], '2'], [2]];
        yield 'too long dropped' => ['12345678901,5', [5]];
        yield 'null' => [null, []];
        yield 'empty string' => ['', []];
        yield 'bool' => [true, []];
        yield 'float scalar' => [3.0, []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('inputs')]
    public function testParse(mixed $raw, array $expected): void
    {
        self::assertSame($expected, OrderIdList::parse($raw));
    }

    public function testCsvRoundTrip(): void
    {
        $ids = [5, 3, 11];

        self::assertSame('5,3,11', OrderIdList::toCsv($ids));
        self::assertSame($ids, OrderIdList::parse(OrderIdList::toCsv($ids)));
        self::assertSame('', OrderIdList::toCsv([]));
    }
}
