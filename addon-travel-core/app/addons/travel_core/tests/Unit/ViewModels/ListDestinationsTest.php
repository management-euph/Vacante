<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\ViewModels\ListDestinations;

/** Destination chips: the busiest first, eight shown, the rest behind "All destinations". */
#[CoversClass(ListDestinations::class)]
final class ListDestinationsTest extends TestCase
{
    public function testTheBusiestFirstEightShownTheRestBehindAll(): void
    {
        $rows = [];
        foreach (['Bacau' => 2, 'Eforie Nord' => 20, 'Brasov' => 9, 'Mamaia' => 9, 'Poiana Brasov' => 7, 'Predeal' => 5, 'Constanta' => 4, 'Sacele' => 3, 'Sovata' => 3, 'Mangalia' => 2, 'Lepsa' => 0] as $name => $count) {
            $rows[] = ['category_id' => (string) (100 + count($rows)), 'category' => $name, 'product_count' => (string) $count];
        }
        $d = ListDestinations::from($rows);

        self::assertNotNull($d);
        self::assertSame(['Eforie Nord', 'Brasov', 'Mamaia', 'Poiana Brasov', 'Predeal', 'Constanta', 'Sacele', 'Sovata'], array_column($d['visible'], 'name'));
        self::assertSame(['Bacau', 'Mangalia'], array_column($d['more'], 'name'));
        self::assertSame(10, $d['total'], 'an empty destination is left out');
        self::assertSame(['category_id' => 101, 'name' => 'Eforie Nord', 'count' => 20], $d['visible'][0]);
    }

    public function testNothingToShow(): void
    {
        self::assertNull(ListDestinations::from(null));
        self::assertNull(ListDestinations::from([]));
        self::assertNull(ListDestinations::from([['category_id' => 5, 'category' => 'Lepsa', 'product_count' => 0], 'x']));
    }
}
