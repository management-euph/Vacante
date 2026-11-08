<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

/**
 * The Destinations card said "1 countries, 1 resorts sold". Its labels carry
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

    public function testOneCountryAndOneResortReadInTheSingular(): void
    {
        self::assertSame('1 country, 1 resort sold.', self::render('novoton_holidays.dash_dest_intro', 'en', ['[countries]' => '1', '[resorts]' => '1']));
        self::assertSame('2 countries, 76 resorts sold.', self::render('novoton_holidays.dash_dest_intro', 'en', ['[countries]' => '2', '[resorts]' => '76']));
        self::assertSame('1 țară, 1 stațiune vândută.', self::render('novoton_holidays.dash_dest_intro', 'ro', ['[countries]' => '1', '[resorts]' => '1']));
        self::assertSame('2 țări, 76 de stațiuni vândute.', self::render('novoton_holidays.dash_dest_intro', 'ro', ['[countries]' => '2', '[resorts]' => '76']));
        self::assertSame('1 resort', self::render('novoton_holidays.dash_dest_n_resorts', 'en', ['[n]' => '1']));
        self::assertSame('5 stațiuni', self::render('novoton_holidays.dash_dest_n_resorts', 'ro', ['[n]' => '5']));
    }
}
