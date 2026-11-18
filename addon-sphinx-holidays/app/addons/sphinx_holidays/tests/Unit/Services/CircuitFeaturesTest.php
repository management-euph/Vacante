<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\BookableCircuits;
use Tygh\Addons\SphinxHolidays\Services\CircuitFeatures;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * What a Sphinx circuit gives its product's features (Feature Mappings ›
 * Circuits): the stored row read into facts, and the departure cities and
 * months the rates say.
 */
final class CircuitFeaturesTest extends TestCase
{
    public function testPastMonthsAndMonthsAYearAwayAreLeftOut(): void
    {
        $stored = ['2026-10', '2026-11', '2027-05', '2027-06', '2027-10', '2027-11', '2028-01', 'bad'];

        self::assertSame([5, 6, 10, 11], CircuitFeatures::months($stored, '2026-11'), 'from November 2026 to October 2027');
        self::assertSame([], CircuitFeatures::months([], '2026-11'));
    }

    public function testTheStoredCircuitAndItsSavedDeparturesAreReadIntoFacts(): void
    {
        $facts = CircuitFeatures::facts([
            'transport_type' => ' Flight ',
            'duration_nights' => '7',
            'tags_json' => '[{"id":2,"name":"Senior Voyage"},{"id":3,"name":""}]',
        ], ['Malta'], '2026-11', [
            'cities' => [['id' => '202405', 'name' => 'București'], ['id' => '197128', 'name' => 'Cluj-Napoca'], ['id' => '300', 'name' => '']],
            'months' => ['2027-05', '2027-06', '2028-01'],
        ]);

        self::assertSame('flight', $facts->transport);
        self::assertSame(7, $facts->nights);
        self::assertSame(['Malta'], $facts->countries);
        self::assertSame([5, 6], $facts->months, 'the saved departures\' months, a year ahead');
        self::assertSame(['București', 'Cluj-Napoca'], $facts->departureCities, 'their cities, a city with no name left out');
        self::assertSame(['Senior Voyage'], $facts->tags);
    }

    public function testACircuitWithNothingStoredGivesEmptyFacts(): void
    {
        $facts = CircuitFeatures::facts([], [], '2026-11');

        self::assertSame('', $facts->transport);
        self::assertSame(0, $facts->nights);
        self::assertSame([], $facts->months);
        self::assertSame([], $facts->departureCities);
        self::assertSame([], $facts->tags);
    }

    public function testTheRatesSayEachCircuitsMonthsAndDepartureCities(): void
    {
        $pages = [
            ['results' => [
                ['circuit_id' => 5, 'departure_date' => '2026-11-20', 'departures' => [['id' => 1, 'name' => 'București']]],
                ['circuit_id' => 5, 'departure_date' => '2026-11-02', 'departures' => [['id' => 9, 'name' => 'Past']]],
            ], 'meta' => ['last_page' => 1]],
            ['results' => [
                ['circuit_id' => 5, 'departure_date' => '2026-12-04', 'departures' => [['id' => 2, 'name' => 'Cluj-Napoca'], ['id' => 1, 'name' => 'București']]],
                ['circuit_id' => 8, 'departure_date' => '2026-12-11', 'departures' => []],
            ], 'meta' => ['last_page' => 1]],
        ];
        $api = $this->createMock(SphinxApi::class);
        $api->method('getCircuitRates')->willReturnCallback(static function () use (&$pages): array {
            return array_shift($pages) ?? ['results' => [], 'meta' => ['last_page' => 1]];
        });

        $rates = new BookableCircuits($api);
        $bookable = $rates->ids('2026-11-12', [5, 8], true);
        $facts = $rates->facts();

        self::assertSame([5, 8], $bookable);
        self::assertSame(['2026-11', '2026-12'], $facts[5]['months']);
        self::assertSame([1 => 'București', 2 => 'Cluj-Napoca'], $facts[5]['departures'], 'a departure before today does not count');
        self::assertSame(['2026-12'], $facts[8]['months']);
        self::assertSame([], $facts[8]['departures']);
    }

    public function testWhatThePriceIncludesAndDoesNotBecomesFacts(): void
    {
        $facts = CircuitFeatures::facts([
            'details_json' => json_encode([
                'included' => [
                    ['title' => '7 nopti cazare la hotel 3*', 'description' => ''],
                    ['title' => ' Mic dejun si cina ', 'description' => 'x'],
                    ['title' => '7 nopti cazare la hotel 3*', 'description' => ''],
                    ['title' => '', 'description' => 'only a description'],
                ],
                'not_included' => [['title' => 'Excursiile optionale', 'description' => '']],
            ]),
        ], [], '2026-11');

        self::assertSame(['7 nopti cazare la hotel 3*', 'Mic dejun si cina'], $facts->included, 'each once, empty titles left out');
        self::assertSame(['Excursiile optionale'], $facts->notIncluded);
    }

    public function testACircuitWithNoDetailsHasNoIncludedFacts(): void
    {
        foreach ([[], ['details_json' => null], ['details_json' => 'not json']] as $row) {
            $facts = CircuitFeatures::facts($row, [], '2026-11');

            self::assertSame([], $facts->included);
            self::assertSame([], $facts->notIncluded);
        }
    }

    public function testDifficultyGroupSizeAndStarsBecomeFacts(): void
    {
        $facts = CircuitFeatures::facts([
            'details_json' => json_encode([
                'difficulty' => ['level' => 3, 'description' => 'moderat'],
                'group_size' => ['min' => 10, 'max' => 30],
                'accommodation' => ['hotel' => 'Hotel Roma', 'category' => '5*'],
            ]),
            'itinerary_json' => json_encode([
                ['accommodation' => ['category' => '4']],
                ['accommodation' => ['category' => '3*']],
                ['accommodation' => ['category' => '4']],
                ['accommodation' => ['category' => 'lux']],
                [],
            ]),
        ], [], '2026-11');

        self::assertSame(3, $facts->difficulty);
        self::assertSame(30, $facts->groupSize, 'the most people in the group');
        self::assertSame([3, 4, 5], $facts->stars, 'the route and the trip\'s own hotel, each once, lowest first');
    }

    public function testAGroupWithOnlyItsFewestAndNoDifficultyOrStars(): void
    {
        $facts = CircuitFeatures::facts([
            'details_json' => json_encode(['difficulty' => ['level' => 9], 'group_size' => ['min' => 15, 'max' => null]]),
        ], [], '2026-11');

        self::assertSame(0, $facts->difficulty, 'a level outside 1–5 is not one');
        self::assertSame(15, $facts->groupSize, 'the fewest when there is no most');
        self::assertSame([], $facts->stars);
    }
}
