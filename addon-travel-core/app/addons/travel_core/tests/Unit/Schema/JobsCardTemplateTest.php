<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The scheduled-jobs cards every provider's dashboard includes
 * (components/jobs_card.tpl, job_row.tpl, run_job.tpl, commands_card.tpl):
 * the cron key is never printed or put in a link, every action is a
 * protected POST to the add-on's own run action, every state is worded,
 * and every label exists.
 *
 * Source-text assertions: the templates render inside CS-Cart's Smarty,
 * which this repository does not ship.
 */
final class JobsCardTemplateTest extends TestCase
{
    private const string COMPONENTS = '/design/backend/templates/addons/travel_core/components/';

    private static function repo(): string
    {
        return dirname(__DIR__, 6);
    }

    /** The template without Smarty comments, which may quote what the code must not do. */
    private static function code(string $name): string
    {
        $path = self::repo() . self::COMPONENTS . $name . '.tpl';
        self::assertFileExists($path);

        return (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($path));
    }

    /** @return array<string, string> */
    private static function templates(): array
    {
        return [
            'jobs_card' => self::code('jobs_card'),
            'job_row' => self::code('job_row'),
            'run_job' => self::code('run_job'),
            'commands_card' => self::code('commands_card'),
        ];
    }

    public function testNoLinkOrTextCarriesTheCronKey(): void
    {
        foreach (self::templates() as $name => $src) {
            self::assertStringNotContainsString('access_key', $src, "{$name}: the key must not be in the markup");
            self::assertStringNotContainsString('_cron.run', $src, "{$name}: no link to a public cron URL");
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $src, "{$name}: no inline event handlers");
            self::assertDoesNotMatchRegularExpression('/<a\b[^>]*\{\$(job|cli|url|key)/', $src, "{$name}: no command in a link");
        }
    }

    /** Every place the real commands reach the page is an attribute Copy reads, never visible text. */
    public function testTheRealCommandsOnlyRideInDataAttributes(): void
    {
        $row = self::templates()['job_row'];
        foreach (['$job.crontab_line', '$job.url', '$job.cli'] as $real) {
            self::assertStringContainsString('data-copy="{' . $real . '|escape:html}"', $row);
            self::assertSame(1, substr_count($row, '{' . $real), "{$real} appears outside its data-copy");
        }

        $card = self::templates()['commands_card'];
        self::assertStringContainsString('data-crontab-cli="{$cli|escape:html}"', $card);
        self::assertStringContainsString('data-crontab-url="{$url|escape:html}"', $card);
        self::assertStringContainsString('data-key="{$key|escape:html}"', $card);
        self::assertStringContainsString('<pre class="travel-cron-commands__text" data-travel-cron-text>{$masked|escape:html}</pre>', $card);
        preg_match_all('/\{\$(cli|url|key)\|escape:html\}/', $card, $m, PREG_OFFSET_CAPTURE);
        self::assertCount(3, $m[0]);
        foreach ($m[0] as [$use, $at]) {
            $before = substr($card, max(0, $at - 30), 30);
            self::assertMatchesRegularExpression('/data-[a-z-]+="$/', $before, "{$use} is printed outside a data-* attribute");
        }
    }

    /** Run, Check status, Force full sync, Redo all and Reset are POSTs with CS-Cart's CSRF token, to the add-on's run action. */
    public function testEveryActionIsAProtectedPostToTheAddonsRunAction(): void
    {
        foreach (self::templates() as $name => $src) {
            preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $src, $forms);
            foreach ($forms[0] as $form) {
                self::assertMatchesRegularExpression('/method="post"/', $form, "{$name}: a form is not a POST");
                self::assertStringContainsString('name="security_hash"', $form, "{$name}: a POST form without security_hash");
            }
        }

        $run = self::templates()['run_job'];
        self::assertStringContainsString('<input type="hidden" name="dispatch" value="{$run_dispatch|escape:html}" />', $run);
        self::assertStringContainsString('name="security_hash" value="{$security_hash}"', $run);
        self::assertStringContainsString('<input type="hidden" name="job" value="{$job|escape:html}" />', $run);
        self::assertStringContainsString('data-travel-cron-confirm="{$confirm|escape:html}"', $run);

        $row = self::templates()['job_row'];
        preg_match_all('/\{include file="addons\/travel_core\/components\/run_job\.tpl"[^}]*\}/', $row, $includes);
        self::assertCount(6, $includes[0], 'Run, Check status, Redo all, Force full sync, the add-on\'s own items, Reset');
        foreach ($includes[0] as $include) {
            self::assertStringContainsString('run_dispatch=$run_dispatch', $include);
        }
        self::assertStringContainsString('job=$job.mode label=__("travel_core.jobs_run")', $row);
        foreach (['status', 'force_full', 'reset', 'force'] as $action) {
            self::assertStringContainsString('action="' . $action . '"', $row);
        }
        // The ones that undo or redo work ask first.
        self::assertMatchesRegularExpression('/action="force_full"[^}]*confirm=__\("travel_core\.jobs_force_full_confirm"/', $row);
        self::assertMatchesRegularExpression('/action="reset"[^}]*confirm=__\("travel_core\.jobs_reset_confirm"/', $row);
        self::assertMatchesRegularExpression('/action="force"[^}]*confirm=__\("travel_core\.jobs_redo_all_confirm"/', $row);

        $card = self::templates()['jobs_card'];
        self::assertSame(2, substr_count($card, '{include file="addons/travel_core/components/job_row.tpl" job=$job has_key=$has_key run_dispatch=$run_dispatch}'), 'the staged rows and the on-demand rows');
    }

    /** Each menu item shows only for a job that answers it (CronJobPlan's row flags), the add-on's own items included. */
    public function testEachMenuItemShowsOnlyForAJobThatAnswersIt(): void
    {
        $row = self::templates()['job_row'];

        foreach (['has_status' => 'status', 'redo_all' => 'force', 'force_full' => 'force_full', 'resettable' => 'reset'] as $flag => $action) {
            self::assertMatchesRegularExpression('/\{if \$job\.' . $flag . '\}\s*\{include file="addons\/travel_core\/components\/run_job\.tpl"[^}]*action="' . $action . '"/', $row, "{$action} is not behind {$flag}");
        }
        self::assertStringContainsString('{foreach from=$job.extras item=_extra}', $row);
        self::assertMatchesRegularExpression('/action=\$_extra\.action label=__\(\$_extra\.label\)[^}]*confirm=\$_confirm\}/', $row);
        self::assertStringContainsString('{if isset($_extra.confirm)}{$_confirm = __($_extra.confirm, ["[job]" => $_job_name])}{/if}', $row);
        self::assertStringContainsString('{if $job.has_status || $job.redo_all || $job.force_full || $job.resettable || $job.extras}', $row, 'a divider before any action item');
    }

    /** Text that comes from a job or the API is printed escaped; the add-on's labels come from its language packs. */
    public function testRunErrorsAndDescriptionsAreEscaped(): void
    {
        $row = self::templates()['job_row'];

        self::assertStringContainsString('{$job.error|escape:html}', $row);
        self::assertStringContainsString('{$job.description|escape:html}', $row);
        self::assertDoesNotMatchRegularExpression('/\{\$job\.(error|description)\}/', $row);
        self::assertStringContainsString('{$_job_name = __($job.name_key)}', $row);
        self::assertStringContainsString('{__($job.desc_key)}', $row);
        self::assertStringContainsString('{__($stage.title_key)}', self::templates()['jobs_card']);
    }

    /** Every state CronJobPlan::health() returns has its own worded pill. */
    public function testEveryRunStateHasAWordedPill(): void
    {
        $row = self::templates()['job_row'];

        foreach (['ok', 'late', 'failed', 'running', 'stalled'] as $state) {
            self::assertStringContainsString('$job.state == "' . $state . '"', $row);
            self::assertStringContainsString('__("travel_core.jobs_state_' . $state . '")', $row);
        }
        self::assertStringContainsString('__("travel_core.jobs_state_never")', $row);
    }

    /** Every label the cards use exists, in both languages. */
    public function testEveryLabelExists(): void
    {
        /** @var array<string, array<string, string>> $lang */
        $lang = require dirname(__DIR__, 3) . '/lang_keys.php';

        $used = [];
        foreach (self::templates() as $src) {
            preg_match_all('/__\("(travel_core\.[a-z0-9_]+)"/', $src, $m);
            $used = [...$used, ...$m[1]];
        }
        self::assertContains('travel_core.jobs_copy_url', $used);
        foreach (array_unique($used) as $key) {
            self::assertArrayHasKey($key, $lang, "label {$key} is missing from lang_keys.php");
            self::assertNotSame('', $lang[$key]['en'] ?? '', $key);
            self::assertNotSame('', $lang[$key]['ro'] ?? '', $key);
        }
    }

    /**
     * The cards load the script that drives them, and its words: inside the
     * page's capture, so admin AJAX navigation runs it too.
     */
    public function testTheCardsLoadTheirScriptAndCarryItsWords(): void
    {
        self::assertFileExists(self::repo() . '/js/addons/travel_core/cron-jobs.js');
        foreach (['jobs_card', 'commands_card'] as $name) {
            $src = self::templates()[$name];
            self::assertStringContainsString('{script src="js/addons/travel_core/cron-jobs.js"}', $src, $name);
            self::assertStringContainsString('data-txt-copied="{__("travel_core.jobs_copied")|escape:html}"', $src, $name);
            self::assertStringContainsString('data-txt-copy-failed="{__("travel_core.jobs_copy_failed")|escape:html}"', $src, $name);
        }
        self::assertStringContainsString('<section id="{$card_id}"', self::templates()['jobs_card'], 'the anchor other pages link to');
    }

    /**
     * An open ⋯ menu is lifted out of the jobs card: the card scrolls
     * sideways, which also clips downwards, and cut off the menus of its last
     * rows. Pinned to the window, it must sit above the page.
     */
    public function testAnOpenMenuIsPinnedAboveThePage(): void
    {
        $css = (string) file_get_contents(self::repo() . '/design/backend/css/addons/travel_core/admin_styles.css');
        self::assertMatchesRegularExpression('/\.travel-cron-card--flush\s*\{[^}]*overflow-x:\s*auto/', $css, 'the card the menus sit in scrolls sideways');
        self::assertMatchesRegularExpression('/\.travel-cron-menu--floating\s*\{[^}]*position:\s*fixed;[^}]*z-index:\s*1000;/', $css);

        $js = (string) file_get_contents(self::repo() . '/js/addons/travel_core/cron-jobs.js');
        self::assertStringContainsString("document.body.appendChild(menu)", $js);
        self::assertStringContainsString("menu.classList.add('travel-cron-menu--floating')", $js);
    }
}
