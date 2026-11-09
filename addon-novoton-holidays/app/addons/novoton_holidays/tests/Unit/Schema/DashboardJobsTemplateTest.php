<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder;

/**
 * The dashboard's scheduled-jobs section: Travel Core's shared jobs and
 * commands cards (components/jobs_card.tpl, commands_card.tpl, whose own
 * markup Travel Core's JobsCardTemplateTest checks), fed with Novoton's
 * plan. Here: the cron key is never printed or put in a link, every Run goes
 * to Novoton's own run action, and every label Novoton names exists.
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
            'activity' => self::code('components/activity.tpl'),
        ];
    }

    /** One {include …} of a Travel Core component, whole, even across lines. */
    private static function include(string $component): string
    {
        $manage = self::dashboardTemplates()['manage'];
        self::assertSame(1, preg_match('/\{include file="addons\/travel_core\/components\/' . preg_quote($component, '/') . '\.tpl"[^}]*\}/', $manage, $m), "{$component} is not included once");

        return $m[0];
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

    /** The real commands go to the commands card as values it puts in data-*; the page prints only masked text. */
    public function testTheRealCommandsOnlyRideInDataAttributes(): void
    {
        $commands = self::include('commands_card');
        foreach (['card_id="novoton-crontab"', 'cli=$novoton_crontab_cli', 'url=$novoton_crontab_url', 'masked=$novoton_crontab_masked', 'key=$novoton_cron_key', 'extra=$smarty.capture.novoton_xml_feed'] as $param) {
            self::assertStringContainsString($param, $commands);
        }

        $manage = self::dashboardTemplates()['manage'];
        self::assertStringContainsString('<code class="travel-cron-cmd">{$novoton_xml_feed_masked|escape:html}</code>', $manage);
        self::assertStringContainsString('<button type="button" class="btn travel-cron-copy" data-copy="{$novoton_xml_feed_url|escape:html}">', $manage);
        foreach (['novoton_crontab_cli', 'novoton_crontab_url', 'novoton_cron_key', 'novoton_crontab_masked'] as $var) {
            self::assertStringNotContainsString('{$' . $var, $manage, "{$var} is printed on the page");
        }
        preg_match_all('/\{\$novoton_xml_feed_url\|escape:html\}/', $manage, $m, PREG_OFFSET_CAPTURE);
        self::assertCount(1, $m[0]);
        self::assertMatchesRegularExpression('/data-copy="$/', substr($manage, max(0, $m[0][0][1] - 30), 30));

        // The card prints the feed block as HTML. CS-Cart escapes every {$…}
        // it prints: with a plain {$extra} the block showed as markup on the
        // page, the real key from its data-copy in plain sight.
        $card = (string) file_get_contents(dirname(self::repo()) . '/addon-travel-core/design/backend/templates/addons/travel_core/components/commands_card.tpl');
        self::assertStringContainsString('{if $extra}{$extra nofilter}{/if}', $card);
    }

    /** Every Run on the page — the jobs card's, Needs attention's, Tools' — posts to novoton_holidays.run_job. */
    public function testEveryRunGoesToNovotonsRunAction(): void
    {
        $jobs = self::include('jobs_card');
        foreach (['card_id="novoton-cron-jobs"', 'stages=$novoton_job_stages', 'on_demand=$novoton_on_demand_jobs', 'on_demand_intro=__("novoton_holidays.dash_on_demand_intro")', 'has_key=$novoton_cron_has_key', 'run_dispatch="novoton_holidays.run_job"'] as $param) {
            self::assertStringContainsString($param, $jobs);
        }

        $manage = self::dashboardTemplates()['manage'];
        preg_match_all('/\{include file="addons\/travel_core\/components\/run_job\.tpl"[^}]*\}/', $manage, $runs);
        self::assertCount(2, $runs[0], 'the Needs attention Run and the Tools recompute');
        foreach ($runs[0] as $run) {
            self::assertStringContainsString('run_dispatch="novoton_holidays.run_job"', $run);
        }
        self::assertStringNotContainsString('addons/novoton_holidays/components/run_job.tpl', $manage);
        self::assertStringNotContainsString('addons/novoton_holidays/components/job_row.tpl', $manage);
    }

    public function testTheSectionKeepsItsAnchorAndItsScriptLoadsInsideTheCapture(): void
    {
        $manage = self::tpl('views/novoton_holidays/manage.tpl');

        // Travel Core -> Tools links to #novoton-cron-jobs.
        self::assertStringContainsString('card_id="novoton-cron-jobs"', $manage);

        // The jobs card loads cron-jobs.js itself; included inside the capture,
        // it runs after admin AJAX navigation too.
        $open = strpos($manage, '{capture name="mainbox"}');
        $card = strpos($manage, '{include file="addons/travel_core/components/jobs_card.tpl"');
        $close = strpos($manage, "{/capture}\n\n{capture name=\"buttons\"}");
        self::assertIsInt($open);
        self::assertIsInt($card);
        self::assertIsInt($close);
        self::assertGreaterThan($open, $card);
        self::assertLessThan($close, $card, 'admin AJAX navigation runs only the scripts inside the mainbox capture');

        self::assertFileDoesNotExist(self::repo() . '/js/addons/novoton_holidays/dashboard.js', 'replaced by travel_core/cron-jobs.js');
        self::assertFileDoesNotExist(self::repo() . self::TPL . '/components/job_row.tpl', 'replaced by travel_core/components/job_row.tpl');
        self::assertFileDoesNotExist(self::repo() . self::TPL . '/components/run_job.tpl', 'replaced by travel_core/components/run_job.tpl');
    }

    /**
     * Every Novoton label the page and the plan name exists here; the words
     * for schedules and run states are Travel Core's.
     */
    public function testEveryLabelExists(): void
    {
        /** @var array<string, array<string, string>> $lang */
        $lang = require dirname(__DIR__, 3) . '/lang_keys.php';
        /** @var array<string, array<string, string>> $core */
        $core = require dirname(__DIR__, 7) . '/addon-travel-core/app/addons/travel_core/lang_keys.php';

        $used = [];
        foreach (self::dashboardTemplates() as $src) {
            preg_match_all('/__\("(novoton_holidays\.dash_[a-z0-9_]+)"/', $src, $m);
            $used = [...$used, ...$m[1]];
        }
        $builder = new CronPlanBuilder('http://shop.example/', 'k');
        $modes = [];
        foreach ([...array_merge(...array_values(CronPlanBuilder::STAGES)), ...CronPlanBuilder::ON_DEMAND] as $mode) {
            $modes[$mode] = '';
        }
        $coreKeys = [];
        foreach ($builder->stages($modes, [], [], 0) as $stage) {
            $used[] = $stage['title_key'];
            foreach ($stage['jobs'] as $job) {
                $used[] = (string) $job['name_key'];
                $used[] = (string) $job['desc_key'];
                $coreKeys[] = (string) $job['schedule_key'];
            }
        }
        foreach ($builder->onDemandRows($modes, [], 0) as $job) {
            $used[] = (string) $job['name_key'];
            $used[] = (string) $job['desc_key'];
            $coreKeys[] = (string) $job['schedule_key'];
        }

        foreach (array_unique($used) as $key) {
            self::assertArrayHasKey($key, $lang, "label {$key} is missing from lang_keys.php");
        }
        foreach (array_unique($coreKeys) as $key) {
            self::assertArrayHasKey($key, $core, "schedule label {$key} is missing from Travel Core's lang_keys.php");
        }
        self::assertArrayHasKey('travel_core.jobs_copy_url', $core, 'the XML feed\'s Copy button');
    }
}
