<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Excerpt;

final class ExcerptTest extends TestCase
{
    public function testTheTextOfTheHtmlOnOneLine(): void
    {
        $html = "<h2>Check-in</h2><p>From 14:00 &amp; until&nbsp;22:00.</p>\n<ul><li>Late arrival</li><li>Early departure</li></ul>";

        self::assertSame('Check-in From 14:00 & until 22:00. Late arrival Early departure', Excerpt::text($html));
    }

    public function testScriptsStylesAndFormFieldsAreNotText(): void
    {
        $html = '<style>.x{color:red}</style><p>Visible</p><script>var secret = 1;</script><noscript>Enable JS</noscript>'
            . '<select><option>One</option></select><textarea>typed</textarea><button>Send</button><!-- note -->';

        self::assertSame('Visible', Excerpt::text($html));
    }

    public function testWordsAreKeptApartWhereBlocksEnd(): void
    {
        self::assertSame('One Two Three Four', Excerpt::text('<p>One</p><p>Two</p>Three<br>Four'));
        self::assertSame('Cell A Cell B', Excerpt::text('<table><tr><td>Cell A</td><td>Cell B</td></tr></table>'));
    }

    public function testAShortTextIsKeptWhole(): void
    {
        self::assertSame('Short text.', Excerpt::fromHtml('<p>Short text.</p>', 40));
        self::assertSame('Exactly ten', Excerpt::fromHtml('Exactly ten', 11));
        self::assertSame('Any length', Excerpt::fromHtml('Any length', 0));
    }

    public function testALongTextIsCutAtAWord(): void
    {
        $text = 'Rooms are ready from two in the afternoon, and the reception is open all night long.';

        self::assertSame('Rooms are ready from two in the…', Excerpt::fromHtml($text, 35));
    }

    public function testThePunctuationBeforeTheCutGoes(): void
    {
        self::assertSame('Breakfast is served daily…', Excerpt::fromHtml('Breakfast is served daily, from seven to ten.', 27));
        self::assertSame('Wellness area…', Excerpt::fromHtml('Wellness area – sauna, pool', 18));
        self::assertSame('Wellness area…', Excerpt::fromHtml('Wellness area — sauna and pool', 16));
    }

    public function testOneLongWordIsCutAtTheLength(): void
    {
        self::assertSame('Supercalifr…', Excerpt::fromHtml('Supercalifragilisticexpialidocious', 11));
    }

    public function testRomanianLettersAreCountedAsLetters(): void
    {
        $text = 'Plajă însorită, șezlonguri și umbrele, la doi pași de țărm.';

        self::assertSame('Plajă însorită, șezlonguri și…', Excerpt::fromHtml($text, 30));
        self::assertTrue(mb_check_encoding(Excerpt::fromHtml($text, 7), 'UTF-8'));
    }

    public function testBrokenUtf8DoesNotEmptyTheText(): void
    {
        self::assertSame('Bad ? byte', str_replace("\u{FFFD}", '?', Excerpt::text("Bad \xC3 byte")));
    }
}
