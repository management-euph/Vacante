<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\RequestText;

/**
 * What the store writes into a Novoton request goes in plain Latin letters:
 * the request is declared windows-1251, which has no Romanian letters.
 */
#[CoversClass(RequestText::class)]
final class RequestTextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function texts(): iterable
    {
        yield 'Romanian names' => ['Ștefan Țurcanu, Ioana Bălan', 'Stefan Turcanu, Ioana Balan'];
        yield 'capitals and the cedilla forms' => ['ȘTEFAN ŞERBAN ŢUŢUIANU ÎNĂLȚIMEA', 'STEFAN SERBAN TUTUIANU INALTIMEA'];
        yield 'a guest note' => ['Vă rugăm camere alăturate, sosire târzie.', 'Va rugam camere alaturate, sosire tarzie.'];
        yield 'Cyrillic, as Bulgaria writes it' => ['Николай Иванов', 'Nikolay Ivanov'];
        yield 'other Latin letters' => ['Éva Müller-Groß', 'Eva Muller-Gross'];
        yield 'the euro sign' => ['Bacsis 50€', 'Bacsis 50EUR'];
        yield 'what has no Latin spelling goes' => ['Mulțumim 🙏 mult', 'Multumim mult'];
        yield 'plain text as it is' => ['POPESCU ION', 'POPESCU ION'];
        yield 'nothing' => ['', ''];
    }

    #[DataProvider('texts')]
    public function testTextGoesInPlainLatinLetters(string $typed, string $sent): void
    {
        self::assertSame($sent, RequestText::latin($typed));
    }
}
