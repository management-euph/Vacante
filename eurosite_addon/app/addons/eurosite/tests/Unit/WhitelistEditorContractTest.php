<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The whitelist editor is a template and a script that only work together, and
 * every way they can fall out of step is silent in the browser.
 *
 * The page shipped broken in three of those ways at once:
 *
 *   1. whitelist.tpl asked for "js/addons/eurosite/whitelist.js" while the file
 *      lived under design/backend/js — a 404, so NOTHING on the page worked:
 *      no search, no expand, no save. (The repo-wide pin for that class of bug
 *      is travel_core's AddonScriptPathsTest; here we pin this page's copy.)
 *   2. the search box was a select2 widget, so the page silently did nothing
 *      when the kit's select2 was not loaded — the script's own guard skipped
 *      the whole block.
 *   3. an id or data-attribute renamed on one side only degrades quietly: the
 *      script falls back to an English default, or a handler never binds.
 *
 * So: the script's literal DOM lookups must exist in the template, and the
 * template's script reference must resolve on disk.
 */
final class WhitelistEditorContractTest extends TestCase
{
    private const ADDON_ROOT = __DIR__ . '/../..';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function template(): string
    {
        $path = self::ADDON_ROOT . '/../../../design/backend/templates/addons/eurosite/views/eurosite/whitelist.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function script(): string
    {
        $path = self::repoRoot() . '/eurosite_addon/js/addons/eurosite/whitelist.js';
        self::assertFileExists($path, 'the page script is not where whitelist.tpl asks for it');

        return (string) file_get_contents($path);
    }

    public function testTemplateLoadsTheScriptFromWhereItActuallyShips(): void
    {
        $tpl = self::template();

        self::assertStringContainsString(
            '{script src="js/addons/eurosite/whitelist.js"}',
            $tpl,
            'the page must load its script',
        );
        // A leading "js/" means the DOCROOT /js/ tree, which is <addon>/js/… in
        // the repo — not design/backend/js/.
        self::assertFileExists(self::repoRoot() . '/eurosite_addon/js/addons/eurosite/whitelist.js');
        self::assertFileDoesNotExist(
            self::repoRoot() . '/eurosite_addon/design/backend/js/addons/eurosite/whitelist.js',
            'two copies of the page script is worse than one in the wrong place',
        );
    }

    public function testTheScriptTagIsInsideTheMainboxCapture(): void
    {
        $tpl = self::template();
        $capture = strpos($tpl, '{capture name="mainbox"}');
        $endCapture = strpos($tpl, '{/capture}');
        $script = strpos($tpl, '{script src="js/addons/eurosite/whitelist.js"}');

        self::assertIsInt($capture);
        self::assertIsInt($endCapture);
        self::assertIsInt($script);
        // The admin top-nav navigates by AJAX and the response carries only the
        // captured mainbox: a {script} outside it is dropped on that path.
        self::assertGreaterThan($capture, $script, 'the script tag must be inside the mainbox capture');
        self::assertLessThan($endCapture, $script, 'the script tag must be inside the mainbox capture');
    }

    public function testThePageDoesNotDependOnSelect2(): void
    {
        $tpl = self::template();
        $js = self::script();

        // The library itself must not be pulled in...
        self::assertStringNotContainsString('js/lib/select2', $tpl, 'the page must not load select2');
        // ...and nothing may call it, guarded or not: the old guard
        // (`typeof $.fn.select2 !== 'undefined'`) is exactly what made a
        // missing library look like a page that simply ignores you.
        self::assertStringNotContainsString('.select2(', $js);
        self::assertStringNotContainsString('select2:select', $js);
        // The search control is a plain input, not a <select> widget.
        self::assertStringContainsString('<input type="text" id="eurosite-wl-search"', $tpl);
    }

    public function testTheSearchControlIsPresent(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('id="eurosite-wl-search"', $tpl);
        self::assertStringContainsString('id="eurosite-wl-search-results"', $tpl);
        self::assertStringContainsString('eurosite.search_destinations', $tpl, 'the box needs its placeholder label');
    }

    /**
     * Every getElementById('…') the script performs must find something.
     */
    public function testEveryElementTheScriptLooksUpExistsInTheTemplate(): void
    {
        $tpl = self::template();
        preg_match_all("/getElementById\('([a-z0-9-]+)'\)/i", self::script(), $m);
        $ids = array_values(array_unique($m[1]));

        self::assertNotEmpty($ids, 'no id lookups found — this scan is broken');
        foreach ($ids as $id) {
            self::assertStringContainsString(
                'id="' . $id . '"',
                $tpl,
                "whitelist.js looks up #{$id}, which the template does not render",
            );
        }
    }

    /**
     * The server hands the script its configuration through data-attributes on
     * #eurosite-whitelist-data. A missing one is invisible: the script falls
     * back to a hard-coded English string, or to an empty URL.
     */
    public function testEveryDataAttributeTheScriptReadsIsRendered(): void
    {
        $tpl = self::template();
        preg_match_all("/dataEl\.getAttribute\('(data-[a-z-]+)'\)/", self::script(), $m);
        $attrs = array_values(array_unique($m[1]));

        self::assertNotEmpty($attrs);
        foreach ($attrs as $attr) {
            self::assertStringContainsString(
                $attr . '="',
                $tpl,
                "whitelist.js reads {$attr} off #eurosite-whitelist-data, which the template does not set",
            );
        }
    }

    /**
     * Class hooks the template must render for the script to bind handlers.
     * The rest (.eurosite-city, .eurosite-wl-hit) are rendered by the script
     * itself, so they deliberately are not listed here.
     */
    public function testServerRenderedClassHooksExist(): void
    {
        $tpl = self::template();
        foreach ([
            'eurosite-country-row',
            'eurosite-expand',
            'eurosite-country-name',
            'eurosite-country-all',
            'eurosite-select-all',
            'eurosite-city-box',
            'eurosite-city-grid',
            'eurosite-wl-badge',
        ] as $class) {
            self::assertStringContainsString('class="' . $class, $tpl, "missing class hook {$class}");
            self::assertStringContainsString($class, self::script(), "{$class} is rendered but never used");
        }
    }

    /**
     * REGRESSION: a country whose catalog row carries no name rendered as a
     * bare code ("TT", "VC") with nothing to click, and the summary panel had
     * no text to show for it.
     */
    public function testCountryNameFallsBackToTheCodeAndIsClickable(): void
    {
        $tpl = self::template();

        self::assertMatchesRegularExpression(
            '/class="eurosite-country-name"[^>]*data-country="\{\$cc\}"/s',
            $tpl,
            'the country name needs its own hook so clicking it can expand the row',
        );
        self::assertStringContainsString(
            '{$country.name|default:$cc|escape:html}',
            $tpl,
            'a nameless catalog row must still render something',
        );
    }

    /**
     * Own hotels: "Show only countries with own hotels" keeps the countries
     * that have own-offer cities (the only ones where the hotels sync finds
     * hotels); inside an open country all cities show, and its own "Show
     * only own cities" narrows them.
     */
    public function testTheOwnHotelsFilterIsWiredEndToEnd(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/backend/eurosite.php');
        $tpl = self::template();
        $js = self::script();

        self::assertStringContainsString('$ownByCountry = Container::cities()->ownCountsByCountry();', $controller);
        self::assertStringContainsString("\$countries[\$i]['own_cities'] = ", $controller);
        self::assertStringContainsString('{assign var="own_n" value=$country.own_cities}', $tpl);
        self::assertStringContainsString('id="eurosite-wl-own-filter"', $tpl);
        self::assertStringContainsString('data-own="{$own_n}"', $tpl, 'each country row says how many own-offer cities it has');
        self::assertStringContainsString("document.getElementById('eurosite-wl-own-filter')", $js);
        self::assertStringContainsString("r.getAttribute('data-own')", $js);
        self::assertStringContainsString("data-own=\"' + (city.is_own ? '1' : '0') + '\"", $js, 'each city checkbox carries its own flag');

        // The page filter hides countries only; each open country has its own
        // "Show only own cities", offered only where there are some.
        self::assertStringContainsString('{if $own_n}', $tpl);
        self::assertStringContainsString('class="eurosite-own-cities" data-country="{$cc}"', $tpl);
        self::assertStringContainsString("qa('.eurosite-own-cities')", $js);
        self::assertStringContainsString("(!ownCitiesOnly[cc] || cb.getAttribute('data-own') === '1')", $js);
        self::assertStringNotContainsString("(!ownOn || cb.getAttribute('data-own')", $js, 'the page filter must not hide cities');

        // "Select all own cities" next to "Select all cities", same condition.
        self::assertStringContainsString('class="eurosite-select-own" data-country="{$cc}"', $tpl);
        self::assertStringContainsString('eurosite.select_all_own_cities', $tpl);
        self::assertStringContainsString("qa('.eurosite-select-own')", $js);
        self::assertStringContainsString('function onSelectOwnToggle(cc, cb)', $js);
    }
}
