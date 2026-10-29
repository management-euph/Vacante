<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Design A room-first results component
 * (templates/addons/travel_core/components/design_a_*.tpl, the owner's board
 * 13b; docs/hotel-page-design-a-plan.md), which every supplier's search
 * renders for a Design A hotel page:
 *
 *  - both themes carry the same files (production runs nova_theme);
 *  - every text it prints is seeded in English and Romanian, the plural
 *    forms RoomResultsBuilder::form() picks included;
 *  - it prints the view model's plain text escaped, never raw;
 *  - the hooks pdp-design-a.js drives (filters, radios and tills, sort,
 *    "see all", the ⓘ, terms on demand) are there.
 */
final class DesignAResultsComponentTest extends TestCase
{
    private const DIR = 'templates/addons/travel_core/components/';

    private const FILES = [
        'design_a_results.tpl',
        'design_a_room.tpl',
        'design_a_option.tpl',
        'design_a_till.tpl',
        'design_a_package.tpl',
        'design_a_cancel.tpl',
        'design_a_payment.tpl',
        'design_a_terms.tpl',
        'design_a_icon.tpl',
    ];

    /** Lang keys the templates build from a value. */
    private const DYNAMIC = [
        'travel_core.pdpa_r_rates_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_room_types_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_adults_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_children_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_nights_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_count_meal_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_capacity_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_rooms_left_' => ['one', 'few', 'many'],
        'travel_core.pdpa_r_chip_' => ['free', 'immediate', 'special'],
    ];

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function source(string $theme, string $file): string
    {
        return (string) file_get_contents(self::addonRoot() . '/design/themes/' . $theme . '/' . self::DIR . $file);
    }

    private static function all(): string
    {
        return implode("\n", array_map(static fn (string $f): string => self::source('responsive', $f), self::FILES));
    }

    public function testBothThemesCarryTheSameComponent(): void
    {
        foreach (self::FILES as $file) {
            self::assertSame(self::source('responsive', $file), self::source('nova_theme', $file), $file . ': nova_theme must match (composer mirror)');
            self::assertNotSame('', self::source('responsive', $file), $file);
        }
    }

    public function testEveryTextIsSeededInBothLanguages(): void
    {
        preg_match_all('/__\("(travel_core\.[a-z0-9_]+)(`[^"]*)?"/', self::all(), $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $match) {
            if (($match[2] ?? '') === '') {
                $keys[$match[1]] = true;
                continue;
            }
            self::assertArrayHasKey($match[1], self::DYNAMIC, 'a key built from a value must be listed with its forms: ' . $match[1]);
            foreach (self::DYNAMIC[$match[1]] as $form) {
                $keys[$match[1] . $form] = true;
            }
        }
        self::assertGreaterThan(40, count($keys));

        $root = dirname(__DIR__, 3);
        $langKeys = require $root . '/lang_keys.php';
        self::assertIsArray($langKeys);
        $po = [];
        foreach (['en', 'ro'] as $lang) {
            $po[$lang] = (string) file_get_contents(self::addonRoot() . '/var/langs/' . $lang . '/addons/travel_core.po');
        }
        foreach (array_keys($keys) as $key) {
            $entry = $langKeys[$key] ?? null;
            self::assertIsArray($entry, $key . ' missing from lang_keys.php');
            self::assertNotSame('', $entry['en'] ?? '', $key . ' (en)');
            self::assertNotSame('', $entry['ro'] ?? '', $key . ' (ro)');
            foreach ($po as $lang => $text) {
                self::assertStringContainsString('msgctxt "Languages::' . $key . '"', $text, $key . ' missing from ' . $lang . '.po');
            }
        }
    }

    public function testThePluralFormsReadRight(): void
    {
        $langKeys = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($langKeys);
        $ro = static fn (string $key): string => (string) ($langKeys['travel_core.' . $key]['ro'] ?? '');
        self::assertSame('[n] tarif', $ro('pdpa_r_rates_one'));
        self::assertSame('[n] tarife', $ro('pdpa_r_rates_few'));
        self::assertSame('[n] de tarife', $ro('pdpa_r_rates_many'));
        self::assertSame('[n] tipuri de cameră', $ro('pdpa_r_room_types_few'));
        self::assertSame('[n] nopți', $ro('pdpa_r_nights_few'));
    }

    public function testDataIsPrintedEscaped(): void
    {
        $src = (string) preg_replace('/\{\*.*?\*\}/s', '', self::all());

        self::assertStringNotContainsString('nofilter', $src, 'the view model is plain text');
        // Every name, price or date printed straight from the view model is escaped.
        preg_match_all('/\{\$(?:(?:travel_pdpa_(?:room|option|plan|results|section|chip)|set|deal|cancel|payment)\.[a-z_.]+|travel_pdpa_(?:line|perk|facility)|package|label)(\|[^}]*)?\}/', $src, $m, PREG_SET_ORDER);
        self::assertGreaterThan(30, count($m));
        foreach ($m as $output) {
            $isNumberOrKey = preg_match('/\.(key|count|left|rate_count|room_count|from|meal_key|meals|index|amount|for|deposit_percent|capacity)$/', explode('|', trim($output[0], '{}'))[0]) === 1;
            if (!$isNumberOrKey) {
                self::assertStringContainsString('|escape:html', $output[0], 'unescaped output: ' . $output[0]);
            }
        }
    }

    public function testTheHooksThePageScriptDrivesAreThere(): void
    {
        $src = self::all();
        $hooks = [
            'data-travel-results', 'data-travel-meal', 'data-travel-chip', 'data-travel-sort', 'data-travel-room-list',
            'data-travel-room', 'data-room-key=', 'data-travel-option', 'data-travel-pick', 'data-travel-for',
            'data-travel-say', 'data-travel-announce', 'data-travel-rall', 'data-travel-see-all', 'data-travel-hint',
            'data-travel-no-match', 'data-travel-clear-filters', 'data-travel-tip', 'data-travel-tip-pop', 'data-travel-tip-close',
            'data-travel-amen-all', 'data-travel-amen-more', 'data-travel-terms-url', 'data-travel-terms-body', 'data-travel-terms-wait',
            'data-meal=', 'data-amount=', 'data-from=', 'data-from-text=', 'data-name=', 'data-key=', 'data-index=',
        ];
        $js = (string) file_get_contents(self::addonRoot() . '/js/addons/travel_core/pdp-design-a.js');
        foreach ($hooks as $hook) {
            self::assertStringContainsString($hook, $src, $hook);
            self::assertStringContainsString(rtrim($hook, '='), $js, 'pdp-design-a.js drives ' . $hook);
        }
        // The chips read an option's data-<chip key> (free / immediate / special).
        foreach (['data-free=', 'data-immediate=', 'data-special='] as $hook) {
            self::assertStringContainsString($hook, $src, $hook);
        }
        self::assertStringContainsString("getAttribute('data-' + key)", $js);
        foreach (['filterRooms', 'showRooms', 'sortRooms', 'choose', 'data-travel-open', 'data-travel-picked', 'data-travel-live'] as $piece) {
            self::assertStringContainsString($piece, $js, $piece);
        }
    }

    public function testRulesFromTheBoardAreKept(): void
    {
        $results = self::source('responsive', 'design_a_results.tpl');
        self::assertStringContainsString('{if $travel_pdpa_results.meal_plans}', $results, 'the meal filter only with 2+ meal plans (the builder sends none for one)');
        self::assertStringContainsString('travel_core.pdpa_r_count_meal_', $results, 'one meal plan: said in the count line');
        self::assertStringContainsString('{foreach $travel_pdpa_results.chips as $travel_pdpa_chip}', $results, 'only the chips the builder kept');
        self::assertStringContainsString('travel_core.pdpa_r_footnote_struck_deal', $results, 'the footnote says what the struck price leaves out');

        $room = self::source('responsive', 'design_a_room.tpl');
        self::assertStringContainsString('<legend class="travel-pdpa-sr">', $room, 'no visible "Alege masa": screen readers only');
        self::assertStringContainsString('{if $travel_pdpa_room.single}', $room, 'one meal plan: the same block, without a radio');

        $till = self::source('responsive', 'design_a_till.tpl');
        self::assertStringContainsString('travel_core.pdpa_r_request', $till, 'on request: "Cere rezervarea"');
        self::assertStringContainsString('travel_core.pdpa_r_no_pay_yet', $till, '"Nu plătești încă" under the button');
        self::assertStringContainsString('role="dialog"', $till, 'the ⓘ opens a toggletip, not a hover tooltip');
        self::assertSame(1, substr_count($till, 'class="travel-pdpa-btn'), 'one button per till: the room\'s only one');
        self::assertStringNotContainsString('travel-pdpa-btn--sec', $till);

        $option = self::source('responsive', 'design_a_option.tpl');
        self::assertStringContainsString('type="radio"', $option);
        self::assertStringContainsString('{if $travel_pdpa_option.chosen} checked{/if}', $option, 'the cheapest option chosen when the card opens');
    }
}
