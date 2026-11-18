<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;

/**
 * The admin product page's read-only "Circuit" tab
 * (design/backend/templates/addons/travel_core/hooks/products/tabs_extra.post.tpl;
 * docs/circuit-page-plan.md step 5): what the circuit's supplier sync holds
 * for the product, laid out as the circuit's page uses it. Nothing here is
 * editable: the supplier's next sync would overwrite it. Every fact keeps its
 * row: one the supplier did not send says so, so a gap in the supplier's
 * data shows here rather than as a missing line.
 */
final class CircuitAdminView
{
    /** Departures listed, at most (the tab says how many more there are). */
    public const int DEPARTURES_SHOWN = 30;

    /**
     * @param list<array{departure_code: string, departure_name: string, departure_date: string, price: float, currency: string, availability: string, seats: int|null}> $departures from today on (CircuitDepartureRepository::forCircuit())
     *
     * @return array{
     *     provider: string,
     *     circuit_id: string,
     *     name: string,
     *     transport: string,
     *     days: int,
     *     nights: int,
     *     route: string,
     *     departure_cities: string,
     *     difficulty: int,
     *     group_min: int,
     *     group_max: int,
     *     age_min: int,
     *     age_max: int,
     *     hotel: string,
     *     hotel_stars: int,
     *     tags: list<string>,
     *     photo_count: int,
     *     has_details: bool,
     *     days_list: list<array{number: int, title: string, text: string, hotel: string, stars: int, meals: string, photos: int, highlighted: bool}>,
     *     included: list<string>,
     *     not_included: list<string>,
     *     excursions: list<array{title: string, price: string}>,
     *     notes: list<array{title: string, shown_in: string}>,
     *     documents: list<array{title: string, url: string}>,
     *     departures: list<array{date: string, city: string, price: string, availability: string, seats: int|null}>,
     *     departures_total: int
     * }
     */
    public function build(CircuitPage $page, ?CircuitDetails $details, array $departures): array
    {
        $days = [];
        foreach ($details->days ?? [] as $i => $day) {
            $days[] = [
                'number' => $i + 1,
                'title' => $day->title,
                'text' => $day->text,
                'hotel' => $day->hotel,
                'stars' => $day->stars,
                'meals' => $day->meals,
                'photos' => count($day->photos),
                'highlighted' => $day->highlighted,
            ];
        }

        // Where the page shows each of the supplier's notes.
        $notes = [];
        if ($details !== null) {
            if ($details->highlights['items'] !== []) {
                $notes[] = ['title' => $details->highlights['title'], 'shown_in' => 'highlights'];
            }
            foreach ([['excursions', $details->excursionNotes], ['prices', $details->priceNotes], ['notes', $details->notes]] as [$section, $list]) {
                foreach ($list as $note) {
                    $notes[] = ['title' => $note['title'], 'shown_in' => $section];
                }
            }
        }

        $listed = [];
        foreach (array_slice($departures, 0, self::DEPARTURES_SHOWN) as $departure) {
            $listed[] = [
                'date' => $departure['departure_date'],
                'city' => $departure['departure_name'] !== '' ? $departure['departure_name'] : $departure['departure_code'],
                'price' => self::money($departure['price'], $departure['currency']),
                'availability' => $departure['availability'],
                'seats' => $departure['seats'],
            ];
        }

        return [
            'provider' => $page->providerName,
            'circuit_id' => $page->circuitId,
            'name' => $page->name,
            'transport' => $page->transport,
            'days' => max(0, $page->durationDays),
            'nights' => max(0, $page->durationNights),
            'route' => implode(' · ', $page->route),
            'departure_cities' => implode(', ', array_values(array_filter(array_column($page->departures, 'name')))),
            'difficulty' => $details->difficulty ?? 0,
            'group_min' => $details->groupMin ?? 0,
            'group_max' => $details->groupMax ?? 0,
            'age_min' => $details->ageMin ?? 0,
            'age_max' => $details->ageMax ?? 0,
            'hotel' => $details->accommodation['hotel'] ?? '',
            'hotel_stars' => $details->accommodation['stars'] ?? 0,
            'tags' => $details->tags ?? [],
            'photo_count' => $details->photoCount ?? 0,
            'has_details' => $details !== null,
            'days_list' => $days,
            'included' => $details->included ?? [],
            'not_included' => $details->notIncluded ?? [],
            'excursions' => array_map(
                static fn (array $excursion): array => ['title' => $excursion['title'], 'price' => $excursion['price'] > 0 ? self::money($excursion['price'], $excursion['currency']) : ''],
                $details->excursions ?? [],
            ),
            'notes' => $notes,
            'documents' => $details->documents ?? [],
            'departures' => $listed,
            'departures_total' => count($departures),
        ];
    }

    /** "1.090,50 EUR": the supplier's price as it reads. */
    private static function money(float $amount, string $currency): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return trim(number_format($amount, $decimals, ',', '.') . ' ' . $currency);
    }
}
