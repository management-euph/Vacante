<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\CronPlan;

/**
 * The dashboard's scheduled jobs never put the cron key in a page or a URL.
 *
 * Its Cron Commands, Image sync and Diagnostic tables printed every job's
 * URL with the shared cron key in plain text, and their Run links put it in
 * the browser's history and Referer headers. The page now shows Travel Core's
 * shared jobs card (commands masked, copied for real), and every Run, menu
 * item, Queue images for a scope and Check one hotel is an admin POST to
 * sphinx_holidays.run_job, which CS-Cart checks with its security_hash and
 * which passes on only what CronPlan::runParams() allows.
 *
 * Source-text assertions: the controller and templates run inside CS-Cart,
 * which this repository does not ship.
 */
final class DashboardJobsTest extends TestCase
{
    private const string TEMPLATES = '/design/backend/templates/addons/sphinx_holidays';

    private static function controller(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php');
    }

    /** A template of this add-on without its {* comments *}. */
    private static function template(string $path): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 6) . self::TEMPLATES . '/' . $path);

        return (string) preg_replace('/\{\*.*?\*\}/s', '', $src);
    }

    /** @return array<string, string> the dashboard and the two cards it includes */
    private static function pages(): array
    {
        return [
            'manage.tpl' => self::template('views/sphinx_holidays/manage.tpl'),
            'image_scope.tpl' => self::template('components/image_scope.tpl'),
            'check_hotel.tpl' => self::template('components/check_hotel.tpl'),
        ];
    }

    /** @return string the run_job handler's body */
    private static function handler(): string
    {
        $src = self::controller();
        $start = strpos($src, "if (\$mode === 'run_job') {");
        self::assertIsInt($start, 'no run_job handler');
        $end = strpos($src, "\n    }\n", $start);
        self::assertIsInt($end);

        return substr($src, $start, $end - $start);
    }

    /** @return string the manage branch's body */
    private static function manage(): string
    {
        $src = self::controller();
        $start = strpos($src, "if (\$mode === 'manage') {");
        self::assertIsInt($start, 'no manage branch');
        $end = strpos($src, "} elseif (\$mode === '", $start);
        self::assertIsInt($end);

        return substr($src, $start, $end - $start);
    }

    /**
     * The names a form sends: its fields, its hidden inputs, its buttons.
     *
     * @return list<string>
     */
    private static function fieldNames(string $tpl): array
    {
        preg_match_all('/<(?:input|select|textarea|button)\b[^>]*\bname="([^"]+)"/', $tpl, $m);

        return array_values(array_unique($m[1]));
    }

    public function testRunJobIsHandledOnlyForPost(): void
    {
        $src = self::controller();
        $post = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
        $handler = strpos($src, "if (\$mode === 'run_job') {");
        self::assertIsInt($post);
        self::assertIsInt($handler);
        $postEnds = strpos($src, "\n}\n", $post);

        self::assertIsInt($postEnds);
        self::assertGreaterThan($post, $handler);
        self::assertLessThan($postEnds, $handler, 'run_job must sit inside the POST block: a GET must not start a job');
    }

    public function testItChecksPermissionAndRunsOnlyKnownJobsWithWhatThePlanAllows(): void
    {
        $body = self::handler();

        self::assertStringContainsString("fn_check_permissions('manage_catalog', 'update', 'admin')", $body);
        self::assertStringContainsString("preg_replace('/[^a-z0-9_]/', ''", $body);
        self::assertStringContainsString('array_key_exists($job, CronDispatcher::getAvailableModes())', $body);
        self::assertStringContainsString("\$params = CronPlan::runParams(\$job, RequestCoerce::string(\$_REQUEST, 'job_action'), \$_REQUEST);", $body);
        self::assertStringContainsString('CronRunOutput::dispatch(new CronDispatcher(), $job, $params);', $body);
        self::assertSame(1, substr_count($body, '$_REQUEST);'), 'the request reaches the job only through CronPlan::runParams()');
        self::assertStringNotContainsString('toStringMap($_REQUEST)', $body);
        self::assertStringNotContainsString('access_key', $body);
        self::assertStringContainsString("header('Content-Type: text/plain; charset=utf-8');", $body);
        self::assertStringContainsString('echo CronRunOutput::startLine($job);', $body);
    }

    /** The URL and the dashboard print a run the same way. */
    public function testThePublicCronEndpointPrintsThroughTheSameOutput(): void
    {
        $cron = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/sphinx_cron.php');

        self::assertStringContainsString('echo CronRunOutput::startLine($mode);', $cron);
        self::assertStringContainsString('CronRunOutput::dispatch($dispatcher, $mode, TypeCoerce::toStringMap($_REQUEST));', $cron);
    }

    public function testNoPagePrintsTheKeyOrACronUrl(): void
    {
        foreach (self::pages() as $name => $tpl) {
            self::assertStringNotContainsString('access_key', $tpl, $name);
            self::assertStringNotContainsString('sphinx_cron.run', $tpl, $name);
            self::assertStringNotContainsString('$cron_urls', $tpl, $name);
            self::assertStringNotContainsString('cron_mode=', $tpl, $name);
        }
        self::assertStringNotContainsString("assign('cron_urls'", self::controller());
    }

    /** Travel Core's cards, given Sphinx's rows and run action. */
    public function testTheDashboardShowsTravelCoresCards(): void
    {
        $tpl = self::pages()['manage.tpl'];

        self::assertMatchesRegularExpression('/\{include file="addons\/travel_core\/components\/jobs_card\.tpl"[^}]*card_id="sphinx-cron-commands"[^}]*stages=\$sphinx_job_stages[^}]*on_demand=\$sphinx_on_demand_jobs[^}]*has_key=\$sphinx_cron_has_key[^}]*run_dispatch="sphinx_holidays\.run_job"/s', $tpl);
        self::assertMatchesRegularExpression('/\{if \$sphinx_cron_has_key\}\s*\{include file="addons\/travel_core\/components\/commands_card\.tpl"[^}]*cli=\$sphinx_crontab_cli[^}]*url=\$sphinx_crontab_url[^}]*masked=\$sphinx_crontab_masked[^}]*key=\$sphinx_cron_key/s', $tpl);
        self::assertStringContainsString('{include file="addons/sphinx_holidays/components/image_scope.tpl"}', $tpl);
        self::assertStringContainsString('{include file="addons/sphinx_holidays/components/check_hotel.tpl"}', $tpl);
    }

    /** Every variable the cards read is assigned by the manage branch, from the plan. */
    public function testTheControllerHandsTheCardsTheirData(): void
    {
        $manage = self::manage();
        preg_match_all('/\$(sphinx_[a-z_]+)/', implode("\n", self::pages()), $m);

        foreach (array_unique($m[1]) as $var) {
            self::assertStringContainsString("\$view->assign('{$var}', ", $manage, "{$var} is read by the page but never assigned");
        }
        self::assertStringContainsString('new CronPlan(', $manage);
        self::assertStringContainsString('CronRunLog::get(CronPlan::ADDON, $cron_mode)', $manage);
        self::assertStringContainsString("str_replace(\$cron_key, '••••••••', \$crontab_cli)", $manage, 'the commands are shown masked');
    }

    /** Queue images for a scope and Check one hotel run as the jobs card's Run does. */
    public function testTheScopeAndCheckFormsPostToRunJob(): void
    {
        foreach (['image_scope.tpl', 'check_hotel.tpl'] as $name) {
            $tpl = self::pages()[$name];

            self::assertSame(1, preg_match_all('/<form\b[^>]*>/', $tpl, $forms), $name);
            self::assertMatchesRegularExpression('/method="post"/', $forms[0][0], $name);
            self::assertStringContainsString('travel-cron-run-form', $forms[0][0], $name . ': cron-jobs.js opens the log window for this class');
            self::assertStringContainsString('target="_blank"', $forms[0][0], $name . ': without the script the log still opens apart');
            self::assertStringContainsString('<input type="hidden" name="dispatch" value="sphinx_holidays.run_job" />', $tpl, $name);
            self::assertStringContainsString('<input type="hidden" name="security_hash" value="{$security_hash}" />', $tpl, $name);
        }
    }

    /** The forms send nothing runParams() would drop, and runParams() reads every field they send. */
    public function testTheFormsSendTheFieldsTheRunReads(): void
    {
        $pages = self::pages();

        self::assertSame(['dispatch', 'security_hash', 'job', 'scope', 'country', 'scope_id', 'limit', 'force'], self::fieldNames($pages['image_scope.tpl']));
        self::assertSame(['dispatch', 'security_hash', 'hotel', 'job', 'attach', 'apply'], self::fieldNames($pages['check_hotel.tpl']));

        self::assertStringContainsString('<input type="hidden" name="job" value="sync_images" />', $pages['image_scope.tpl']);
        preg_match_all('/<option value="([a-z]+)">/', $pages['image_scope.tpl'], $scopes);
        self::assertSame(['all', 'strict', 'country', 'destination', 'region'], $scopes[1]);

        preg_match_all('/<button type="submit" name="job" value="([a-z_]+)"/', $pages['check_hotel.tpl'], $checks);
        self::assertSame(CronPlan::DIAGNOSTIC, $checks[1], 'one button per one-hotel check');
    }

    /** Attaching images and writing SEO fields change the store: each box asks first, for its own check only. */
    public function testTheBoxesThatWriteAskFirst(): void
    {
        $tpl = self::pages()['check_hotel.tpl'];

        self::assertMatchesRegularExpression('/<input type="checkbox" name="attach" value="Y" data-travel-cron-confirm="\{__\("sphinx_holidays\.jobs_check_attach_confirm"\)\|escape:html\}" data-travel-cron-confirm-for="diagnose_images" \/>/', $tpl);
        self::assertMatchesRegularExpression('/<input type="checkbox" name="apply" value="Y" data-travel-cron-confirm="\{__\("sphinx_holidays\.jobs_check_apply_confirm"\)\|escape:html\}" data-travel-cron-confirm-for="diagnose_seo" \/>/', $tpl);
    }

    /** Every form on the dashboard posts to an action the controller has. */
    public function testEveryDashboardFormPostsToAnExistingAction(): void
    {
        $src = self::controller();
        preg_match_all('/name="dispatch" value="sphinx_holidays\.([a-z_]+)"/', implode("\n", self::pages()), $m);

        self::assertNotSame([], $m[1]);
        foreach (array_unique($m[1]) as $mode) {
            self::assertStringContainsString("\$mode === '{$mode}'", $src, "the dashboard posts to sphinx_holidays.{$mode}, which nothing handles");
        }
    }
}
