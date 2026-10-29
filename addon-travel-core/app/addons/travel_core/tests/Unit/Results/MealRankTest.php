<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Results;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Results\MealRank;
use Tygh\Addons\TravelCore\Results\RateOffer;

/** A meal plan's rank from the name a supplier sends (Eurosite, Sphinx). */
final class MealRankTest extends TestCase
{
    public function testNamesInEnglishAndRomanianRankLightestFirst(): void
    {
        foreach ([
            'Room Only' => RateOffer::MEAL_ROOM_ONLY,
            'Fără masă' => RateOffer::MEAL_ROOM_ONLY,
            'Doar cazare' => RateOffer::MEAL_ROOM_ONLY,
            'Self Catering' => RateOffer::MEAL_ROOM_ONLY,
            'Bed and Breakfast' => RateOffer::MEAL_BREAKFAST,
            'Mic dejun' => RateOffer::MEAL_BREAKFAST,
            'BB' => RateOffer::MEAL_BREAKFAST,
            'Half Board' => RateOffer::MEAL_HALF_BOARD,
            'Demipensiune' => RateOffer::MEAL_HALF_BOARD,
            'Full Board' => RateOffer::MEAL_FULL_BOARD,
            'Pensiune completă' => RateOffer::MEAL_FULL_BOARD,
            'All Inclusive' => RateOffer::MEAL_ALL_INCLUSIVE,
            'All-Inclusive Light' => RateOffer::MEAL_ALL_INCLUSIVE,
            'Ultra All Inclusive' => RateOffer::MEAL_ULTRA_ALL_INCLUSIVE,
            'UAI' => RateOffer::MEAL_ULTRA_ALL_INCLUSIVE,
            'Cina festivă' => RateOffer::MEAL_OTHER,
            '' => RateOffer::MEAL_OTHER,
        ] as $name => $rank) {
            self::assertSame($rank, MealRank::of($name), $name);
        }
    }
}
