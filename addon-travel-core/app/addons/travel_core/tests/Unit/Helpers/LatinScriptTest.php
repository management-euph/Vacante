<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\LatinScript;

/**
 * OSM names Bulgarian streets and towns in Cyrillic; the storefront shows
 * them in Latin letters, the way Bulgaria's own road signs do.
 */
#[CoversClass(LatinScript::class)]
final class LatinScriptTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'resort' => ['Китен', 'Kiten'];
        yield 'ъ is a' => ['Несебър', 'Nesebar'];
        yield 'щ is sht' => ['Щастливеца', 'Shtastlivetsa'];
        yield 'two words' => ['Свети Влас', 'Sveti Vlas'];
        yield 'street with a number' => ['улица Странджа 26', 'ulitsa Strandzha 26'];
        yield 'capitals stay capitals' => ['НЕСЕБЪР', 'NESEBAR'];
        yield 'capital digraph' => ['Чайка', 'Chayka'];
        yield 'Latin text untouched' => ['Strada Mihai Eminescu 12', 'Strada Mihai Eminescu 12'];
        yield 'Romanian diacritics untouched' => ['Regiunea Burgas, Năvodari', 'Regiunea Burgas, Năvodari'];
        yield 'mixed' => ['Hotel Нептун', 'Hotel Neptun'];
    }

    #[DataProvider('names')]
    public function testWritesNamesInLatinLetters(string $name, string $latin): void
    {
        self::assertSame($latin, LatinScript::toLatin($name));
    }

    public function testGreekGoesThroughIcuWhenIntlIsPresent(): void
    {
        if (!class_exists(\Transliterator::class)) {
            self::markTestSkipped('intl is not installed');
        }

        $latin = LatinScript::toLatin('Χαλκιδική');
        self::assertDoesNotMatchRegularExpression('/\p{Greek}/u', $latin);
        self::assertStringStartsWith('Ch', $latin);
    }
}
