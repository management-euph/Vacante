<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Design A room-first results component
 * (templates/addons/travel_core/components/design_a_*.tpl,
 * docs/hotel-page-design-a-plan.md phase 3), which every supplier's search
 * renders for a Design A hotel page:
 *
 *  - both themes carry the same files (production runs nova_theme);
 *  - every text it prints is seeded in English and Romanian, the plural
 *    forms RoomResultsBuilder::form() picks included;
 *  - it prints the view model's plain text escaped, never raw;
 *  - the hooks pdp-design-a.js drives (filters, sort, "see all") are there.
 */
final class DesignAResultsComponentTest extends TestCase
{
    private const DIR = 'templates/addons/travel_core/components/';

    private const FILES = [
        'design_a_results.tpl',
        'design_a_room.tpl',
        'design_a_rate.tpl',
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
        preg_match_all('/\{\$travel_pdpa_(?:room|rate|plan|group|results|section|line|perk|chip)\.[a-z_.]+(\|[^}]*)?\}/', $src, $m, PREG_SET_ORDER);
        foreach ($m as $output) {
            $isNumberOrKey = preg_match('/\.(key|count|left|rate_count|room_count|from|meal_key)$/', explode('|', trim($output[0], '{}'))[0]) === 1;
            if (!$isNumberOrKey) {
                self::assertStringContainsString('|escape:html', $output[0], 'unescaped output: ' . $output[0]);
            }
        }
    }

    public function testTheHooksThePageScriptDrivesAreThere(): void
    {
        $src = self::all();
        foreach ([
            'data-travel-results', 'data-travel-meal', 'data-travel-chip', 'data-travel-sort', 'data-travel-room-list',
            'data-travel-room', 'data-travel-rates', 'data-travel-group', 'data-travel-rate', 'data-travel-more',
            'data-travel-compact', 'data-travel-see-all', 'data-travel-no-match', 'data-travel-clear-filters',
            'data-meal=', 'data-free=', 'data-immediate=', 'data-special=', 'data-from=',
        ] as $hook) {
            self::assertStringContainsString($hook, $src, $hook);
        }
        $js = (string) file_get_contents(self::addonRoot() . '/js/addons/travel_core/pdp-design-a.js');
        foreach (['filterRates', 'sortRooms', 'data-travel-see-all', 'data-travel-open'] as $piece) {
            self::assertStringContainsString($piece, $js, $piece);
        }
    }

    public function testRulesFromTheBoardAreKept(): void
    {
        $results = self::source('responsive', 'design_a_results.tpl');
        self::assertStringContainsString('{if $travel_pdpa_results.meal_plans|count > 1}', $results, 'R1: the strip only with 2+ meal plans');
        self::assertStringContainsString('$travel_pdpa_results.facts.meal', $results, 'R2: one meal plan said once');
        self::assertStringContainsString('{foreach $travel_pdpa_results.chips as $travel_pdpa_chip}', $results, 'R3: only the chips the builder kept');

        $rate = self::source('responsive', 'design_a_rate.tpl');
        self::assertStringContainsString('travel_core.pdpa_r_request', $rate, 'on request: "Cere rezervarea"');
        self::assertStringContainsString('{if !$travel_pdpa_rate.primary} travel-pdpa-btn--sec{/if}', $rate, 'one filled button per room');
    }
}
