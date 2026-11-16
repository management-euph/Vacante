<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDay;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
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
        self::assertSame(['number' => 1, 'title' => 'Sosire în Heraklion', 'paragraphs' => ['Zbor din București, transfer la hotel.', 'Seara liberă pe faleza veche.'], 'hotel' => 'Hotel Atrion', 'stars' => 4, 'meals' => 'cină', 'photos' => [], 'highlighted' => false], $view['days'][0]);
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

    /** Under the title (board 16.3): the route, "5 zile / 4 nopți", "Avion din București", "Grup 18–40 de persoane". */
    public function testTheFactsUnderTheTitle(): void
    {
        $page = new CircuitPage(
            'sphinx',
            '17',
            'Creta clasică',
            [['id' => '111', 'name' => 'Cluj-Napoca'], ['id' => '222', 'name' => 'București']],
            5,
            4,
            4,
            4,
            CircuitPage::FLIGHT,
            ['Heraklion', 'Rethymno', 'Chania', 'Grecia'],
        );

        self::assertSame([
            'route_line' => 'Heraklion · Rethymno · Chania · Grecia',
            'days' => 5,
            'days_form' => 'few',
            'nights' => 4,
            'nights_form' => 'few',
            'transport' => 'flight',
            'from_city' => 'București',
            'group_min' => 18,
            'group_max' => 40,
            'group_form' => 'many',
            'stars_min' => 4,
            'stars_max' => 4,
            'difficulty' => 0,
            'age_min' => 0,
            'age_max' => 0,
        ], (new CircuitDetailsViewFactory())->facts($page, self::creta()), 'the city the bar starts on; the noun follows the last number');
    }

    public function testFactsTheSupplierDoesNotGiveAreLeftEmpty(): void
    {
        $facts = (new CircuitDetailsViewFactory())->facts(new CircuitPage('sphinx', '17', 'Creta', [], 1, 0), null);

        self::assertSame(['', 1, 'one', 0, '', '', 0, 0], [$facts['route_line'], $facts['days'], $facts['days_form'], $facts['nights'], $facts['transport'], $facts['from_city'], $facts['group_min'], $facts['group_max']]);

        $odd = (new CircuitDetailsViewFactory())->facts(new CircuitPage('sphinx', '17', 'Creta', [], 5, 4), new CircuitDetails(groupMin: 18, groupMax: 10));
        self::assertSame([18, 0, 'few'], [$odd['group_min'], $odd['group_max'], $odd['group_form']], 'a largest group under the smallest says nothing');
    }

    /**
     * Step 3 (docs/circuit-page-plan.md): the trip's own hotel's stars win
     * over the route's; the difficulty and the age asked come under the title.
     */
    public function testTheTripsHotelDifficultyAndAgeAreFacts(): void
    {
        $page = new CircuitPage('sphinx', '1', 'Malta', [], 8, 7, 3, 4);
        $facts = (new CircuitDetailsViewFactory())->facts($page, new CircuitDetails(
            ageMin: 12,
            ageMax: 65,
            difficulty: 2,
            accommodation: ['hotel' => 'Hotel Topaz', 'stars' => 3, 'text' => ''],
        ));

        self::assertSame([3, 3, 2, 12, 65], [$facts['stars_min'], $facts['stars_max'], $facts['difficulty'], $facts['age_min'], $facts['age_max']]);

        $route = (new CircuitDetailsViewFactory())->facts($page, new CircuitDetails(ageMin: 30, ageMax: 18, difficulty: 9));
        self::assertSame([3, 4, 0, 30, 0], [$route['stars_min'], $route['stars_max'], $route['difficulty'], $route['age_min'], $route['age_max']], 'no hotel named: the route\'s stars; an odd level or oldest says nothing');
    }

    /** The hotel, the excursions, the high points, the price notes and the documents: each its section. */
    public function testTheNewSectionsAndTheMenu(): void
    {
        $view = (new CircuitDetailsViewFactory())->view(new CircuitDetails(
            days: [new CircuitDay('Malta', 'Sosire.', '', 0, '', ['https://cdn.example/1.jpg', 'https://cdn.example/2.jpg', 'https://cdn.example/3.jpg', 'https://cdn.example/4.jpg'], true)],
            highlights: ['title' => 'Ce te va incanta', 'items' => ['Valletta', 'Gozo']],
            accommodation: ['hotel' => 'Hotel Topaz', 'stars' => 3, 'text' => "Aproape de plaja.\n# Facilitati camere\nAer conditionat.\n\nSeif.\n# Localizare\nBugibba."],
            excursions: [
                ['title' => 'Insula Gozo', 'text' => 'O zi pe insula.', 'duration' => '8', 'price' => 45.0, 'currency' => 'EUR', 'highlighted' => true],
                ['title' => 'Cele Trei Orase', 'text' => '', 'duration' => '', 'price' => 1090.5, 'currency' => 'EUR', 'highlighted' => false],
                ['title' => 'Fara pret', 'text' => '', 'duration' => '', 'price' => 0.0, 'currency' => '', 'highlighted' => false],
            ],
            priceNotes: [['title' => 'Reduceri si suplimente', 'text' => "Supliment SGL: 150 euro\n• Copil 2-11.99 ani: reducere 100 euro\n\n"]],
            documents: [['title' => 'Program', 'url' => 'https://cdn.example/p.pdf']],
        ));

        self::assertSame(['program', 'stays', 'excursions', 'notes'], $view['menu'], 'the trip\'s hotel alone opens Accommodation; the documents and price notes alone open Good to know');
        self::assertCount(3, $view['days'][0]['photos'], 'three photos at most under a day');
        self::assertTrue($view['days'][0]['highlighted']);
        self::assertSame(['title' => 'Ce te va incanta', 'items' => ['Valletta', 'Gozo']], $view['highlights']);
        self::assertSame([
            ['title' => '', 'paragraphs' => ['Aproape de plaja.']],
            ['title' => 'Facilitati camere', 'paragraphs' => ['Aer conditionat.', 'Seif.']],
            ['title' => 'Localizare', 'paragraphs' => ['Bugibba.']],
        ], $view['accommodation']['sections'], 'the supplier\'s "# Title" lines start the parts');
        self::assertSame(['45 EUR', '1.090,50 EUR', ''], array_column($view['excursions'], 'price'), 'a price as it reads; none when the supplier does not know it');
        self::assertSame([['title' => 'Reduceri si suplimente', 'lines' => ['Supliment SGL: 150 euro', 'Copil 2-11.99 ani: reducere 100 euro']]], $view['price_notes'], 'a line each, the typed bullet left out');
        self::assertSame([['title' => 'Program', 'url' => 'https://cdn.example/p.pdf']], $view['documents']);
        self::assertTrue($view['notes_shown']);
        self::assertFalse((new CircuitDetails(documents: [['title' => 'x', 'url' => 'https://x.example/a.pdf']]))->isEmpty());
    }

    /** Step 4: the price box says only what the supplier's own lists say. */
    public function testThePriceBoxTakesTheSuppliersOwnItems(): void
    {
        $factory = new CircuitDetailsViewFactory();

        self::assertSame([
            ['text' => 'Transport avion Bucuresti – Malta – Bucuresti', 'included' => true],
            ['text' => 'Taxele de aeroport', 'included' => true],
            ['text' => '1 bagaj la cala de maxim 20Kg', 'included' => true],
        ], $factory->priceIncludes(new CircuitDetails(
            included: ['7 nopti cazare la hotel 3*', 'Transport avion Bucuresti – Malta – Bucuresti', 'Taxele de aeroport', 'Transport autocar la destinatie', '1 bagaj la cala de maxim 20Kg'],
            notIncluded: ['Taxa de statiune'],
        )), 'one item per topic, the first the supplier lists');

        self::assertSame([
            ['text' => 'Transport autocar', 'included' => true],
            ['text' => 'Taxa de statiune: 0,5 €/zi', 'included' => false],
        ], $factory->priceIncludes(new CircuitDetails(included: ['Transport autocar', 'Ghid'], notIncluded: ['Asigurare', 'Taxa de statiune: 0,5 €/zi'])), 'taxes not included are said so; a topic neither list names is left out');

        self::assertSame([], $factory->priceIncludes(null));
        self::assertSame([], $factory->priceIncludes(new CircuitDetails(included: ['Ghid'])));
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
