<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder;

/**
 * The dashboard's scheduled-jobs section: the cron key is never printed or
 * put in a link, every action is a protected POST, and every label it uses
 * exists in both language packs.
 *
 * Source-text assertions: the templates render inside CS-Cart's Smarty,
 * which this repository does not ship.
 */
final class DashboardJobsTemplateTest extends TestCase
{
    private const string TPL = '/design/backend/templates/addons/novoton_holidays';

    private static function repo(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function tpl(string $path): string
    {
        return (string) file_get_contents(self::repo() . self::TPL . '/' . $path);
    }

    /** The template without Smarty comments, which may quote what the code must not do. */
    private static function code(string $path): string
    {
        return (string) preg_replace('/\{\*.*?\*\}/s', '', self::tpl($path));
    }

    /** @return array<string, string> */
    private static function dashboardTemplates(): array
    {
        return [
            'manage' => self::code('views/novoton_holidays/manage.tpl'),
            'job_row' => self::code('components/job_row.tpl'),
            'run_job' => self::code('components/run_job.tpl'),
        ];
    }

    public function testNoLinkOrTextCarriesTheCronKey(): void
    {
        foreach (self::dashboardTemplates() as $name => $src) {
            self::assertStringNotContainsString('access_key', $src, "{$name}: the key must not be in the markup");
            self::assertStringNotContainsString('novoton_cron.run', $src, "{$name}: no link to the public cron URL");
            self::assertStringNotContainsString('$cron_urls', $src, "{$name}: the old key-carrying URL list is gone");
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $src, "{$name}: no inline event handlers");
        }

        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_holidays.php');
        self::assertStringNotContainsString("assign('cron_urls'", $controller);
        // What IS printed is masked; the real text only rides in data-* for Copy.
        self::assertStringContainsString("\$view->assign('novoton_crontab_masked', \$mask(\$crontab_cli));", $controller);
        self::assertStringContainsString("\$view->assign('novoton_xml_feed_masked', \$mask(\$xml_feed_url));", $controller);
    }

    /** Every place the real key reaches the page is an attribute Copy reads, never visible text. */
    public function testTheRealCommandsOnlyRideInDataAttributes(): void
    {
        $manage = self::dashboardTemplates()['manage'];
        self::assertStringContainsString('<pre id="novoton-crontab-text" class="novoton-crontab__text">{$novoton_crontab_masked|escape:html}</pre>', $manage);
        self::assertStringContainsString('data-crontab-cli="{$novoton_crontab_cli|escape:html}"', $manage);
        self::assertStringContainsString('<code class="travel-cron-cmd">{$novoton_xml_feed_masked|escape:html}</code>', $manage);

        preg_match_all('/\{\$(novoton_crontab_cli|novoton_crontab_url|novoton_cron_key|novoton_xml_feed_url)\|escape:html\}/', $manage, $m, PREG_OFFSET_CAPTURE);
        self::assertNotSame([], $m[0]);
        foreach ($m[0] as [$use, $at]) {
            $before = substr($manage, max(0, $at - 30), 30);
            self::assertMatchesRegularExpression('/data-[a-z-]+="$/', $before, "{$use} is printed outside a data-* attribute");
        }

        $row = self::dashboardTemplates()['job_row'];
        foreach (['$job.crontab_line', '$job.url', '$job.cli'] as $real) {
            self::assertStringContainsString('data-copy="{' . $real . '|escape:html}"', $row);
            self::assertSame(1, substr_count($row, '{' . $real), "{$real} appears outside its data-copy");
        }
    }

    /** Run, Check status, Force full sync and Reset are POSTs with CS-Cart's CSRF token. */
    public function testEveryActionIsAProtectedPost(): void
    {
        foreach (self::dashboardTemplates() as $name => $src) {
            preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $src, $forms);
            foreach ($forms[0] as $form) {
                self::assertMatchesRegularExpression('/method="post"/', $form, "{$name}: a form is not a POST");
                self::assertStringContainsString('name="security_hash"', $form, "{$name}: a POST form without security_hash");
            }
        }

        $row = self::dashboardTemplates()['job_row'];
        self::assertStringContainsString('components/run_job.tpl" job=$job.mode label=__("novoton_holidays.dash_run")', $row);
        foreach (['status', 'force_full', 'reset'] as $action) {
            self::assertStringContainsString('action="' . $action . '"', $row);
        }
        // The two that undo or redo work ask first.
        self::assertMatchesRegularExpression('/action="force_full"[^}]*confirm=__\("novoton_holidays\.dash_force_full_confirm"/', $row);
        self::assertMatchesRegularExpression('/action="reset"[^}]*confirm=__\("novoton_holidays\.dash_reset_confirm"/', $row);
        self::assertStringContainsString('data-novoton-confirm="{$confirm|escape:html}"', self::dashboardTemplates()['run_job']);
    }

    public function testTheSectionKeepsItsAnchorAndLoadsItsScriptInsideTheCapture(): void
    {
        $manage = self::tpl('views/novoton_holidays/manage.tpl');

        // Travel Core -> Tools links to #novoton-cron-jobs.
        self::assertStringContainsString('id="novoton-cron-jobs"', $manage);

        $open = strpos($manage, '{capture name="mainbox"}');
        $script = strpos($manage, '{script src="js/addons/novoton_holidays/dashboard.js"}');
        $close = strpos($manage, '{/capture}');
        self::assertIsInt($open);
        self::assertIsInt($script);
        self::assertIsInt($close);
        self::assertGreaterThan($open, $script);
        self::assertLessThan($close, $script, 'admin AJAX navigation runs only the scripts inside the mainbox capture');
    }

    /** Text that comes from a job or the API is printed escaped. */
    public function testRunErrorsAndDescriptionsAreEscaped(): void
    {
        $row = self::dashboardTemplates()['job_row'];

        self::assertStringContainsString('{$job.error|escape:html}', $row);
        self::assertStringContainsString('{$job.description|escape:html}', $row);
        self::assertDoesNotMatchRegularExpression('/\{\$job\.(error|description)\}/', $row);
    }

    /** Every label the section uses, and every job/stage/schedule label the builder can ask for, exists. */
    public function testEveryLabelExists(): void
    {
        /** @var array<string, array<string, string>> $lang */
        $lang = require dirname(__DIR__, 3) . '/lang_keys.php';

        $used = [];
        foreach (self::dashboardTemplates() as $src) {
            preg_match_all('/__\("(novoton_holidays\.dash_[a-z0-9_]+)"/', $src, $m);
            $used = [...$used, ...$m[1]];
        }
        foreach (array_keys(CronPlanBuilder::STAGES) as $stage) {
            $used[] = 'novoton_holidays.dash_stage_' . $stage;
        }
        foreach ([...array_merge(...array_values(CronPlanBuilder::STAGES)), ...CronPlanBuilder::ON_DEMAND] as $mode) {
            $used[] = 'novoton_holidays.dash_job_' . $mode;
            $used[] = 'novoton_holidays.dash_job_' . $mode . '_desc';
            $used[] = CronPlanBuilder::scheduleWords(CronPlanBuilder::cron($mode) ?: 'x')['key'];
        }
        foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            $used[] = 'novoton_holidays.dash_sched_weekly_' . $day;
        }
        foreach (['ok', 'late', 'failed', 'running', 'stalled', 'never'] as $state) {
            $used[] = 'novoton_holidays.dash_run_' . $state;
        }

        foreach (array_unique($used) as $key) {
            self::assertArrayHasKey($key, $lang, "label {$key} is missing from lang_keys.php");
        }
    }

    /** Every state the builder can return has its own pill. */
    public function testEveryRunStateHasAWordedPill(): void
    {
        $row = self::dashboardTemplates()['job_row'];

        foreach (['ok', 'late', 'failed', 'running', 'stalled'] as $state) {
            self::assertStringContainsString('$job.state == "' . $state . '"', $row);
            self::assertStringContainsString('__("novoton_holidays.dash_run_' . $state . '")', $row);
        }
        self::assertStringContainsString('__("novoton_holidays.dash_run_never")', $row);
    }
}
