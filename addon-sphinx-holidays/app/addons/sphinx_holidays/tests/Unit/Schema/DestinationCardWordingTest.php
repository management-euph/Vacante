<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

/**
 * The Destinations card said "1 countries, 1 cities sold". Its labels carry
 * {one|many} forms (three in Romanian: 1, then 0 and 2–19, then "de"), which
 * DestinationsPicker::t() resolves through Travel Core's plurals().
 */
final class DestinationCardWordingTest extends TestCase
{
    /** @param array<string, string> $params */
    private static function render(string $key, string $lang, array $params): string
    {
        /** @var array<string, array<string, string>> $labels */
        $labels = require dirname(__DIR__, 3) . '/lang_keys.php';

        return DestinationPicker::plurals(strtr($labels[$key][$lang], $params));
    }

    public function testOneCountryAndOneCityReadInTheSingular(): void
    {
        self::assertSame('1 country, 1 city sold.', self::render('sphinx_holidays.dest_card_intro', 'en', ['[countries]' => '1', '[cities]' => '1']));
        self::assertSame('2 countries, 5 cities sold.', self::render('sphinx_holidays.dest_card_intro', 'en', ['[countries]' => '2', '[cities]' => '5']));
        self::assertSame('1 țară, 1 oraș vândut.', self::render('sphinx_holidays.dest_card_intro', 'ro', ['[countries]' => '1', '[cities]' => '1']));
        self::assertSame('2 țări, 21 de orașe vândute.', self::render('sphinx_holidays.dest_card_intro', 'ro', ['[countries]' => '2', '[cities]' => '21']));
    }

    public function testEachCountrysCitiesAndTheWhitelistTilesToo(): void
    {
        self::assertSame('1 city', self::render('sphinx_holidays.dest_card_cities', 'en', ['[n]' => '1']));
        self::assertSame('3 orașe', self::render('sphinx_holidays.dest_card_cities', 'ro', ['[n]' => '3']));
        self::assertSame('in 1 region', self::render('sphinx_holidays.dest_tile_cities_note', 'en', ['[n]' => '1']));
        self::assertSame('1 live product', self::render('sphinx_holidays.dest_tile_hotels_note', 'en', ['[n]' => '1']));
        self::assertSame('20 de produse active', self::render('sphinx_holidays.dest_tile_hotels_note', 'ro', ['[n]' => '20']));
        self::assertSame('1 package route', self::render('sphinx_holidays.dest_tile_circuits_note', 'en', ['[n]' => '1']));
    }
}
