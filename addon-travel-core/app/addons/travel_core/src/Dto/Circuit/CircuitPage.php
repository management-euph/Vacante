<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Circuit;

/**
 * A CS-Cart product that is a circuit (board 16.3): what its page's bar
 * needs to ask the supplier for departures and a quote. A circuit is not a
 * hotel: a route with fixed departures, from one of several cities.
 */
final readonly class CircuitPage
{
    /**
     * @param string $providerName the owning supplier (its add-on's short name: sphinx)
     * @param string $circuitId the supplier's circuit id
     * @param string $name the circuit's name
     * @param list<array{id: string, name: string}> $departures the cities it leaves from, in the supplier's order
     * @param int $durationDays days, the supplier's own (0 when unknown)
     * @param int $durationNights nights, the supplier's own (0 when unknown)
     * @param int $hotelStarsMin the fewest stars of its hotels on the route (0 when unknown)
     * @param int $hotelStarsMax the most stars of its hotels on the route (0 when unknown)
     */
    public function __construct(
        public string $providerName,
        public string $circuitId,
        public string $name,
        public array $departures,
        public int $durationDays,
        public int $durationNights,
        public int $hotelStarsMin = 0,
        public int $hotelStarsMax = 0,
    ) {
    }

    /**
     * The stars of its hotels, for "Hoteluri de 4★ pe traseu" (board 16.3);
     * null when the supplier does not say.
     *
     * @return array{min: int, max: int}|null
     */
    public function hotelStars(): ?array
    {
        return $this->hotelStarsMin > 0 ? ['min' => $this->hotelStarsMin, 'max' => max($this->hotelStarsMin, $this->hotelStarsMax)] : null;
    }

    /**
     * The departure the bar starts on: București when the circuit leaves
     * from there (most of the store's customers do), else the first.
     *
     * @return string its id ('' without departures)
     */
    public function defaultDepartureId(): string
    {
        foreach ($this->departures as $departure) {
            if (preg_match('/bucure|buchar/i', $departure['name']) === 1) {
                return $departure['id'];
            }
        }

        return $this->departures[0]['id'] ?? '';
    }
}
