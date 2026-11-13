<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Circuit;

/**
 * What a provider knows about a circuit that becomes CS-Cart product
 * features (Feature Mappings › Circuits): each field is the provider's own
 * value; which feature it lands in, and as which value, is the admin's
 * mapping (CircuitFeatureConfig).
 */
final readonly class CircuitFeatureFacts
{
    /**
     * @param string $transport the provider's transport code ("flight", "bus"; '' when unknown)
     * @param int $nights the circuit's nights (0 when unknown)
     * @param list<string> $countries the countries visited, by name
     * @param list<int> $months the calendar months (1–12) with a departure still on sale
     * @param list<string> $departureCities the cities it leaves from, by name
     * @param list<string> $tags the provider's tags, by name
     * @param list<string> $included what the price includes, one entry per item
     * @param list<string> $notIncluded what it does not include, one entry per item
     */
    public function __construct(
        public string $transport = '',
        public int $nights = 0,
        public array $countries = [],
        public array $months = [],
        public array $departureCities = [],
        public array $tags = [],
        public array $included = [],
        public array $notIncluded = [],
    ) {
    }
}
