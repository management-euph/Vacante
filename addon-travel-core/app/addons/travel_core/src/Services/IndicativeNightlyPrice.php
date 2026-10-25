<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * The lowest of a supplier's stored per-date prices, as a price for one
 * night — what the indicative price sources
 * (Contracts\IndicativePriceSourceInterface) build their figure from.
 */
final class IndicativeNightlyPrice
{
    /**
     * The lowest positive price among the dates from $today on, divided by
     * $nights (a stored stay total → one night); null when there is none.
     *
     * @param array<array-key, mixed> $pricesByDate 'Y-m-d' => price
     * @param string $today 'Y-m-d'
     * @param int $nights the nights each price covers
     */
    public static function lowest(array $pricesByDate, string $today, int $nights = 1): ?float
    {
        $lowest = null;
        foreach ($pricesByDate as $date => $price) {
            if ((string) $date < $today || !is_numeric($price)) {
                continue;
            }
            $value = (float) $price;
            if ($value > 0 && ($lowest === null || $value < $lowest)) {
                $lowest = $value;
            }
        }

        return $lowest === null ? null : $lowest / max(1, $nights);
    }
}
