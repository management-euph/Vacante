<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDay;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\ViewModels\CircuitAdminView;

/**
 * Step 5 (docs/circuit-page-plan.md): the admin product page's read-only
 * "Circuit" tab shows what the supplier sync holds, and where the page
 * shows each of the supplier's notes.
 */
final class CircuitAdminViewTest extends TestCase
{
    public function testTheTabShowsWhatTheSyncHolds(): void
    {
        $page = new CircuitPage('sphinx', '1', 'Malta', [['id' => '202405', 'name' => 'Bucuresti'], ['id' => '197128', 'name' => '']], 8, 7, 3, 3, CircuitPage::FLIGHT, ['Valletta', 'Gozo']);
        $details = new CircuitDetails(
            days: [new CircuitDay('Sosire', 'Text', 'Hotel Topaz', 3, 'Cina', ['https://x.example/1.jpg', 'https://x.example/2.jpg'], true)],
            included: ['Zbor'],
            notIncluded: ['Taxa de statiune'],
            notes: [['title' => 'Note importante', 'text' => 'x']],
            groupMin: 10,
            groupMax: 25,
            highlights: ['title' => 'Ce te va incanta', 'items' => ['Valletta']],
            difficulty: 1,
            accommodation: ['hotel' => 'Hotel Topaz', 'stars' => 3, 'text' => ''],
            excursions: [['title' => 'Gozo', 'text' => '', 'duration' => '', 'price' => 45.0, 'currency' => 'EUR', 'highlighted' => false]],
            excursionNotes: [['title' => 'Note excursii', 'text' => 'x']],
            priceNotes: [['title' => 'Reduceri', 'text' => 'x']],
            documents: [['title' => 'Program', 'url' => 'https://x.example/p.pdf']],
            tags: ['Senior Voyage'],
            photoCount: 22,
        );
        $departures = [];
        for ($i = 1; $i <= 32; $i++) {
            $departures[] = ['departure_code' => '202405', 'departure_name' => $i === 2 ? '' : 'Bucuresti', 'departure_date' => sprintf('2027-05-%02d', min($i, 28)), 'price' => 1090.5, 'currency' => 'EUR', 'availability' => '', 'seats' => null];
        }

        $view = (new CircuitAdminView())->build($page, $details, $departures);

        self::assertSame(['sphinx', '1', 'flight', 8, 7, 'Valletta · Gozo', 'Bucuresti'], [$view['provider'], $view['circuit_id'], $view['transport'], $view['days'], $view['nights'], $view['route'], $view['departure_cities']]);
        self::assertSame([1, 10, 25, 'Hotel Topaz', 3], [$view['difficulty'], $view['group_min'], $view['group_max'], $view['hotel'], $view['hotel_stars']]);
        self::assertSame([['number' => 1, 'title' => 'Sosire', 'text' => 'Text', 'hotel' => 'Hotel Topaz', 'stars' => 3, 'meals' => 'Cina', 'photos' => 2, 'highlighted' => true]], $view['days_list']);
        self::assertSame([['Senior Voyage'], 22, true], [$view['tags'], $view['photo_count'], $view['has_details']]);
        self::assertSame([
            ['title' => 'Ce te va incanta', 'shown_in' => 'highlights'],
            ['title' => 'Note excursii', 'shown_in' => 'excursions'],
            ['title' => 'Reduceri', 'shown_in' => 'prices'],
            ['title' => 'Note importante', 'shown_in' => 'notes'],
        ], $view['notes']);
        self::assertSame([['title' => 'Gozo', 'price' => '45 EUR']], $view['excursions']);
        self::assertCount(CircuitAdminView::DEPARTURES_SHOWN, $view['departures']);
        self::assertSame(32, $view['departures_total']);
        self::assertSame(['date' => '2027-05-02', 'city' => '202405', 'price' => '1.090,50 EUR', 'availability' => '', 'seats' => null], $view['departures'][1], 'a city without a name shows its code');
    }

    public function testACircuitWithoutDetailsStillHasItsTab(): void
    {
        $view = (new CircuitAdminView())->build(new CircuitPage('sphinx', '2', 'X', [], 0, 0), null, []);

        self::assertSame([[], [], [], 0, 0], [$view['days_list'], $view['notes'], $view['departures'], $view['departures_total'], $view['difficulty']]);
        self::assertSame([[], 0, false], [$view['tags'], $view['photo_count'], $view['has_details']], 'without details the tab says "not sent", not "not required"');
    }
}
