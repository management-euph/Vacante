<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;

/**
 * The dashboard's cron-commands table, modelled on the Sphinx dashboard: one
 * row per cron mode with its schedule, its URL and its CLI equivalent, each
 * runnable in a click and copyable in another.
 *
 * Pinned at source level because the controller needs Tygh's view and a live
 * dispatcher to run. What these checks protect is the join between three files
 * that have to agree: the controller assigns the rows, the template reads their
 * keys, and dashboard.js wires the copy buttons.
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

    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    public function testEveryCronModeTheDispatcherKnowsGetsARow(): void
    {
        $controller = self::controller();
        $modes = array_keys(CronDispatcher::getAvailableModes());

        self::assertNotEmpty($modes);
        // The rows are built by iterating the dispatcher's own mode map, so a
        // new command shows up without touching the dashboard.
        self::assertStringContainsString('foreach ($syncModes as $m => $description)', $controller);

        // Every mode still needs a suggested schedule; the map falls back to a
        // nightly slot, which is fine, but a mode we KNOW about should be named.
        preg_match('/\$cronSchedules = \[(.*?)\];/s', $controller, $m);
        self::assertNotEmpty($m, 'the schedule map is gone');
        foreach ($modes as $mode) {
            self::assertStringContainsString("'{$mode}'", $m[1], "cron mode {$mode} has no suggested schedule");
        }
    }

    public function testTemplateReadsExactlyTheKeysTheControllerAssigns(): void
    {
        $controller = self::controller();
        $tpl = self::template();

        self::assertStringContainsString("\$view->assign('eurosite_cron_rows', \$cronRows);", $controller);
        self::assertStringContainsString("\$view->assign('eurosite_cron_cli', \$cronCli);", $controller);
        self::assertStringContainsString('{foreach from=$eurosite_cron_rows item=cron}', $tpl);

        foreach (['mode', 'description', 'schedule', 'url', 'cli'] as $key) {
            self::assertStringContainsString("'{$key}'", $controller, "row key {$key} is not built");
            self::assertStringContainsString('$cron.' . $key, $tpl, "row key {$key} is never rendered");
        }
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

        foreach ($copyButtons as $attrs) {
            self::assertStringContainsString('data-copy="', $attrs, 'a copy button with nothing to copy');
            self::assertStringContainsString('data-txt-copied="', $attrs);
            self::assertStringContainsString('data-txt-copy-failed="', $attrs);
        }
    }

    public function testTheCopyScriptShipsAndIsLoadedInsideTheCapture(): void
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
        $body = (string) file_get_contents($js);
        self::assertStringContainsString('execCommand', $body, 'the copy fallback for non-secure contexts is missing');
    }

    /**
     * The cron URLs embed the addon's access key, so the page says so.
     */
    public function testTheAccessKeyWarningIsShown(): void
    {
        self::assertStringContainsString('eurosite.cron_access_key_note', self::template());
    }
}
