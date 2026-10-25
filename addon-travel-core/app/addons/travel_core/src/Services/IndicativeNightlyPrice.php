<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * A supplier's stored per-date prices as prices for one night — what the
 * indicative price sources (Contracts\IndicativePriceSourceInterface) build
 * their figures from.
 */
final class IndicativeNightlyPrice
{
    /**
     * The positive prices from $today on, each divided by $nights (a stored
     * stay total → one night).
     *
     * @param array<array-key, mixed> $pricesByDate 'Y-m-d' => price
     * @param string $today 'Y-m-d'
     * @param int $nights the nights each price covers
     *
     * @return array<string, float>
     */
    public static function perNight(array $pricesByDate, string $today, int $nights = 1): array
    {
        $perNight = [];
        foreach ($pricesByDate as $date => $price) {
            $day = (string) $date;
            if ($day < $today || !is_numeric($price) || (float) $price <= 0) {
                continue;
            }
            $perNight[$day] = (float) $price / max(1, $nights);
        }

        return $perNight;
    }

    /**
     * The lowest of perNight(); null when there is none.
     *
     * @param array<array-key, mixed> $pricesByDate 'Y-m-d' => price
     */
    public static function lowest(array $pricesByDate, string $today, int $nights = 1): ?float
    {
        $perNight = self::perNight($pricesByDate, $today, $nights);

        return $perNight === [] ? null : min($perNight);
    }
}
