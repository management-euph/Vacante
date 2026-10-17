<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\PriceComputeCommand;
use Tygh\Addons\NovotonHolidays\Helpers\SeasonDates;

/**
 * The catalog "from" price is the package's cheapest adult price. It took
 * every season's PriceN column, past seasons included, so in July a hotel
 * still advertised May's price. PriceN is season N: with today's date only
 * the seasons not over yet count.
 */
#[CoversClass(PriceComputeCommand::class)]
#[CoversClass(SeasonDates::class)]
final class PriceComputeMinPriceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function priceinfo(): array
    {
        return [
            'seasons' => [
                ['Season' => '1', 'FromDate' => '2027-05-15', 'ToDate' => '2027-06-14'],
                ['Season' => '2', 'FromDate' => '2027-06-15', 'ToDate' => '2027-08-31'],
                ['Season' => '3', 'FromDate' => '2027-09-01', 'ToDate' => '2027-09-30'],
            ],
            'season_price' => [
                ['IdRoom' => 'DBL', 'IdAge' => 'ADULT', 'Price1' => '30', 'Price2' => '55', 'Price3' => '40'],
                ['IdRoom' => 'DBL', 'IdAge' => '3 RD ADULT', 'Price1' => '10', 'Price2' => '10', 'Price3' => '10'],
                ['IdRoom' => 'DBL', 'IdAge' => 'CHD 2-11.99', 'Price1' => '5', 'Price2' => '5', 'Price3' => '5'],
            ],
        ];
    }

    public function testOnlySeasonsNotOverCount(): void
    {
        self::assertSame(30.0, PriceComputeCommand::extractMinPrice(self::priceinfo(), '2027-01-10'), 'before the season: all three');
        self::assertSame(40.0, PriceComputeCommand::extractMinPrice(self::priceinfo(), '2027-07-20'), 'July: May-June is over');
        self::assertSame(40.0, PriceComputeCommand::extractMinPrice(self::priceinfo(), '2027-09-30'), 'a season counts through its last day');
        self::assertNull(PriceComputeCommand::extractMinPrice(self::priceinfo(), '2027-10-01'), 'all over: no price, the product keeps its last one');
    }

    public function testWithoutADateOrSeasonNumbersEveryColumnCounts(): void
    {
        self::assertSame(30.0, PriceComputeCommand::extractMinPrice(self::priceinfo()), 'no date: as before');

        $unnumbered = self::priceinfo();
        $unnumbered['seasons'] = [['FromDate' => '2027-05-15', 'ToDate' => '2027-06-14']];
        self::assertSame(30.0, PriceComputeCommand::extractMinPrice($unnumbered, '2027-07-20'), 'cannot tell which column is current: no filter');
    }

    public function testTheNestedShapeFiltersToo(): void
    {
        $nested = self::priceinfo();
        $nested['seasons'] = ['season' => [
            ['IdSeason' => '1', 'DateFrom' => '2027-05-15', 'DateTo' => '2027-06-14'],
            ['IdSeason' => '2', 'DateFrom' => '2027-06-15', 'DateTo' => '2027-08-31'],
        ]];

        self::assertSame(55.0, PriceComputeCommand::extractMinPrice($nested, '2027-07-20'));
    }

    public function testSeasonsAreCountedWhateverTheirShape(): void
    {
        self::assertSame(3, PriceComputeCommand::countSeasons(self::priceinfo()));
        self::assertSame(1, PriceComputeCommand::countSeasons(['seasons' => ['Season' => '1', 'FromDate' => '2027-05-15', 'ToDate' => '2027-06-14']]), 'one flat season object is one season, not four fields');
        self::assertSame(1, PriceComputeCommand::countSeasons(['seasons' => ['season' => ['IdSeason' => '9', 'DateFrom' => '2027-05-15', 'DateTo' => '2027-06-14']]]));
        self::assertSame(2, PriceComputeCommand::countSeasons(['seasons' => ['season' => [['IdSeason' => '1'], ['IdSeason' => '2']]]]));
        self::assertSame(0, PriceComputeCommand::countSeasons([]));
    }

    public function testSeasonNumbersNotOver(): void
    {
        self::assertSame([2 => true, 3 => true], SeasonDates::numbersNotOver(self::priceinfo(), '2027-07-20'));
        self::assertSame([], SeasonDates::numbersNotOver(self::priceinfo(), '2027-10-01'));
        self::assertNull(SeasonDates::numbersNotOver(['seasons' => [['FromDate' => '2027-05-15', 'ToDate' => '2027-06-14']]], '2027-01-01'));
        self::assertNull(SeasonDates::numbersNotOver([], '2027-01-01'));
    }
}
