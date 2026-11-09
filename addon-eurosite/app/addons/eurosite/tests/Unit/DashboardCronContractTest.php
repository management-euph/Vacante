<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * The dashboard's scheduled jobs, on Travel Core's shared cards.
 *
 * The page had a table of its own (inline-styled ⋯ menus, "Sync now"), a
 * crontab block with a reveal-the-key toggle, and buttons that minted and
 * rotated the shared cron key. It now shows Travel Core's jobs card and
 * commands card, as Sphinx's and Novoton's dashboards do: commands masked,
 * copied for real, Run as an admin POST to eurosite.run_job that CS-Cart
 * checks with its security_hash, the key minted on Travel Core -> Tools only.
 *
 * CronPlanBuilderTest owns the plan's rules. What is pinned here is the join
 * between the controller, the page and the cards, where a rename goes
 * unnoticed: the page still renders, just emptier.
 *
 * Source-text assertions: the controller and templates run inside CS-Cart,
 * which this repository does not ship.
 */
final class DashboardCronContractTest extends TestCase
{
    private const string ADDON_ROOT = __DIR__ . '/../..';

    private const string TEMPLATES = '/addon-eurosite/design/backend/templates/addons/eurosite';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function controller(): string
    {
        return (string) file_get_contents(self::ADDON_ROOT . '/controllers/backend/eurosite.php');
    }

    /** A template of this add-on without its {* comments *}. */
    private static function template(string $path): string
    {
        $src = (string) file_get_contents(self::repoRoot() . self::TEMPLATES . '/' . $path);

        return (string) preg_replace('/\{\*.*?\*\}/s', '', $src);
    }

    /** @return array<string, string> the dashboard, its part of each job's row and its sync activity */
    private static function pages(): array
    {
        return [
            'manage.tpl' => self::template('views/eurosite/manage.tpl'),
            'job_extra.tpl' => self::template('components/job_extra.tpl'),
            'activity.tpl' => self::template('components/activity.tpl'),
        ];
    }

    /** The body of `if ($mode === '<mode>') {`, up to its closing brace at the same indent. */
    private static function branch(string $opening): string
    {
        $src = self::controller();
        $start = strpos($src, $opening);
        self::assertIsInt($start, "no {$opening}");
        $indent = strspn(substr($src, (int) strrpos(substr($src, 0, $start), "\n") + 1), ' ');
        $end = strpos($src, "\n" . str_repeat(' ', $indent) . "}\n", $start);
        self::assertIsInt($end);

        return substr($src, $start, $end - $start);
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

    public function testRunJobChecksPermissionAndRunsOnlyKnownJobsWithWhatThePlanAllows(): void
    {
        $body = self::branch("    if (\$mode === 'run_job') {");

        self::assertStringContainsString("fn_check_permissions('manage_catalog', 'update', 'admin')", $body);
        self::assertStringContainsString("preg_replace('/[^a-z0-9_]/', ''", $body);
        self::assertStringContainsString('array_key_exists($job, CronDispatcher::getAvailableModes())', $body);
        self::assertStringContainsString("\$params = CronPlanBuilder::runParams(\$job, RequestCoerce::string(\$_REQUEST, 'job_action'));", $body);
        self::assertStringContainsString('CronRunOutput::dispatch(new CronDispatcher(), $job, $params);', $body);
        self::assertStringNotContainsString('toStringMap($_REQUEST)', $body, 'the request reaches the job only through runParams()');
        self::assertStringNotContainsString('access_key', $body);
        self::assertStringNotContainsString('RequestPacer', $body, 'only a scheduled run is paced');
        self::assertStringContainsString("header('Content-Type: text/plain; charset=utf-8');", $body);
        self::assertStringContainsString("echo CronRunOutput::startLine('Eurosite', \$job);", $body);
    }

    /** The URL and the dashboard print a run the same way. */
    public function testThePublicCronEndpointPrintsThroughTheSameOutput(): void
    {
        $cron = (string) file_get_contents(self::ADDON_ROOT . '/controllers/frontend/eurosite_cron.php');

        self::assertStringContainsString("echo CronRunOutput::startLine('Eurosite', \$mode);", $cron);
        self::assertStringContainsString('CronRunOutput::dispatch($dispatcher, $mode, TypeCoerce::toStringMap($_REQUEST));', $cron);
        self::assertStringNotContainsString("echo 'Stats: '", $cron, 'a second copy of the printer drifts');
    }

    /** The shared key is minted on Travel Core -> Tools, for every provider. */
    public function testTheDashboardNoLongerMintsTheKey(): void
    {
        self::assertStringNotContainsString("\$mode === 'generate_cron_key'", self::controller());
        self::assertStringNotContainsString('CronKeyService', self::controller());
        foreach (self::pages() as $name => $tpl) {
            self::assertStringNotContainsString('generate_cron_key', $tpl, $name);
        }
    }

    public function testNoPagePrintsTheKeyOrACronUrl(): void
    {
        foreach (self::pages() as $name => $tpl) {
            self::assertStringNotContainsString('access_key', $tpl, $name);
            self::assertStringNotContainsString('eurosite_cron.run', $tpl, $name);
            self::assertStringNotContainsString('cron_mode=', $tpl, $name);
            self::assertStringNotContainsString('{$eurosite_cron_key', $tpl, $name . ': the key only reaches the commands card, which masks it');
        }
    }

    /** Travel Core's cards, given Eurosite's rows, plans and run action. */
    public function testTheDashboardShowsTravelCoresCards(): void
    {
        $tpl = self::pages()['manage.tpl'];

        self::assertMatchesRegularExpression(
            '/\{include file="addons\/travel_core\/components\/jobs_card\.tpl"\s+card_id="eurosite-scheduled-jobs"\s+stages=\$eurosite_job_stages\s+on_demand=\$eurosite_on_demand_jobs\s+on_demand_intro=__\("eurosite\.jobs_on_demand_intro"\)\s+has_key=\$eurosite_cron_has_key\s+run_dispatch="eurosite\.run_job"\s+row_extra="addons\/eurosite\/components\/job_extra\.tpl"\s*\}/',
            $tpl,
        );
        self::assertMatchesRegularExpression(
            '/\{if \$eurosite_cron_has_key\}\s*\{include file="addons\/travel_core\/components\/commands_card\.tpl"\s+card_id="eurosite-crontab"\s+plans=\$eurosite_cron_plans\s+key=\$eurosite_cron_key\s*\}\s*\{\/if\}/',
            $tpl,
        );
        self::assertFileExists(self::repoRoot() . self::TEMPLATES . '/components/job_extra.tpl');
    }

    /** Every variable the page reads is assigned by the manage branch. */
    public function testTheControllerHandsThePageItsData(): void
    {
        $manage = self::branch("if (\$mode === 'manage' || empty(\$mode)) {");
        preg_match_all('/\$(eurosite_[a-z_]+)/', implode("\n", self::pages()), $m);

        foreach (array_unique($m[1]) as $var) {
            self::assertStringContainsString("\$view->assign('{$var}', ", $manage, "{$var} is read by the page but never assigned");
        }
        self::assertStringContainsString('new CronPlanBuilder($baseUrl, $cronKey, ', $manage);
        self::assertStringContainsString('CronRunLog::get(CronPlanBuilder::ADDON, $cronMode)', $manage);
        self::assertStringContainsString('CronPlanBuilder::legacyRuns($syncLog->getLastPerType())', $manage);
        self::assertStringContainsString('$jobStages = CronPlanBuilder::withCounts($plan->stages($cronModes, $cronRecords, $legacyRuns, time()), $counts);', $manage);
        self::assertStringContainsString("\$view->assign('eurosite_job_stages', \$jobStages);", $manage);

        // The schedule table and the URL shape belong to the plan: a second
        // copy in the controller is how the two drift apart.
        self::assertStringNotContainsString('dispatch=eurosite_cron.run', $manage);
        self::assertStringNotContainsString('$lastSyncs', $manage);
    }

    /**
     * Both plans reach the card, the recommended one first, each with its
     * name, its hint and its commands, shown masked.
     */
    public function testBothPlansReachTheCommandsCard(): void
    {
        $manage = self::branch("if (\$mode === 'manage' || empty(\$mode)) {");

        self::assertStringContainsString('foreach (CronPlanBuilder::PLANS as $planKey) {', $manage);
        foreach ([
            "'key' => \$planKey,",
            "'label' => __('eurosite.jobs_plan_' . \$planKey),",
            "'hint' => __('eurosite.jobs_plan_' . \$planKey . '_hint'),",
            "'cli' => \$planCli,",
            "'url' => \$plan->crontabFor(\$planKey, \$cronModes, 'url', \$generatedOn),",
            "'masked' => \$cronKey === '' ? \$planCli : str_replace(\$cronKey, '••••••••', \$planCli),",
        ] as $field) {
            self::assertStringContainsString($field, $manage);
        }
        self::assertStringContainsString("\$planCli = \$plan->crontabFor(\$planKey, \$cronModes, 'cli', \$generatedOn);", $manage);
        self::assertStringContainsString("\$view->assign('eurosite_cron_plans', \$cronPlans);", $manage);
    }

    /**
     * Under each job's name, what is Eurosite's own: its catalog's size, the
     * hotel list, the probe beside the availability check, and the two states
     * the page can diagnose by itself.
     */
    public function testEachRowSaysWhatIsEurositesOwn(): void
    {
        $extra = self::pages()['job_extra.tpl'];

        self::assertStringContainsString('{if isset($job.count)}', $extra);
        self::assertStringContainsString('{__($job.count_key, ["[n]" => $job.count])}', $extra);
        self::assertStringContainsString('{if $job.mode == "hotels" || $job.mode == "availability" || $job.mode == "add_products"}', $extra);
        self::assertStringContainsString('{"eurosite.hotels"|fn_url}', $extra);
        self::assertStringContainsString('<a href="#eurosite-probe">', $extra);
        self::assertStringContainsString('id="eurosite-probe"', self::pages()['manage.tpl'], 'the probe link lands on the page');

        // No Immediate hotel on the configured dates: the probe says which dates would.
        self::assertStringContainsString('{if $job.mode == "availability" && isset($job.count) && $job.count == 0}', $extra);
        self::assertStringContainsString('{__("eurosite.probe_none_hint")}', $extra);
        // The hotels job reads the whitelist, and the whitelist is empty.
        self::assertStringContainsString('{if $job.mode == "hotels" && $eurosite_counts.whitelist == 0}', $extra);
        self::assertStringContainsString('{__("eurosite.hotels_need_whitelist")}', $extra);
        self::assertStringContainsString('{"eurosite.whitelist"|fn_url}', $extra);
        self::assertStringNotContainsString('style="', $extra, 'Travel Core\'s hint classes, not inline styles');
    }

    /** The old page's script and table are gone: Travel Core's cards bring their own. */
    public function testTheOldTableAndScriptAreGone(): void
    {
        $tpl = self::pages()['manage.tpl'];

        self::assertFileDoesNotExist(self::repoRoot() . '/addon-eurosite/js/addons/eurosite/dashboard.js');
        self::assertStringNotContainsString('dashboard.js', $tpl);
        foreach (['eurosite-menu', 'eurosite-copy', 'eurosite_cron_rows', 'eurosite_crontabs_json', 'eurosite.run_sync', 'onclick='] as $old) {
            self::assertStringNotContainsString($old, $tpl, $old);
        }
        foreach (['eurosite_cron_rows', 'eurosite_crontabs_json', 'eurosite_cron_urls', 'eurosite_sync_modes'] as $var) {
            self::assertStringNotContainsString("assign('{$var}'", self::controller(), $var);
        }
    }

    /** Every form on the dashboard goes to an action the controller has. */
    public function testEveryDashboardFormGoesToAnExistingAction(): void
    {
        preg_match_all('/name="dispatch" value="eurosite\.([a-z_]+)"/', implode("\n", self::pages()), $m);

        self::assertNotSame([], $m[1]);
        foreach (array_unique($m[1]) as $mode) {
            self::assertStringContainsString("\$mode === '{$mode}'", self::controller(), "the dashboard posts to eurosite.{$mode}, which nothing handles");
        }
    }

    /** Every label the page, its row part and the plan's rows name exists, in both languages. */
    public function testEveryLabelExists(): void
    {
        /** @var array<string, array<string, string>> $lang */
        $lang = require self::ADDON_ROOT . '/lang_keys.php';

        $keys = [];
        preg_match_all('/__\("(eurosite\.[a-z0-9_]+)"/', implode("\n", self::pages()), $m);
        $keys = [...$keys, ...$m[1]];
        foreach (CronPlanBuilder::STAGES as $stage => $jobs) {
            $keys[] = 'eurosite.jobs_stage_' . $stage;
            foreach ($jobs as $job) {
                $keys[] = 'eurosite.jobs_job_' . $job;
                $keys[] = 'eurosite.jobs_job_' . $job . '_desc';
            }
        }
        foreach (CronPlanBuilder::PLANS as $plan) {
            $keys[] = 'eurosite.jobs_plan_' . $plan;
            $keys[] = 'eurosite.jobs_plan_' . $plan . '_hint';
        }
        foreach (['countries', 'own_cities', 'cities', 'hotels', 'room_types', 'tags', 'package_routes', 'cache', 'immediate', 'products'] as $count) {
            $keys[] = 'eurosite.jobs_count_' . $count;
        }
        $keys = [...$keys, 'eurosite.jobs_full_force', 'eurosite.jobs_full_force_confirm', 'eurosite.jobs_unknown'];

        foreach (array_unique($keys) as $key) {
            self::assertArrayHasKey($key, $lang, "label {$key} is missing from lang_keys.php");
            self::assertNotSame('', $lang[$key]['en'] ?? '', $key);
            self::assertNotSame('', $lang[$key]['ro'] ?? '', $key);
        }
    }

    /**
     * The page reads as Sphinx's and Novoton's: at a glance, what needs
     * attention, the tools, the destinations and availability, the jobs and
     * their commands, recent sync activity, then recent bookings.
     */
    public function testTheSectionsComeInTheProvidersOrder(): void
    {
        $tpl = self::pages()['manage.tpl'];
        $at = [];
        foreach ([
            'glance' => '<div class="travel-cron-tiles travel-cron-tiles--four">',
            'attention' => '<section class="travel-cron-card travel-cron-attention"',
            'tools' => '<section class="travel-cron-card travel-cron-tools"',
            'destinations' => 'components/destinations_card.tpl',
            'availability' => 'components/availability_card.tpl',
            'jobs' => 'components/jobs_card.tpl',
            'commands' => 'components/commands_card.tpl',
            'activity' => '{include file="addons/eurosite/components/activity.tpl"}',
            'bookings' => 'aria-labelledby="eurosite-bookings-title"',
        ] as $name => $marker) {
            $pos = strpos($tpl, $marker);
            self::assertIsInt($pos, "no {$name} section");
            $at[$name] = $pos;
        }
        $sorted = $at;
        asort($sorted);
        self::assertSame(array_keys($at), array_keys($sorted));
    }

    /** At a glance and Needs attention come from Services\DashboardSummary, fed by the manage branch. */
    public function testTheTopOfThePageComesFromTheSummary(): void
    {
        $manage = self::branch("if (\$mode === 'manage' || empty(\$mode)) {");
        self::assertStringContainsString("\$view->assign('eurosite_glance', DashboardSummary::glance(\$destBuilt, \$hotelFigures, \$jobStages));", $manage);
        self::assertStringContainsString('CronHealth::assess($cronRecords, CronRunLog::lastWrongKeyRefusal(CronPlanBuilder::ADDON), time())', $manage);
        self::assertStringContainsString("\$view->assign('eurosite_attention', DashboardSummary::attention(", $manage);
        self::assertStringContainsString("'immediate_without' => \$hotelRepo->countImmediateWithoutProduct(),", $manage);

        $tpl = self::pages()['manage.tpl'];
        self::assertSame(3, preg_match_all('/<div class="travel-cron-tile(?:"|\{)/', $tpl), 'three of Eurosite\'s own');
        self::assertStringContainsString('{include file="addons/travel_core/components/jobs_tile.tpl" health=$_g.jobs card_id="eurosite-scheduled-jobs"}', $tpl, 'and Travel Core\'s jobs tile');
        // Every kind DashboardSummary::attention() gives has its line and its one action.
        foreach (['refused' => 'href="#eurosite-crontab"', 'nothing_sold' => 'href="{"eurosite.whitelist"|fn_url}"', 'no_immediate' => 'href="#eurosite-probe"'] as $kind => $action) {
            $branch = substr($tpl, (int) strpos($tpl, '$item.kind == "' . $kind . '"'), 400);
            self::assertStringContainsString('{__("eurosite.dash_attention_' . $kind . '"', $branch, $kind);
            self::assertStringContainsString($action, $branch, $kind);
        }
        foreach (['failed', 'stalled', 'never', 'late'] as $kind) {
            self::assertStringContainsString('{__("eurosite.dash_attention_' . $kind . '", ["[job]" => $_name', $tpl, $kind);
        }
        self::assertStringContainsString('{include file="addons/travel_core/components/run_job.tpl" run_dispatch="eurosite.run_job" job=$item.mode', $tpl, 'a job\'s line runs it as the jobs card does');
    }

    /** The Tools card: the API connection and the probe; every POST carries CS-Cart's CSRF token. */
    public function testTheToolsCardHoldsTheApiConnectionAndTheProbe(): void
    {
        $tpl = self::pages()['manage.tpl'];
        $tools = substr($tpl, (int) strpos($tpl, '<section class="travel-cron-card travel-cron-tools"'));
        $tools = substr($tools, 0, (int) strpos($tools, '</section>'));

        self::assertStringContainsString('value="eurosite.test_connection"', $tools);
        self::assertStringContainsString('id="eurosite-probe"', $tools);
        self::assertStringContainsString('{$eurosite_api_url|escape:html}', $tools);

        preg_match_all('/<form\b[^>]*method="post"[^>]*>.*?<\/form>/s', $tpl, $posts);
        self::assertNotEmpty($posts[0]);
        foreach ($posts[0] as $form) {
            self::assertStringContainsString('name="security_hash" value="{$security_hash}"', $form);
        }
        self::assertStringNotContainsString('style="', $tpl, 'Travel Core\'s card classes, not inline styles');
    }

    /**
     * Recent sync activity is one paged list: CS-Cart's pagination include
     * comes as a pair around it, read from $search, which the manage branch
     * assigns from Services\SyncActivity, as the All / Problems buttons'
     * counts.
     */
    public function testTheSyncActivityIsOnePagedList(): void
    {
        $activity = self::pages()['activity.tpl'];
        self::assertSame(2, substr_count($activity, '{include file="common/pagination.tpl" save_current_page=true}'));
        self::assertStringContainsString('<section id="eurosite-activity"', $activity);
        self::assertStringContainsString('{"eurosite.manage?activity=problems"|fn_url}#eurosite-activity', $activity);
        foreach (['ok', 'partial', 'failed', 'stalled', 'running'] as $state) {
            self::assertStringContainsString('$run.state == "' . $state . '"', $activity, $state);
        }
        self::assertStringContainsString('{$run.error|escape:html}', $activity);
        self::assertStringContainsString('{$run.error_full|escape:html}', $activity);

        $manage = self::branch("if (\$mode === 'manage' || empty(\$mode)) {");
        self::assertStringContainsString('$activity = SyncActivity::none();', $manage, 'a log that cannot be read still renders the card');
        self::assertStringContainsString('$activity = SyncActivity::page($syncLog, $_REQUEST, time());', $manage);
        self::assertStringContainsString("\$view->assign('search', \$activity['search']);", $manage);
    }

    public function testTheWrapperDivIsClosed(): void
    {
        // The page wrapper went unclosed for the life of this template; the
        // browser recovered by closing it at the end of the capture, which
        // also swallows anything appended after it.
        $tpl = self::pages()['manage.tpl'];

        self::assertSame(substr_count($tpl, '<div'), substr_count($tpl, '</div>'), 'unbalanced <div> in manage.tpl');
    }
}
