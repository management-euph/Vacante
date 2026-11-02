<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * The meal plan a Eurosite offer shows: the plan in the store's language,
 * then the hotel's own word for it in brackets: "Mic dejun (BB)".
 *
 * An offer's meal is the hotel's own text, <Meal Type="2" Code="2036">BB</Meal>
 * (Casa Georgius Krauss, 2 Nov 2026). Its Type is not the meal plan: it is
 * Eurosite's service type, 2 = meals, the same on every meal (the
 * specification's own examples carry Type="2" on "Mic dejun",
 * "All Inclusive", "Demipensiune" and "UAI"); the 0-6 meal list exists only
 * as a search filter. The Code is the hotel's own meal id. So the plan is
 * read from the text: "BB", "mic dejun", "HB", "demipensiune", "AI", "UAI"…
 *
 * The plan reads by its language variable, eurosite.meal_plan_<plan>
 * ("Mic dejun"); the hotel's text follows when it says more than that
 * ("Mic dejun (BB)"), not when it only repeats it ("Demipensiune"). Text
 * that names no known plan, or a plan with no label yet, stays as it is.
 */
final class MealPlanNames
{
    /** Plan => how a hotel writes it; the first that matches wins (ultra before all inclusive). */
    private const array PLANS = [
        'ultra_all_inclusive' => '/\bultra\b|\buai\b/u',
        'all_inclusive' => '/all[\s-]*incl|\bai\b/u',
        'full_board' => '/full[\s-]*board|pensiune\s+complet|\bfb\b/u',
        'half_board' => '/half[\s-]*board|demi[\s-]*pensiune|\bhb\b/u',
        'breakfast' => '/breakfast|mic\s+dejun|\bbb\b|\bb\s*&\s*b\b/u',
        'self_catering' => '/self[\s-]*catering|\bsc\b/u',
        'meal_vouchers' => '/bonuri|voucher/u',
        'room_only' => '/room\s+only|f[aă]r[aă]\s+mas[aă]|no\s+meals?|doar\s+cazare|\bro\b/u',
    ];

    /** The language variable of a plan: eurosite.meal_plan_breakfast. */
    public static function langKey(string $plan): string
    {
        return 'eurosite.meal_plan_' . $plan;
    }

    /** @return list<string> every plan */
    public static function plans(): array
    {
        return array_keys(self::PLANS);
    }

    /** The plan the hotel's text names; '' when it names none. */
    public static function plan(string $name): string
    {
        $text = mb_strtolower(trim($name));
        foreach (self::PLANS as $plan => $pattern) {
            if ($text !== '' && preg_match($pattern, $text) === 1) {
                return $plan;
            }
        }

        return '';
    }

    /**
     * @param string $name the hotel's own text for the meal ("BB")
     * @param string $lang the page's language ('ro', 'en', …); '' outside the store: the hotel's text
     * @param (\Closure(string, string): string)|null $translate key, language => label (default: CS-Cart's __())
     */
    public static function label(string $name, string $lang, ?\Closure $translate = null): string
    {
        $name = trim($name);
        $plan = self::plan($name);
        if ($lang === '' || $plan === '') {
            return $name;
        }
        $key = self::langKey($plan);
        $label = trim(($translate ?? self::translated(...))($key, $lang));
        if ($label === '' || str_contains($label, $key)) {
            // Not seeded yet: CS-Cart answers "_eurosite.meal_plan_breakfast".
            return $name;
        }

        return self::plain($name) === self::plain($label) ? $label : "{$label} ({$name})";
    }

    private static function translated(string $key, string $lang): string
    {
        if (!function_exists('__')) {
            return '';
        }
        $label = __($key, [], $lang);

        return is_string($label) ? $label : '';
    }

    /** "Pensiune Completă", "pensiune  completa." → "pensiune completa": case, diacritics and punctuation left out. */
    private static function plain(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', is_array($words) ? $words : []);
    }
}
