<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Circuit;

/**
 * One day of a circuit's program (board 16.3, "Program pe zile"), as the
 * supplier describes it: what happens, where the night is spent, the meals.
 * The texts are plain (paragraphs split by blank lines), never HTML.
 */
final readonly class CircuitDay
{
    /**
     * @param string $title what the day is ("Sosire în Heraklion"; '' when the supplier says none)
     * @param string $text what happens, plain text
     * @param string $hotel the night's hotel ('' when none: the last day, a night on the road)
     * @param int $stars its stars, 1 to 5 (0 when unknown)
     * @param string $meals the day's meals, as the supplier writes them ('' when unknown)
     */
    public function __construct(
        public string $title,
        public string $text,
        public string $hotel = '',
        public int $stars = 0,
        public string $meals = '',
    ) {
    }
}
