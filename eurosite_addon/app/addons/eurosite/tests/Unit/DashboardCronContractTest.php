<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * The dashboard's half of the scheduling feature: the controller hands the
 * page a plan, the template renders it, and dashboard.js drives the toggles.
 *
 * CronPlanBuilderTest owns the rules themselves. What is pinned here is the
 * join between the three files, which is exactly where a rename goes
 * unnoticed — the page still renders, just emptier.
 */
final class DashboardCronContractTest extends TestCase
{
    private const ADDON_ROOT = __DIR__ . '/../..';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function controller(): string
    {
        return (string) file_get_contents(self::ADDON_ROOT . '/controllers/backend/eurosite.php');
    }

    private static function template(): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/eurosite_addon/design/backend/templates/addons/eurosite/views/eurosite/manage.tpl',
        );
    }

    private static function script(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/eurosite_addon/js/addons/eurosite/dashboard.js');
    }

    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    public function testTheControllerBuildsThePlanWithTheService(): void
    {
        $controller = self::controller();

        self::assertStringContainsString('use Tygh\\Addons\\Eurosite\\Services\\CronPlanBuilder;', $controller);
        self::assertStringContainsString('new CronPlanBuilder($baseUrl, $cronKey, ', $controller);
        self::assertStringContainsString('$plan->rows($syncModes, $counts, $lastSyncs)', $controller);

        // The schedule table and the URL shape belong to the service now — a
        // second copy in the controller is how the two drift apart.
        self::assertStringNotContainsString('$cronSchedules', $controller);
        self::assertStringNotContainsString('dispatch=eurosite_cron.run&access_key={$cronKey}', $controller);
    }

    /**
     * All four crontab variants are rendered up front so the toggles need no
     * round trip; the page reads them out of one attribute.
     */
    public function testAllFourCrontabVariantsReachThePage(): void
    {
        $controller = self::controller();
        $tpl = self::template();

        self::assertStringContainsString("foreach (['full', 'per'] as \$planKey)", $controller);
        self::assertStringContainsString("foreach (['url', 'cli'] as \$format)", $controller);
        self::assertStringContainsString("\$view->assign('eurosite_crontabs_json', json_encode(\$crontabs));", $controller);
        self::assertStringContainsString('data-crontabs="{$eurosite_crontabs_json|escape:html}"', $tpl);

        // The script indexes them by "<plan>_<format>".
        self::assertStringContainsString("\$crontabs[\$planKey . '_' . \$format]", $controller);
        self::assertStringContainsString("variants[state.plan + '_' + state.format]", self::script());
    }

    /**
     * REGRESSION: with no key the endpoint answers 403 to everything, and the
     * page used to print a table of commands with an empty access_key= — nine
     * dead URLs, presented as ready to schedule.
     */
    public function testWithNoKeyThePageOffersAKeyInsteadOfCommands(): void
    {
        $tpl = self::template();
        $controller = self::controller();

        self::assertStringContainsString("\$view->assign('eurosite_cron_has_key', \$plan->hasKey());", $controller);
        self::assertStringContainsString('{if !$eurosite_cron_has_key}', $tpl);
        self::assertStringContainsString('eurosite.cron_no_key_title', $tpl);
        self::assertStringContainsString('value="eurosite.generate_cron_key"', $tpl);

        // The crontab block and the per-row copy buttons live on the other
        // side of that branch.
        $noKeyBranch = substr($tpl, strpos($tpl, '{if !$eurosite_cron_has_key}') ?: 0);
        $elsePos = strpos($noKeyBranch, '{else}');
        self::assertIsInt($elsePos);
        self::assertStringNotContainsString('id="eurosite-crontab"', substr($noKeyBranch, 0, $elsePos));
    }

    /**
     * The button still mints a key — Travel Core's, not an eurosite-local one.
     *
     * Cron authentication moved into Travel Core: one secret for all three
     * providers, rotated in one place, in one crontab. The button stays where
     * it is (this dashboard is where the commands are), but writing an
     * eurosite row here would now produce a value nothing reads —
     * ConfigProvider::getCronKey() delegates to CronKeyService — so it would
     * report success while every scheduled job kept using the old key. That
     * is a worse failure than the blank key this button was added for,
     * because it looks like it worked.
     */
    public function testGeneratingAKeyMintsTheSharedCoreKey(): void
    {
        // Comment-stripped, and scoped to this one mode. The raw-source
        // version of this test was satisfied by the COMMENT above the call
        // ("lives in CronKeyService::generate() now"), so replacing the mint
        // with a read of the existing key — a button that reports "a new key
        // was generated" while changing nothing — kept every suite green.
        $mode = self::modeBody('generate_cron_key');

        self::assertStringContainsString('$newKey = CronKeyService::generate();', $mode);
        self::assertStringNotContainsString(
            "updateValue('cron_access_key'",
            $mode,
            'the dashboard still writes an eurosite-local cron key that nothing reads',
        );
        // The cached settings array would otherwise still hold the old key for
        // the rest of the request.
        self::assertStringContainsString('ConfigProvider::resetSettingsCache();', $mode);
        // Rotating invalidates every scheduled URL of EVERY travel addon,
        // Travel Core's own exchange-rate job included.
        foreach (['Travel Core -> Tools', 'Eurosite', 'Sphinx', 'Novoton'] as $place) {
            self::assertStringContainsString($place, $mode, "the rotation notice never mentions {$place}");
        }
    }

    /**
     * The comment-stripped body of `if ($mode === '<mode>') { ... }` in the
     * controller, found by matching braces on PHP's tokens so a brace inside a
     * string cannot end it early.
     */
    private static function modeBody(string $mode): string
    {
        $code = '';
        $kinds = [];
        foreach (token_get_all(self::controller()) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text = is_array($token) ? $token[1] : $token;
            $open = $token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            $kinds[] = [strlen($code), $open ? 1 : ($token === '}' ? -1 : 0)];
            $code .= $text;
        }

        $at = strpos($code, "if (\$mode === '{$mode}')");
        self::assertIsInt($at, "mode {$mode} is gone");

        $depth = 0;
        $start = null;
        foreach ($kinds as [$offset, $delta]) {
            if ($offset < $at || $delta === 0) {
                continue;
            }
            $depth += $delta;
            $start ??= $offset;
            if ($depth === 0) {
                return substr($code, $start, $offset + 1 - $start);
            }
        }

        self::fail("mode {$mode} has no balanced body");
    }

    public function testTheMergedTableRendersEveryFieldThePlanProvides(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('{foreach from=$eurosite_cron_rows item=job}', $tpl);
        foreach (['mode', 'description', 'count', 'schedule_human', 'schedule_cron', 'state', 'state_label'] as $field) {
            self::assertStringContainsString('$job.' . $field, $tpl, "the table never renders {$field}");
        }

        // The old page had a second table saying the same things.
        self::assertStringNotContainsString('$eurosite_catalog_rows', $tpl);
        self::assertStringNotContainsString("\$view->assign('eurosite_catalog_rows'", self::controller());
    }

    /**
     * The one diagnosis the page can make by itself: hotels reads the
     * whitelist, and the whitelist is empty.
     */
    public function testTheHotelsRowExplainsAnEmptyWhitelist(): void
    {
        $tpl = self::template();

        self::assertStringContainsString("{if \$job.mode == 'hotels' && \$eurosite_counts.whitelist == 0}", $tpl);
        self::assertStringContainsString('eurosite.hotels_need_whitelist', $tpl);
        self::assertStringContainsString('{"eurosite.whitelist"|fn_url}', $tpl);
    }

    public function testEveryCopyButtonCarriesItsPayloadAndFeedbackText(): void
    {
        $tpl = self::template();

        // Whole elements, not "up to the next '>'": the Smarty `=>` inside a
        // label's [default] argument ends an attribute-only match early.
        preg_match_all('#<button\b.*?</button>#s', $tpl, $m);
        $copyButtons = array_values(array_filter(
            $m[0],
            static fn (string $html): bool => str_contains($html, 'eurosite-copy'),
        ));
        self::assertNotEmpty($copyButtons, 'no copy buttons on the dashboard');

        foreach ($copyButtons as $html) {
            self::assertStringContainsString('data-copy="', $html, 'a copy button with nothing to copy');
            self::assertStringContainsString('data-txt-copied="', $html);
            self::assertStringContainsString('data-txt-copy-failed="', $html);
        }
    }

    public function testTheScriptShipsAndIsLoadedInsideTheCapture(): void
    {
        $tpl = self::template();
        $js = self::repoRoot() . '/eurosite_addon/js/addons/eurosite/dashboard.js';

        self::assertFileExists($js);
        self::assertStringContainsString('{script src="js/addons/eurosite/dashboard.js"}', $tpl);

        $script = strpos($tpl, '{script src="js/addons/eurosite/dashboard.js"}');
        $endCapture = strpos($tpl, '{/capture}');
        self::assertIsInt($script);
        self::assertIsInt($endCapture);
        self::assertLessThan($endCapture, $script, 'AJAX navigation drops a {script} outside the mainbox capture');

        // navigator.clipboard is undefined on a plain-http admin, which is how
        // most of these stores are reached — the fallback is not optional.
        self::assertStringContainsString('execCommand', self::script(), 'the copy fallback is missing');
    }

    /**
     * Masking is a display concern only. A crontab pasted with bullets in it
     * is a 403 at 01:00 that nobody is awake to see.
     */
    public function testCopyingSendsTheRealKeyEvenWhileItIsMasked(): void
    {
        $js = self::script();

        self::assertStringContainsString('function masked(', $js);
        self::assertStringContainsString('pre.textContent = masked(plainText());', $js);
        self::assertStringContainsString('copyToClipboard(plainText())', $js);
        self::assertStringNotContainsString('copyToClipboard(masked(', $js);
    }

    public function testTheTogglesTheTemplateRendersAreTheOnesTheScriptBinds(): void
    {
        $tpl = self::template();
        $js = self::script();

        foreach (['eurosite_cron_plan', 'eurosite_cron_format'] as $group) {
            self::assertStringContainsString('name="' . $group . '"', $tpl);
            self::assertStringContainsString("bindRadios('" . $group . "'", $js);
        }
        foreach (['eurosite-crontab', 'eurosite-crontab-text', 'eurosite-crontab-copy', 'eurosite-crontab-reveal'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $tpl);
            self::assertStringContainsString("'" . $id . "'", $js);
        }
    }

    /**
     * The per-row actions live behind one glyph. Two things must hold: the
     * toggle is a real menu button (an icon-only div with a click handler is
     * invisible to Tab and to a screen reader), and the menu still renders
     * when there is no key — saying why the copies are missing beats a column
     * that silently loses its actions, which is how this looked on the store.
     */
    public function testEveryRowHasAnAccessibleOverflowMenu(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('class="eurosite-menu-wrap"', $tpl);
        self::assertStringContainsString('class="btn btn-micro eurosite-menu-toggle"', $tpl);
        self::assertStringContainsString('&#8943;', $tpl, 'the toggle needs its ⋯ glyph');

        foreach (['aria-haspopup="true"', 'aria-expanded="false"', 'aria-controls="eurosite-menu-{$job.mode}"'] as $attr) {
            self::assertStringContainsString($attr, $tpl, "the menu button is missing {$attr}");
        }
        self::assertStringContainsString('eurosite.more_actions', $tpl, 'an icon-only button needs a label');
        self::assertStringContainsString('role="menu"', $tpl);
        self::assertStringContainsString('id="eurosite-menu-{$job.mode}"', $tpl);

        // The menu itself is outside the has-key branch; only its contents differ.
        $menuStart = strpos($tpl, 'class="eurosite-menu-wrap"');
        self::assertIsInt($menuStart);
        $rowEnd = strpos($tpl, '</td>', $menuStart);
        self::assertIsInt($rowEnd);
        $cell = substr($tpl, $menuStart, $rowEnd - $menuStart);
        self::assertStringContainsString('{if $eurosite_cron_has_key}', $cell);
        self::assertStringContainsString('{else}', $cell);
        self::assertStringContainsString('eurosite.menu_needs_key', $cell);
        self::assertStringContainsString('#eurosite-scheduled-jobs', $cell, 'the no-key note must link to the fix');
    }

    public function testTheMenuOffersAllThreeCopiesAsMenuItems(): void
    {
        $tpl = self::template();

        foreach ([
            '$job.crontab_line' => 'eurosite.copy_crontab_line',
            '$job.url' => 'eurosite.copy_url',
            '$job.cli' => 'eurosite.copy_cli',
        ] as $payload => $label) {
            self::assertStringContainsString('data-copy="{' . $payload . '|escape:html}"', $tpl);
            self::assertStringContainsString($label, $tpl);
        }

        // Each is a real menuitem carrying the shared copy behaviour.
        preg_match_all('#<button[^>]*role="menuitem"[^>]*>#s', $tpl, $m);
        self::assertCount(3, $m[0]);
        foreach ($m[0] as $button) {
            self::assertStringContainsString('eurosite-copy', $button);
        }
    }

    /**
     * A dropdown that cannot be dismissed or escaped is worse than no
     * dropdown: it covers the row below it until you reload.
     */
    public function testTheMenuScriptHandlesDismissalAndKeyboardUse(): void
    {
        $js = self::script();

        self::assertStringContainsString('function initMenus(', $js);
        self::assertStringContainsString("aria-expanded", $js, 'the toggle state must be announced');
        self::assertStringContainsString("e.key === 'Escape'", $js);
        self::assertStringContainsString("e.key === 'ArrowDown'", $js);
        self::assertStringContainsString("e.key === 'ArrowUp'", $js);
        self::assertStringContainsString('toggle.focus()', $js, 'Escape must not strand focus in a hidden menu');
        self::assertStringContainsString('!open.menu.contains(e.target)', $js, 'clicking outside must close it');
    }

    public function testTheWrapperDivIsClosed(): void
    {
        // The page wrapper went unclosed for the life of this template; the
        // browser recovered by closing it at the end of the capture, which
        // also swallows anything appended after it.
        $tpl = preg_replace('/\{\*.*?\*\}/s', '', self::template());
        self::assertIsString($tpl);
        self::assertSame(
            substr_count($tpl, '<div'),
            substr_count($tpl, '</div>'),
            'unbalanced <div> in manage.tpl',
        );
    }

    public function testTheAccessKeyWarningIsShown(): void
    {
        self::assertStringContainsString('eurosite.cron_access_key_note', self::template());
    }

    /**
     * Cheap guard on the service's own table: a command registering a new mode
     * should get a named slot rather than silently taking the fallback.
     */
    public function testEveryDispatcherModeHasItsOwnSchedule(): void
    {
        $plan = new CronPlanBuilder('https://example.test', 'k');
        $modes = CronDispatcher::getAvailableModes();
        self::assertNotEmpty($modes);

        $rows = $plan->rows($modes, [], []);
        $scheduled = [];
        foreach ($rows as $row) {
            $scheduled[(string) $row['mode']] = (string) $row['schedule_cron'];
        }

        foreach (array_keys($modes) as $mode) {
            if ($mode === 'full') {
                continue; // the headline action, not a row
            }
            if (in_array($mode, CronPlanBuilder::DIAGNOSTIC, true)) {
                // Read-only, run by hand: never a dashboard row or a crontab line.
                self::assertArrayNotHasKey($mode, $scheduled, "diagnostic {$mode} must not be scheduled");
                continue;
            }
            self::assertArrayHasKey($mode, $scheduled, "cron mode {$mode} has no row");
            self::assertTrue(
                CronPlanBuilder::hasSchedule($mode),
                "cron mode {$mode} has no slot of its own — add one to CronPlanBuilder::SCHEDULES",
            );
        }
    }
}
