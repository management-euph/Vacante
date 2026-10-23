<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityListing;

/**
 * A supplier dashboard's Hotel availability card
 * (docs/novoton-availability-gaps-plan.md §12, phase 1b): that supplier's
 * hotels per state, as the weekly review left them, and when it ran.
 * components/availability_card.tpl draws it on the Novoton, Eurosite and
 * Sphinx dashboards, and links to the Hotel availability page.
 */
final class AvailabilityCard
{
    /** Each state's pill colour; the pill always says the state's name too, so it reads in greyscale. */
    public const array PILLS = [
        'bookable' => 'ok',
        'between_seasons' => 'info',
        'not_offered' => 'warn',
        'dormant' => 'warn',
        'left_feed' => 'bad',
        'closed' => 'none',
        'no_data' => 'none',
    ];

    /**
     * The card's figures, or null when they cannot be read: a dashboard
     * shows without the card rather than not at all.
     *
     * @return array{provider: string, label: string, mode: string, total: int, counts: list<array{state: string, hotels: int, pill: string}>, reviewed_at: string}|null
     */
    public static function build(string $provider, ?HotelAvailabilityListing $listing = null): ?array
    {
        try {
            $byState = ($listing ?? new HotelAvailabilityListing())->counts()[$provider] ?? [];
            $counts = [];
            foreach (HotelAvailabilityState::cases() as $state) {
                $hotels = $byState[$state->value] ?? 0;
                if ($hotels > 0) {
                    $counts[] = ['state' => $state->value, 'hotels' => $hotels, 'pill' => self::PILLS[$state->value]];
                }
            }
            $summary = AvailabilityReview::lastSummary();

            return [
                'provider' => $provider,
                'label' => TypeCoerce::toString(TravelProviderRegistry::get($provider)['label'] ?? $provider),
                'mode' => TravelCoreConfig::getAvailabilityMode($provider)->value,
                'total' => array_sum(array_column($counts, 'hotels')),
                'counts' => $counts,
                // The last review, when it covered this supplier.
                'reviewed_at' => $summary !== null && isset($summary['providers'][$provider]) ? $summary['at'] : '',
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
