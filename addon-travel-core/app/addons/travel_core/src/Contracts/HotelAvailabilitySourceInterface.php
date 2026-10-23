<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;

/**
 * A supplier that records hotel availability for Travel Core's rules.
 *
 * The rules live in Travel Core, the collection in each supplier
 * (docs/novoton-availability-gaps-plan.md §0.2): a supplier writes what its
 * existing jobs see into ?:travel_hotel_availability, and Travel Core's weekly
 * step classifies every hotel from those records. Each provider add-on
 * registers an implementation with TravelProviderRegistry::setAvailabilitySource().
 */
interface HotelAvailabilitySourceInterface
{
    /** The registry name ('novoton', 'eurosite', 'sphinx'): the provider column of the records. */
    public function provider(): string;

    public function capabilities(): AvailabilityCapabilities;

    /**
     * The cron modes that write availability records.
     *
     * Travel Core's weekly step does not classify while one of them is
     * running, so it never judges a destination half way through its update
     * (plan §4.5, rule 5). Include a `full` mode that runs one of them.
     *
     * @return list<string>
     */
    public function recordingModes(): array;
}
