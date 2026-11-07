<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\Helpers\PlainText;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;

/**
 * What components/circuit_details.tpl draws under a circuit's rooms (board
 * 16.3), from what its supplier says (CircuitDetails):
 *  - the section menu: only the sections there is something for, in the
 *    board's order (Program pe zile, Ce include, Cazare, De știut);
 *  - the program, day by day, numbered from 1, each text in paragraphs;
 *  - the hotels on the route: day N's hotel is night N, and nights in a
 *    row at the same hotel make one line ("Nopțile 1–2");
 *  - what the price includes and does not;
 *  - the things to know: the group a departure needs, the travellers' age,
 *    the supplier's notes; each count with the plural form its sentence
 *    takes ("18 persoane", "20 de persoane": RoomResultsBuilder::form()).
 * The template only lays them out; the texts stay plain (it escapes them).
 */
final class CircuitDetailsViewFactory
{
    /**
     * @return array{
     *     menu: list<string>,
     *     days: list<array{number: int, title: string, paragraphs: list<string>, hotel: string, stars: int, meals: string}>,
     *     stays: list<array{from: int, to: int, hotel: string, stars: int}>,
     *     included: list<string>,
     *     not_included: list<string>,
     *     group_min: int,
     *     group_min_form: string,
     *     group_max: int,
     *     age_min: int,
     *     age_min_form: string,
     *     age_max: int,
     *     age_max_form: string,
     *     notes: list<array{title: string, paragraphs: list<string>}>,
     *     notes_shown: bool
     * }
     */
    public function view(CircuitDetails $details): array
    {
        $days = [];
        foreach ($details->days as $i => $day) {
            $days[] = [
                'number' => $i + 1,
                'title' => $day->title,
                'paragraphs' => PlainText::paragraphs($day->text),
                'hotel' => $day->hotel,
                'stars' => $day->stars,
                'meals' => $day->meals,
            ];
        }
        $stays = $this->stays($days);
        $notes = array_map(
            static fn (array $note): array => ['title' => $note['title'], 'paragraphs' => PlainText::paragraphs($note['text'])],
            $details->notes,
        );

        $menu = array_keys(array_filter([
            'program' => $days !== [],
            'include' => $details->included !== [] || $details->notIncluded !== [],
            'stays' => $stays !== [],
            'notes' => $notes !== [] || $details->groupMin > 0 || $details->ageMin > 0 || $details->ageMax > 0,
        ]));

        return [
            'menu' => $menu,
            'days' => $days,
            'stays' => $stays,
            'included' => $details->included,
            'not_included' => $details->notIncluded,
            'group_min' => $details->groupMin,
            'group_min_form' => RoomResultsBuilder::form($details->groupMin),
            'group_max' => $details->groupMax,
            'age_min' => $details->ageMin,
            'age_min_form' => RoomResultsBuilder::form($details->ageMin),
            'age_max' => $details->ageMax,
            'age_max_form' => RoomResultsBuilder::form($details->ageMax),
            'notes' => $notes,
            'notes_shown' => in_array('notes', $menu, true),
        ];
    }

    /**
     * The facts under a circuit's title (board 16.3): its route ("Heraklion ·
     * Rethymno · Chania"), its length, how it travels and from where (the
     * city the bar starts on), and the group a departure takes. Each count
     * comes with its plural form; what the supplier does not say is 0 or ''.
     *
     * @return array{
     *     route_line: string,
     *     days: int,
     *     days_form: string,
     *     nights: int,
     *     nights_form: string,
     *     transport: string,
     *     from_city: string,
     *     group_min: int,
     *     group_max: int,
     *     group_form: string
     * }
     */
    public function facts(CircuitPage $page, ?CircuitDetails $details): array
    {
        $groupMin = max(0, $details->groupMin ?? 0);
        $groupMax = max(0, $details->groupMax ?? 0);
        if ($groupMax < $groupMin) {
            $groupMax = 0; // a largest group under the smallest says nothing
        }
        $days = max(0, $page->durationDays);
        $nights = max(0, $page->durationNights);

        return [
            'route_line' => implode(' · ', $page->route),
            'days' => $days,
            'days_form' => RoomResultsBuilder::form($days),
            'nights' => $nights,
            'nights_form' => RoomResultsBuilder::form($nights),
            'transport' => $page->transport,
            'from_city' => $page->defaultDepartureName(),
            'group_min' => $groupMin,
            'group_max' => $groupMax,
            // The noun follows the last number: "18–40 de persoane".
            'group_form' => RoomResultsBuilder::form($groupMax > 0 ? $groupMax : $groupMin),
        ];
    }

    /**
     * Night N is spent at day N's hotel; nights in a row at the same hotel
     * (and stars) make one stay.
     *
     * @param list<array{number: int, hotel: string, stars: int}> $days
     *
     * @return list<array{from: int, to: int, hotel: string, stars: int}>
     */
    private function stays(array $days): array
    {
        $stays = [];
        /** @var array{from: int, to: int, hotel: string, stars: int}|null $open the stay the last night belongs to */
        $open = null;
        foreach ($days as $day) {
            $night = $day['hotel'] !== '' || $day['stars'] > 0;
            if ($night && $open !== null
                && mb_strtolower($open['hotel']) === mb_strtolower($day['hotel'])
                && $open['stars'] === $day['stars']
                && $open['to'] === $day['number'] - 1
            ) {
                $open = ['from' => $open['from'], 'to' => $day['number'], 'hotel' => $open['hotel'], 'stars' => $open['stars']];
                continue;
            }
            if ($open !== null) {
                $stays[] = $open;
            }
            $open = $night ? ['from' => $day['number'], 'to' => $day['number'], 'hotel' => $day['hotel'], 'stars' => $day['stars']] : null;
        }
        if ($open !== null) {
            $stays[] = $open;
        }

        return $stays;
    }
}
