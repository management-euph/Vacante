<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

/**
 * A meal plan's rank (RateOffer::MEAL_*) from its name, for a supplier that
 * sends the name, not a code (Eurosite "Mic dejun", Sphinx "Half Board"):
 * the meal filter and a room's options go lightest first. English and
 * Romanian names and the usual short codes; anything else ranks last.
 */
final class MealRank
{
    /** Patterns, most to least specific: "demipensiune" before "pensiune", "ultra" before "all inclusive". */
    private const array PATTERNS = [
        RateOffer::MEAL_ULTRA_ALL_INCLUSIVE => '/\bultra\b|\buai\b/u',
        RateOffer::MEAL_ALL_INCLUSIVE => '/all[\s-]*incl|\bai\b/u',
        RateOffer::MEAL_HALF_BOARD => '/half[\s-]*board|demi[\s-]*pensiune|\bhb\b/u',
        RateOffer::MEAL_FULL_BOARD => '/full[\s-]*board|pensiune\s+complet|\bfb\b/u',
        RateOffer::MEAL_BREAKFAST => '/breakfast|mic\s+dejun|\bbb\b|\bb\s*&\s*b\b/u',
        RateOffer::MEAL_ROOM_ONLY => '/room\s+only|f[aă]r[aă]\s+mas[aă]|self[\s-]*catering|no\s+meals?|doar\s+cazare|\bro\b|\bsc\b/u',
    ];

    public static function of(string $name): int
    {
        $text = mb_strtolower(trim($name));
        foreach (self::PATTERNS as $rank => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $rank;
            }
        }

        return RateOffer::MEAL_OTHER;
    }
}
