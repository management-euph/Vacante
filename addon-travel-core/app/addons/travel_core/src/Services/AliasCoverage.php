<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * Which providers have NO aliases for a feature type they need.
 *
 * FeatureMapper resolves a provider value only through an alias of THAT
 * provider (api_source); for the strict types a value without one is logged
 * and skipped — so a provider with zero aliases for "stars" silently gives
 * none of its hotels a star rating (Novoton lost its star aliases this way,
 * to the old alias key). The dashboard lists these gaps.
 *
 * Only providers that use aliases at all are checked (a provider with no
 * alias of any type does not map through this table), and only the types
 * every alias-using provider seeds.
 */
final class AliasCoverage
{
    /** Feature types every alias-using provider maps through aliases. */
    public const array CHECKED_TYPES = ['board', 'room_type', 'stars', 'property_type'];

    /**
     * @param array<string, array<string, int>> $counts api_source => feature_type => alias count
     * @param list<string> $types
     * @return list<array{provider: string, feature_type: string}>
     */
    public static function gaps(array $counts, array $types = self::CHECKED_TYPES): array
    {
        $gaps = [];
        ksort($counts);
        foreach ($counts as $provider => $byType) {
            if ($provider === '' || array_sum($byType) === 0) {
                continue;
            }
            foreach ($types as $type) {
                if (($byType[$type] ?? 0) === 0) {
                    $gaps[] = ['provider' => $provider, 'feature_type' => $type];
                }
            }
        }

        return $gaps;
    }
}
