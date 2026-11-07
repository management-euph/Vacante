<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDay;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Helpers\PlainText;
use Tygh\Addons\TravelCore\ViewModels\CircuitDetailsViewFactory;

/**
 * What a circuit's page shows under its rooms (board 16.3): the section
 * menu, the program day by day, the hotels on the route night by night,
 * what is included and not, the things to know. The page used to show the
 * circuit's description only: none of its program.
 */
#[CoversClass(CircuitDetailsViewFactory::class)]
#[CoversClass(PlainText::class)]
final class CircuitDetailsViewFactoryTest extends TestCase
{
    private static function creta(): CircuitDetails
    {
        return new CircuitDetails(
            [
                new CircuitDay('Sosire în Heraklion', "Zbor din București, transfer la hotel.\n\nSeara liberă pe faleza veche.", 'Hotel Atrion', 4, 'cină'),
                new CircuitDay('Knossos și Heraklion', 'Palatul din Knossos.', 'Hotel Atrion', 4, 'mic dejun'),
                new CircuitDay('Rethymno', 'Orașul vechi venețian.', 'Hotel Fortezza', 4, 'mic dejun'),
                new CircuitDay('Chania', 'Portul venețian.', 'Hotel Kydon', 4, 'mic dejun'),
                new CircuitDay('Întoarcere', 'Transfer la aeroport.', '', 0, 'mic dejun'),
            ],
            ['Zbor dus-întors București – Heraklion', 'Ghid în limba română'],
            ['Intrările la obiective'],
            [['title' => 'Documente', 'text' => "Carte de identitate valabilă.\n\nPașaport pentru copii."]],
            18,
            40,
        );
    }

    public function testTheProgramIsNumberedDayByDayInParagraphs(): void
    {
        $view = (new CircuitDetailsViewFactory())->view(self::creta());

        self::assertSame([1, 2, 3, 4, 5], array_column($view['days'], 'number'));
        self::assertSame(['number' => 1, 'title' => 'Sosire în Heraklion', 'paragraphs' => ['Zbor din București, transfer la hotel.', 'Seara liberă pe faleza veche.'], 'hotel' => 'Hotel Atrion', 'stars' => 4, 'meals' => 'cină'], $view['days'][0]);
    }

    public function testNightsInARowAtTheSameHotelMakeOneStay(): void
    {
        self::assertSame([
            ['from' => 1, 'to' => 2, 'hotel' => 'Hotel Atrion', 'stars' => 4],
            ['from' => 3, 'to' => 3, 'hotel' => 'Hotel Fortezza', 'stars' => 4],
            ['from' => 4, 'to' => 4, 'hotel' => 'Hotel Kydon', 'stars' => 4],
        ], (new CircuitDetailsViewFactory())->view(self::creta())['stays'], 'the last day has no night');
    }

    public function testTheSameHotelLaterOnIsAnotherStay(): void
    {
        $view = (new CircuitDetailsViewFactory())->view(new CircuitDetails([
            new CircuitDay('A', '', 'Hotel Atrion', 4),
            new CircuitDay('B', '', 'Hotel Kydon', 4),
            new CircuitDay('C', '', 'hotel atrion', 4),
            new CircuitDay('D', '', '', 3),
            new CircuitDay('E', '', '', 3),
        ]));

        self::assertSame([[1, 1], [2, 2], [3, 3], [4, 5]], array_map(static fn (array $stay): array => [$stay['from'], $stay['to']], $view['stays']));
    }

    public function testTheMenuNamesOnlyWhatThereIsInTheBoardsOrder(): void
    {
        self::assertSame(['program', 'include', 'stays', 'notes'], (new CircuitDetailsViewFactory())->view(self::creta())['menu']);
        self::assertSame(['include'], (new CircuitDetailsViewFactory())->view(new CircuitDetails([], [], ['Taxele locale']))['menu']);
        self::assertSame(['notes'], (new CircuitDetailsViewFactory())->view(new CircuitDetails(groupMin: 18))['menu'], 'the group a departure needs is a thing to know');
        self::assertSame(['program'], (new CircuitDetailsViewFactory())->view(new CircuitDetails([new CircuitDay('Sosire', 'Transfer.')]))['menu'], 'no hotel, no stays');
    }

    public function testTheThingsToKnow(): void
    {
        $view = (new CircuitDetailsViewFactory())->view(self::creta());

        self::assertSame([18, 40], [$view['group_min'], $view['group_max']]);
        self::assertSame([['title' => 'Documente', 'paragraphs' => ['Carte de identitate valabilă.', 'Pașaport pentru copii.']]], $view['notes']);
        self::assertSame(['Intrările la obiective'], $view['not_included']);
    }

    /** Suppliers send HTML: the page gets plain text (it escapes it), its paragraphs and line breaks kept. */
    public function testASuppliersHtmlBecomesPlainText(): void
    {
        self::assertSame(
            "Ziua începe la Knossos & muzeu.\nDupă-amiaza liberă.\n\nCina la hotel.",
            PlainText::fromHtml("<p>Ziua începe la <b>Knossos</b> &amp; muzeu.<br/>După-amiaza&nbsp; liberă.</p>\r\n<script>alert(1)</script><p>Cina la hotel.</p>"),
        );
        self::assertSame("Unu\n\nDoi", PlainText::fromHtml('<ul><li>Unu</li><li>Doi</li></ul>'));
        self::assertSame('', PlainText::fromHtml('  <p> </p> '));
        self::assertSame(['Unu', "Doi\nTrei"], PlainText::paragraphs("Unu\n\n\nDoi\nTrei\n"));
    }
}
