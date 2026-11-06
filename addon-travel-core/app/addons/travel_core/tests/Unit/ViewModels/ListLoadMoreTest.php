<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\ViewModels\ListLoadMore;

/** "Load N more": N = what is left, at most the page size CS-Cart is set to. */
#[CoversClass(ListLoadMore::class)]
final class ListLoadMoreTest extends TestCase
{
    /** @return array<string, array{mixed, array<string, int>|null}> */
    public static function cases(): array
    {
        return [
            '29 hotels, 20 a page' => [['page' => 1, 'items_per_page' => 20, 'total_items' => 29], ['from' => 1, 'to' => 20, 'total' => 29, 'next_page' => 2, 'count' => 9]],
            '128 hotels, 20 a page' => [['page' => 1, 'items_per_page' => '20', 'total_items' => '128'], ['from' => 1, 'to' => 20, 'total' => 128, 'next_page' => 2, 'count' => 20]],
            'page 6 of 128' => [['page' => 6, 'items_per_page' => 20, 'total_items' => 128], ['from' => 101, 'to' => 120, 'total' => 128, 'next_page' => 7, 'count' => 8]],
            'the last page' => [['page' => 2, 'items_per_page' => 20, 'total_items' => 29], null],
            'one page only' => [['page' => 1, 'items_per_page' => 20, 'total_items' => 20], null],
            'no paging' => [['page' => 1, 'items_per_page' => 0, 'total_items' => 40], null],
            'no search' => [null, null],
            'page 0 reads as 1' => [['page' => 0, 'items_per_page' => 10, 'total_items' => 15], ['from' => 1, 'to' => 10, 'total' => 15, 'next_page' => 2, 'count' => 5]],
        ];
    }

    /** @param array<string, int>|null $expected */
    #[DataProvider('cases')]
    public function testLoadMore(mixed $search, ?array $expected): void
    {
        self::assertSame($expected, ListLoadMore::from($search));
    }
}
