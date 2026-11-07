<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Providers\SphinxCircuitProductProvider;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;

/**
 * A product is a Sphinx circuit when a sold row of ?:sphinx_circuits is
 * linked to it: its page then gets the circuit bar (board 16.3), with the
 * departures the circuits sync stored.
 */
#[CoversClass(SphinxCircuitProductProvider::class)]
final class SphinxCircuitProductProviderTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private static array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        self::$queries = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @param array<string, mixed> $row what the query answers */
    private static function answer(array $row): void
    {
        DbStub::$getRow = static function (string $sql, mixed ...$args) use ($row): array {
            self::$queries[] = [$sql, $args];

            return $row;
        };
    }

    public function testALinkedCircuitIsClaimedWithItsDepartures(): void
    {
        self::answer([
            'circuit_id' => '17', 'name' => 'Creta clasică', 'duration_days' => '5', 'duration_nights' => '4',
            'departure_ids' => '[111,222,333]', 'departure_names' => 'Cluj-Napoca, București, Iași',
            'transport_type' => 'flight', 'destination_names' => 'Heraklion, Rethymno, Chania, Heraklion',
        ]);

        $circuit = (new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPXC17');

        self::assertNotNull($circuit);
        self::assertSame('sphinx', $circuit->providerName);
        self::assertSame('17', $circuit->circuitId);
        self::assertSame([5, 4], [$circuit->durationDays, $circuit->durationNights]);
        self::assertSame([
            ['id' => '111', 'name' => 'Cluj-Napoca'],
            ['id' => '222', 'name' => 'București'],
            ['id' => '333', 'name' => 'Iași'],
        ], $circuit->departures);
        self::assertSame('222', $circuit->defaultDepartureId());
        self::assertSame('București', $circuit->defaultDepartureName());
        // Under its title: its transport and route, each place once (board 16.3).
        self::assertSame(CircuitPage::FLIGHT, $circuit->transport);
        self::assertSame(['Heraklion', 'Rethymno', 'Chania'], $circuit->route);
        self::assertStringContainsString("sync_status = 'active'", self::$queries[0][0], 'a circuit no longer sold is not claimed');
        self::assertSame([110], self::$queries[0][1]);
    }

    public function testATransportSphinxDoesNotNameIsNotGuessed(): void
    {
        self::answer(['circuit_id' => '17', 'name' => 'Creta', 'transport_type' => 'BUS', 'destination_names' => '']);
        $bus = (new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPXC17');
        self::answer(['circuit_id' => '17', 'name' => 'Creta', 'transport_type' => 'train']);
        $train = (new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPXC17');

        self::assertSame([CircuitPage::BUS, []], [$bus?->transport, $bus?->route]);
        self::assertSame('', $train?->transport);
    }

    public function testAProductThatIsNoCircuitIsNotClaimed(): void
    {
        self::answer([]);

        self::assertNull((new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPX7'));
        self::assertNull((new SphinxCircuitProductProvider())->resolveCircuit(0, ''), 'no product, no query');
        self::assertCount(1, self::$queries);
    }

    /** "Hoteluri de 4★ pe traseu": the stars of each day's hotel, as the circuits sync keeps the itinerary. */
    public function testTheHotelsStarsComeFromTheItinerary(): void
    {
        self::answer([
            'circuit_id' => '17', 'name' => 'Creta clasică', 'duration_days' => '5', 'duration_nights' => '4',
            'departure_ids' => '[111]', 'departure_names' => 'București',
            'itinerary_json' => '[{"label":"Day 1","accommodation":{"hotel":"Atrion","category":"4"}},{"label":"Day 3","accommodation":{"hotel":"Fortezza","category":"3"}},{"label":"Day 5","accommodation":null}]',
        ]);

        $circuit = (new SphinxCircuitProductProvider())->resolveCircuit(110, 'SPXC17');

        self::assertNotNull($circuit);
        self::assertSame(['min' => 3, 'max' => 4], $circuit->hotelStars());
        self::assertStringContainsString('itinerary_json', self::$queries[0][0]);
    }

    public function testStarsTheItineraryDoesNotSayAreNotGuessed(): void
    {
        self::assertSame([4, 4], SphinxCircuitProductProvider::hotelStars('[{"accommodation":{"category":"4*"}}]'));
        self::assertSame([0, 0], SphinxCircuitProductProvider::hotelStars('[{"accommodation":{"category":"10"}},{"accommodation":{"category":"4.5"}},{"accommodation":{"category":"lux"}}]'));
        self::assertSame([0, 0], SphinxCircuitProductProvider::hotelStars(null));
        self::assertSame([0, 0], SphinxCircuitProductProvider::hotelStars('not json'));
    }

    /** The program and details under the circuit's rooms (board 16.3), from what the circuits sync keeps. */
    public function testThePagesProgramAndDetails(): void
    {
        self::answer([
            'itinerary_json' => (string) json_encode([
                ['label' => 'Day 1', 'title' => 'Sosire în Heraklion', 'description' => '<p>Zbor din București, transfer la hotel.</p>', 'additional_info' => 'Seara liberă.', 'accommodation' => ['hotel' => 'Hotel Atrion', 'category' => '4', 'description' => null], 'meals' => ['description' => 'Cină']],
                ['label' => 'Day 2', 'title' => '', 'description' => '', 'additional_info' => '', 'accommodation' => null, 'meals' => ['description' => null]],
                ['label' => 'Day 5', 'title' => 'Întoarcere', 'description' => 'Transfer la aeroport.', 'accommodation' => ['hotel' => null, 'category' => null], 'meals' => null],
            ]),
            'details_json' => (string) json_encode([
                'included' => [['title' => 'Zbor dus-întors', 'description' => ''], ['title' => '', 'description' => 'Ghid în limba română']],
                'not_included' => [['title' => 'Taxele locale', 'description' => 'Se achită la hotel.']],
                'additional_info' => [['title' => 'Documente', 'description' => '<b>Carte</b> de identitate.']],
                'group_size' => ['min' => 18, 'max' => 40],
                'age' => ['min' => 18],
            ]),
        ]);

        $details = (new SphinxCircuitProductProvider())->circuitDetails('17');

        self::assertNotNull($details);
        self::assertCount(2, $details->days, 'a day that says nothing is left out');
        self::assertSame(['Sosire în Heraklion', "Zbor din București, transfer la hotel.\n\nSeara liberă.", 'Hotel Atrion', 4, 'Cină'], [$details->days[0]->title, $details->days[0]->text, $details->days[0]->hotel, $details->days[0]->stars, $details->days[0]->meals]);
        self::assertSame(['', 0], [$details->days[1]->hotel, $details->days[1]->stars], 'the last day, no night');
        self::assertSame(['Zbor dus-întors', 'Ghid în limba română'], $details->included, 'an item without a title is its description');
        self::assertSame(['Taxele locale'], $details->notIncluded);
        self::assertSame([['title' => 'Documente', 'text' => 'Carte de identitate.']], $details->notes);
        self::assertSame([18, 40, 18, 0], [$details->groupMin, $details->groupMax, $details->ageMin, $details->ageMax]);
        self::assertStringContainsString("WHERE circuit_id = ?i AND sync_status = 'active'", self::$queries[0][0], 'a circuit no longer sold shows nothing');
        self::assertStringStartsWith('SELECT * FROM', self::$queries[0][0], 'details_json is not named: an install not yet migrated keeps its page');
        self::assertSame([17], self::$queries[0][1]);
    }

    public function testACircuitNotSoldOrUnsyncedShowsNothing(): void
    {
        self::answer([]);
        self::assertNull((new SphinxCircuitProductProvider())->circuitDetails('17'));
        self::assertNull((new SphinxCircuitProductProvider())->circuitDetails('x'), 'no id, no query');
        self::assertCount(1, self::$queries);

        self::assertTrue(SphinxCircuitProductProvider::details(null, null)->isEmpty(), 'synced before the details were kept');
    }

    /** A name with ", " in it breaks the pairing: a lone id keeps the whole text, several get none. */
    public function testNamesThatDoNotPairAreNotGuessed(): void
    {
        self::assertSame([['id' => '1', 'name' => 'Cluj, Napoca']], SphinxCircuitProductProvider::departures('[1]', 'Cluj, Napoca'));
        self::assertSame(
            [['id' => '1', 'name' => ''], ['id' => '2', 'name' => '']],
            SphinxCircuitProductProvider::departures('[1,2]', 'Cluj, Napoca, București'),
        );
        self::assertSame([], SphinxCircuitProductProvider::departures(null, ''));
    }
}
