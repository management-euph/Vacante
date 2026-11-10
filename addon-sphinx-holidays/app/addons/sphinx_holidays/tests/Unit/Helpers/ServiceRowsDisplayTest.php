<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\ServiceRows;

/**
 * A trip's services in the shopper's money: the amount the booking page
 * adds to the total when one is ticked, and its "+196 €" text, as the total
 * itself is shown. A service without a price gets neither.
 */
final class ServiceRowsDisplayTest extends TestCase
{
    public function testEachPriceIsShownAsTheTotalIs(): void
    {
        $rows = ServiceRows::forShopper([
            ['code' => 'S12', 'title' => 'Demipensiune', 'type' => 'optional', 'pricing' => ['selling_price' => 180.0, 'currency' => 'EUR']],
            ['code' => 'S0', 'title' => 'Ghid', 'type' => 'mandatory', 'pricing' => ['currency' => 'EUR']],
        ], static fn (float $price): float => $price * 1.1);

        $shown = ServiceRows::withDisplay(
            $rows,
            static fn (float $api): float => $api * 5.0,
            static fn (float $amount): string => number_format($amount, 0, ',', '.') . ' lei',
        );

        self::assertSame(990.0, $shown[0]['display_amount']);
        self::assertSame('+990 lei', $shown[0]['display_price']);
        self::assertSame(0.0, $shown[1]['display_amount']);
        self::assertSame('', $shown[1]['display_price']);
        self::assertSame('S12', $shown[0]['code'], 'the rest of the row stays');
        self::assertSame(198.0, $shown[0]['price']);
    }
}
