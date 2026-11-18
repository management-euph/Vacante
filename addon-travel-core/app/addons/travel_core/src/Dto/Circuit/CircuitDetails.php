<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Circuit;

/**
 * What a circuit's page shows under its rooms (board 16.3;
 * docs/circuit-page-plan.md): the program day by day, what the price
 * includes and does not, the hotel, the optional excursions, the trip's
 * high points and the things to know (the group the departure needs, the
 * travellers' age, how demanding it is, the supplier's notes, discounts and
 * documents). The texts are plain, never HTML. Every part a supplier does
 * not give stays empty and its section is left out.
 */
final readonly class CircuitDetails
{
    /**
     * @param list<CircuitDay> $days the program, in order
     * @param list<string> $included what the price includes
     * @param list<string> $notIncluded what it does not
     * @param list<array{title: string, text: string}> $notes the supplier's things to know
     * @param int $groupMin the fewest travellers a departure needs (0 when unknown)
     * @param int $groupMax the most it takes (0 when unknown)
     * @param int $ageMin the youngest traveller it takes (0 when it asks no age)
     * @param int $ageMax the oldest (0 when it asks none)
     * @param array{title: string, items: list<string>} $highlights the trip's high points, under the supplier's heading (items empty: none)
     * @param int $difficulty how demanding, 1 (easy) to 5 (0 when unknown)
     * @param array{hotel: string, stars: int, text: string} $accommodation the hotel the supplier names for the whole trip (hotel '' and stars 0: none)
     * @param list<array{title: string, text: string, duration: string, price: float, currency: string, highlighted: bool}> $excursions the optional excursions, not in the price (price 0: unknown)
     * @param list<array{title: string, text: string}> $excursionNotes the supplier's notes on the excursions
     * @param list<array{title: string, text: string}> $priceNotes the supplier's discounts and supplements
     * @param list<array{title: string, url: string}> $documents files to download, on the supplier's servers
     * @param list<string> $tags the supplier's labels for the trip ("Senior Voyage")
     * @param int $photoCount the trip's own photos (the product's images are made from them)
     */
    public function __construct(
        public array $days = [],
        public array $included = [],
        public array $notIncluded = [],
        public array $notes = [],
        public int $groupMin = 0,
        public int $groupMax = 0,
        public int $ageMin = 0,
        public int $ageMax = 0,
        public array $highlights = ['title' => '', 'items' => []],
        public int $difficulty = 0,
        public array $accommodation = ['hotel' => '', 'stars' => 0, 'text' => ''],
        public array $excursions = [],
        public array $excursionNotes = [],
        public array $priceNotes = [],
        public array $documents = [],
        public array $tags = [],
        public int $photoCount = 0,
    ) {
    }

    /** Whether there is anything to show. */
    public function isEmpty(): bool
    {
        return $this->days === [] && $this->included === [] && $this->notIncluded === [] && $this->notes === []
            && $this->groupMin <= 0 && $this->ageMin <= 0 && $this->ageMax <= 0
            && $this->highlights['items'] === [] && $this->difficulty <= 0
            && $this->accommodation['hotel'] === '' && $this->accommodation['stars'] <= 0
            && $this->excursions === [] && $this->excursionNotes === [] && $this->priceNotes === [] && $this->documents === [];
    }
}
