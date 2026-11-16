<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\Helpers\PlainText;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;

/**
 * What components/circuit_details.tpl draws under a circuit's rooms (board
 * 16.3; docs/circuit-page-plan.md step 3), from what its supplier says
 * (CircuitDetails). Besides the parts below: the trip's high points, its
 * hotel (its text in parts, by the supplier's "# Title" lines), the
 * optional excursions (their price as the supplier writes it), the notes on
 * them, the discounts and supplements (a line each) and the documents.
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
     *     notes_shown: bool,
     *     highlights: array{title: string, items: list<string>},
     *     accommodation: array{hotel: string, stars: int, sections: list<array{title: string, paragraphs: list<string>}>},
     *     excursions: list<array{title: string, paragraphs: list<string>, duration: string, price: string, highlighted: bool}>,
     *     excursion_notes: list<array{title: string, paragraphs: list<string>}>,
     *     price_notes: list<array{title: string, lines: list<string>}>,
     *     documents: list<array{title: string, url: string}>
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
                'photos' => array_slice($day->photos, 0, self::DAY_PHOTOS),
                'highlighted' => $day->highlighted,
            ];
        }
        $stays = $this->stays($days);
        $notes = self::paragraphNotes($details->notes);
        $accommodation = [
            'hotel' => $details->accommodation['hotel'],
            'stars' => $details->accommodation['stars'],
            'sections' => self::sections($details->accommodation['text']),
        ];
        $excursions = array_map(static fn (array $excursion): array => [
            'title' => $excursion['title'],
            'paragraphs' => PlainText::paragraphs($excursion['text']),
            'duration' => $excursion['duration'],
            'price' => $excursion['price'] > 0 ? self::money($excursion['price'], $excursion['currency']) : '',
            'highlighted' => $excursion['highlighted'],
        ], $details->excursions);
        $excursionNotes = self::paragraphNotes($details->excursionNotes);
        $priceNotes = array_map(
            static fn (array $note): array => ['title' => $note['title'], 'lines' => self::lines($note['text'])],
            $details->priceNotes,
        );

        $menu = array_keys(array_filter([
            'program' => $days !== [],
            'include' => $details->included !== [] || $details->notIncluded !== [],
            'stays' => $stays !== [] || $accommodation['hotel'] !== '' || $accommodation['stars'] > 0,
            'excursions' => $excursions !== [] || $excursionNotes !== [],
            'notes' => $notes !== [] || $priceNotes !== [] || $details->documents !== []
                || $details->groupMin > 0 || $details->ageMin > 0 || $details->ageMax > 0,
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
            'highlights' => $details->highlights,
            'accommodation' => $accommodation,
            'excursions' => $excursions,
            'excursion_notes' => $excursionNotes,
            'price_notes' => $priceNotes,
            'documents' => $details->documents,
        ];
    }

    /** Photos shown under a day, at most. */
    private const int DAY_PHOTOS = 3;

    /**
     * @param list<array{title: string, text: string}> $notes
     *
     * @return list<array{title: string, paragraphs: list<string>}>
     */
    private static function paragraphNotes(array $notes): array
    {
        return array_map(
            static fn (array $note): array => ['title' => $note['title'], 'paragraphs' => PlainText::paragraphs($note['text'])],
            $notes,
        );
    }

    /**
     * A text's lines, each without the bullet the supplier typed ("•", "-",
     * "*"), empty ones left out.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[•\-*\x{F0A7}]\s*)+/u', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * A hotel text in parts: a line "# Facilitati camere" starts a part with
     * that title; text before any such line is a part without one.
     *
     * @return list<array{title: string, paragraphs: list<string>}>
     */
    public static function sections(string $text): array
    {
        $titles = [''];
        $lines = [[]];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*#+\s*(.+?)\s*$/u', $line, $m) === 1) {
                $titles[] = $m[1];
                $lines[] = [];
                continue;
            }
            $lines[count($lines) - 1][] = $line;
        }

        $sections = [];
        foreach ($titles as $i => $title) {
            $paragraphs = PlainText::paragraphs(implode("\n", $lines[$i] ?? []));
            if ($title !== '' || $paragraphs !== []) {
                $sections[] = ['title' => $title, 'paragraphs' => $paragraphs];
            }
        }

        return $sections;
    }

    /** "45 EUR" / "1.090,50 EUR": a supplier's price as it reads, its currency as sent. */
    private static function money(float $amount, string $currency): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return trim(number_format($amount, $decimals, ',', '.') . ' ' . $currency);
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
     *     group_form: string,
     *     stars_min: int,
     *     stars_max: int,
     *     difficulty: int,
     *     age_min: int,
     *     age_max: int
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
        $hotelStars = $details->accommodation['stars'] ?? 0;
        $routeStars = $page->hotelStars();
        [$starsMin, $starsMax] = $hotelStars >= 1 && $hotelStars <= 5
            ? [$hotelStars, $hotelStars]
            : [$routeStars['min'] ?? 0, $routeStars['max'] ?? 0];
        $difficulty = $details->difficulty ?? 0;
        $ageMin = max(0, $details->ageMin ?? 0);
        $ageMax = max(0, $details->ageMax ?? 0);
        if ($ageMax > 0 && $ageMax < $ageMin) {
            $ageMax = 0; // an oldest under the youngest says nothing
        }

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
            // The trip's hotel when the supplier names one, else those of the route.
            'stars_min' => $starsMin,
            'stars_max' => $starsMax,
            'difficulty' => $difficulty >= 1 && $difficulty <= 5 ? $difficulty : 0,
            'age_min' => $ageMin,
            'age_max' => $ageMax,
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
