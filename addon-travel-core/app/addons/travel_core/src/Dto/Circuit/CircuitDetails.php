<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Circuit;

/**
 * What a circuit's page shows under its rooms (board 16.3): the program day
 * by day, what the price includes and does not, and the things to know (the
 * group the departure needs, the travellers' age, the supplier's notes).
 * The texts are plain, never HTML.
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
    ) {
    }

    /** Whether there is anything to show. */
    public function isEmpty(): bool
    {
        return $this->days === [] && $this->included === [] && $this->notIncluded === [] && $this->notes === []
            && $this->groupMin <= 0 && $this->ageMin <= 0 && $this->ageMax <= 0;
    }
}
