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
        self::assertStringContainsString('new CronPlanBuilder($baseUrl, $cronKey)', $controller);
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

    public function testGeneratingAKeyWritesARandomValueToTheSetting(): void
    {
        $controller = self::controller();

        self::assertStringContainsString("if (\$mode === 'generate_cron_key')", $controller);
        self::assertStringContainsString('bin2hex(random_bytes(16))', $controller);
        self::assertStringContainsString("updateValue('cron_access_key', \$newKey, 'eurosite', true)", $controller);
        // The cached settings array would otherwise still hold the old key for
        // the rest of the request.
        self::assertStringContainsString('ConfigProvider::resetSettingsCache();', $controller);
        // Rotating invalidates every scheduled URL — the operator has to know.
        self::assertStringContainsString('Re-copy your crontab', $controller);
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
            self::assertArrayHasKey($mode, $scheduled, "cron mode {$mode} has no row");
            self::assertTrue(
                CronPlanBuilder::hasSchedule($mode),
                "cron mode {$mode} has no slot of its own — add one to CronPlanBuilder::SCHEDULES",
            );
        }
    }
}
