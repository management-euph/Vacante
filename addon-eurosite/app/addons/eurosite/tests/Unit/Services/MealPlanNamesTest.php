<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\MealPlanNames;

/**
 * A Eurosite meal reads as the plan its text names, in the store's language,
 * with the hotel's own word in brackets (eurosite.meal_plan_<plan>).
 */
final class MealPlanNamesTest extends TestCase
{
    /** CS-Cart's __() over the seeded labels: a missing one comes back raw. */
    private static function seeded(): \Closure
    {
        /** @var array<string, array<string, string>> $keys */
        $keys = require dirname(__DIR__, 3) . '/lang_keys.php';

        return static fn (string $key, string $lang): string => $keys[$key][$lang] ?? '_' . $key;
    }

    private static function ro(string $name): string
    {
        return MealPlanNames::label($name, 'ro', self::seeded());
    }

    public function testCasaGeorgiusKraussBbReadsAsBreakfast(): void
    {
        // <Meal Type="2" Code="2036">BB</Meal>, 2 Nov 2026.
        self::assertSame('Mic dejun (BB)', self::ro('BB'));
        self::assertSame('Breakfast (BB)', MealPlanNames::label('BB', 'en', self::seeded()));
    }

    /**
     * Type is Eurosite's service type (2 = meals) on every meal: the
     * specification's own examples carry Type="2" on all of these. The plan
     * comes from the text.
     */
    public function testThePlanComesFromTheTextNotFromType2(): void
    {
        self::assertSame('Mic dejun', self::ro('Mic dejun'), 'the text only repeats the plan');
        self::assertSame('All inclusive', self::ro('All Inclusive'));
        self::assertSame('Demipensiune', self::ro('Demipensiune'));
        self::assertSame('Ultra all inclusive (UAI)', self::ro('UAI'));
    }

    public function testEveryWayAHotelWritesAPlan(): void
    {
        self::assertSame('Demipensiune (HB)', self::ro('HB'));
        self::assertSame('Pensiune completă', self::ro('Pensiune completa'));
        self::assertSame('Pensiune completă (FB)', self::ro('FB'));
        self::assertSame('All inclusive (AI)', self::ro('AI'));
        self::assertSame('Fără masă', self::ro('fara masa'));
        self::assertSame('Fără masă (RO)', self::ro('RO'));
        self::assertSame('Self catering (selfcatering)', self::ro('selfcatering'));
        self::assertSame('Bonuri valorice', self::ro('bonuri valorice'));
    }

    public function testEveryPlanIsSeededInEnglishAndRomanian(): void
    {
        foreach (MealPlanNames::plans() as $plan) {
            foreach (['en', 'ro'] as $lang) {
                self::assertStringNotContainsString('_eurosite.', (self::seeded())(MealPlanNames::langKey($plan), $lang), "{$plan} [{$lang}]");
            }
        }
    }

    public function testTextNamingNoPlanStaysAsItIs(): void
    {
        self::assertSame('Pachet spa', self::ro('Pachet spa'));
        self::assertSame('', self::ro(''));
        self::assertSame('BB', MealPlanNames::label('BB', '', self::seeded()), 'outside the store');
        self::assertSame('BB', MealPlanNames::label('BB', 'ro', static fn (string $key): string => '_' . $key), 'a label not seeded yet');
    }
}
