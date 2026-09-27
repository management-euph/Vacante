<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\HotelDescription;

/**
 * Novoton's hotel_description answer carries the text as HTML elements
 * inside <Description>. (string) $response->Description was whitespace, so
 * every product was created with no description.
 */
final class HotelDescriptionTest extends TestCase
{
    /**
     * The shape of a real answer (hotel 4535, Monaco Hotel, Golem, AL) as the
     * add-on parses it: the feed's own entities escaped once more (&amp;nbsp;).
     */
    private const string REAL = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<hotel_description><Description>
<p style="margin-left:0cm; margin-right:0cm"><span style="color:null"><span style="font-size:14px"><span style="font-family:Calibri,sans-serif"><strong><span style="background-color:null">Monaco Hotel **** </span></strong></span></span></span></p>
<p style="margin-left:0cm; margin-right:0cm">&amp;nbsp;</p>
<p style="margin-left:0cm; margin-right:0cm"><span style="color:null"><strong><span style="background-color:null">Resort:</span></strong><span style="background-color:null"> Mali i Robit, Kavaj&amp;euml; (Golem)</span><br/><strong>Location:</strong> 43 km from Tirana Airport &amp;ldquo;Mother Teresa,&amp;rdquo;</span></p>
</Description></hotel_description>
XML;

    public function testTheOldReadWasEmpty(): void
    {
        self::assertSame('', trim((string) (new \SimpleXMLElement(self::REAL))->Description));
    }

    public function testTheHtmlIsReadCleanedAndKeepsItsStructure(): void
    {
        $html = HotelDescription::fromResponse(new \SimpleXMLElement(self::REAL));

        self::assertSame(
            '<p><strong>Monaco Hotel **** </strong></p>'
            . '<p><strong>Resort:</strong> Mali i Robit, Kavaj&euml; (Golem)<br/><strong>Location:</strong> 43 km from Tirana Airport &ldquo;Mother Teresa,&rdquo;</p>',
            $html,
        );
    }

    public function testAPlainTextDescriptionStillReads(): void
    {
        $x = new \SimpleXMLElement('<hotel_description><Description>Family hotel &lt;near&gt; the beach</Description></hotel_description>');

        self::assertSame('Family hotel &lt;near&gt; the beach', HotelDescription::fromResponse($x));
    }

    public function testNoDescriptionIsEmpty(): void
    {
        self::assertSame('', HotelDescription::fromResponse(new \SimpleXMLElement('<hotel_description/>')));
        self::assertSame('', HotelDescription::fromResponse(new \SimpleXMLElement('<hotel_description><Description/></hotel_description>')));
        self::assertSame('', HotelDescription::fromResponse(null));
        self::assertSame('', HotelDescription::fromResponse(false));
    }

    /** Every creator reads it through the helper, not (string) ->Description. */
    public function testEveryProductCreatorUsesTheHelper(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['src/Cron/Commands/AddProductsCommand.php', 'src/Cron/Commands/OffersUpdateCommand.php', 'src/Helpers/ProductFactory.php'] as $file) {
            $src = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('HotelDescription::fromResponse(', $src, $file);
            self::assertDoesNotMatchRegularExpression('/\(string\)\s*\$\w+->Description/', $src, $file);
        }
    }
}
