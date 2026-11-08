<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The top of Sphinx's dashboard: At a glance, Needs attention and the Tools
 * card, on Travel Core's travel-cron styles as Novoton's dashboard is.
 *
 * They replace eleven coloured stat boxes that never said which figure was a
 * problem, product buttons scattered under them, and a row of links the
 * admin menu already has.
 *
 * Source-text assertions: the controller and templates run inside CS-Cart,
 * which this repository does not ship.
 */
final class DashboardPageTest extends TestCase
{
    /** manage.tpl without its {* comments *}. */
    private static function page(): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 6) . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/manage.tpl');

        return (string) preg_replace('/\{\*.*?\*\}/s', '', $src);
    }

    /** @return string the manage branch of the controller */
    private static function manage(): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php');
        $start = strpos($src, "if (\$mode === 'manage') {");
        self::assertIsInt($start);
        $end = strpos($src, "} elseif (\$mode === '", $start);
        self::assertIsInt($end);

        return substr($src, $start, $end - $start);
    }

    /** The section a marker opens, up to the next one. */
    private static function between(string $from, string $to): string
    {
        $page = self::page();
        $start = strpos($page, $from);
        self::assertIsInt($start, $from);
        $end = strpos($page, $to, $start);
        self::assertIsInt($end, $to);

        return substr($page, $start, $end - $start);
    }

    public function testTheSectionsComeInTheProposedOrder(): void
    {
        $page = self::page();
        $at = [];
        foreach ([
            'glance' => 'travel-cron-tiles travel-cron-tiles--four',
            'attention' => 'travel-cron-card travel-cron-attention',
            'tools' => 'travel-cron-card travel-cron-tools',
            'destinations' => 'components/destinations_card.tpl',
            'availability' => 'components/availability_card.tpl',
            'jobs' => 'components/jobs_card.tpl',
            'scope' => 'components/image_scope.tpl',
            'commands' => 'components/commands_card.tpl',
            'activity' => 'components/activity.tpl',
        ] as $section => $marker) {
            $pos = strpos($page, $marker);
            self::assertIsInt($pos, "no {$section}");
            $at[$section] = $pos;
        }
        $sorted = $at;
        asort($sorted);
        self::assertSame(array_keys($at), array_keys($sorted));
    }

    /** The coloured stat boxes and the row of browse links are gone: the tiles and the admin menu say it. */
    public function testTheOldStatBoxesAreGone(): void
    {
        $page = self::page();

        self::assertStringNotContainsString('stat-card', $page);
        self::assertStringNotContainsString('sync-stats', $page);
        self::assertStringNotContainsString('travel-action-buttons', $page);
        self::assertStringNotContainsString('onclick=', $page, 'confirmations are CS-Cart\'s cm-confirm, not inline JavaScript');
    }

    public function testTheTilesReadTheSummaryWithThousandsSeparatorsAndLinkToTheirLists(): void
    {
        $tiles = self::between('travel-cron-tiles travel-cron-tiles--four', 'travel-cron-card travel-cron-attention');

        self::assertSame(4, preg_match_all('/<div class="travel-cron-tile(?:"|\{)/', $tiles));
        foreach (['$_g.hotels.in_scope|number_format', '$_g.products.linked|number_format', '$_g.unlinked.count|number_format', '$_g.unlinked.total|number_format'] as $figure) {
            self::assertStringContainsString($figure, $tiles);
        }
        foreach ([
            '"sphinx_holidays.hotels"|fn_url',
            '"sphinx_holidays.hotels?link_status=linked"|fn_url',
            '"sphinx_holidays.hotels?availability=waiting"|fn_url',
            '"sphinx_holidays.hotels?link_status=orphan"|fn_url',
            'href="#sphinx-cron-commands"',
        ] as $link) {
            self::assertStringContainsString($link, $tiles);
        }
        self::assertStringContainsString('travel-cron-tile--bad', $tiles, 'a failing job turns the jobs tile red, as on Novoton\'s');
    }

    /** Each line has the one action that fixes it. */
    public function testEveryAttentionLineHasItsFix(): void
    {
        $attention = self::between('travel-cron-card travel-cron-attention', 'travel-cron-card travel-cron-tools');

        self::assertStringContainsString('{if $sphinx_attention}', self::page(), 'shown only when there is something to do');
        self::assertStringContainsString('href="#sphinx-crontab"', $attention, 'a refused key: copy the commands with the current key');
        self::assertStringContainsString('value="sphinx_holidays.retry_skipped"', $attention);
        self::assertStringContainsString('run_dispatch="sphinx_holidays.run_job" job=$item.mode', $attention);
        self::assertStringContainsString('{$_name = __($item.name_key)}', $attention, 'the job by its label, from the plan\'s row');
        self::assertStringContainsString('__($item.schedule_key, $item.schedule_params)', $attention, '"is late" says the job\'s schedule');
    }

    /** Fix products: each button only when it has something to do, each a POST that asks first. */
    public function testTheToolsCardFixesProductsAndChecksOneHotel(): void
    {
        $tools = self::between('travel-cron-card travel-cron-tools', 'components/destinations_card.tpl');

        foreach (['{if $skipped_hotels > 0}' => 'sphinx_holidays.retry_skipped', '{if $unlinked_hotels > 0}' => 'sphinx_holidays.add_products', '{if $unlinked_sphinx_products > 0}' => 'sphinx_holidays.relink_products'] as $gate => $dispatch) {
            $at = strpos($tools, $gate);
            self::assertIsInt($at, $gate);
            $form = substr($tools, $at, (int) strpos($tools, '</form>', $at) - $at);
            self::assertStringContainsString('method="post"', $form, $dispatch);
            self::assertStringContainsString('value="' . $dispatch . '"', $form);
            self::assertStringContainsString('name="security_hash" value="{$security_hash}"', $form, $dispatch);
            self::assertStringContainsString('cm-confirm', $form, $dispatch);
            self::assertStringContainsString('data-ca-confirm-text=', $form, $dispatch);
        }
        self::assertStringContainsString('{include file="addons/sphinx_holidays/components/check_hotel.tpl"}', $tools);
    }

    /** Recent sync activity: a table, All or Problems, paged, long errors folded. */
    public function testTheActivityIsAPagedTableWithAProblemsFilter(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 6) . '/design/backend/templates/addons/sphinx_holidays/components/activity.tpl');
        $tpl = (string) preg_replace('/\{\*.*?\*\}/s', '', $src);

        self::assertSame(2, substr_count($tpl, '{include file="common/pagination.tpl" save_current_page=true}'), 'the pagination pair wraps the table');
        self::assertStringContainsString('"sphinx_holidays.manage?activity=problems"|fn_url', $tpl);
        self::assertStringContainsString('"sphinx_holidays.manage"|fn_url', $tpl);
        self::assertSame(7, substr_count($tpl, '<th scope="col"'), 'date, job, run, items, not synced, status, duration');
        foreach (['ok', 'partial', 'failed', 'stalled', 'running'] as $state) {
            self::assertStringContainsString('$run.state == "' . $state . '"', $tpl);
        }
        self::assertStringContainsString('<details class="travel-cron-activity__error">', $tpl);
        self::assertStringContainsString('{$run.error_full|escape:html}', $tpl);
        self::assertStringNotContainsString('sync-log-entry', self::page(), 'the tall log cards are gone');
    }

    /** A scope's commands are copied, never shown: only with a key, from Queue images' row, its parameters added by image-scope.js. */
    public function testTheScopeCardCopiesItsCommandsWithoutShowingThem(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 6) . '/design/backend/templates/addons/sphinx_holidays/components/image_scope.tpl');
        $tpl = (string) preg_replace('/\{\*.*?\*\}/s', '', $src);

        self::assertStringContainsString('{if $_job.mode == "sync_images"}{$_queue = $_job}{/if}', $tpl);
        self::assertStringContainsString('{if $sphinx_cron_has_key && $_queue}', $tpl);
        self::assertSame(2, preg_match_all('/<button type="button" class="btn travel-cron-copy" data-copy="\{\$_queue\.(url|cli)\|escape:html\}" data-sphinx-scope-base="\{\$_queue\.\1\|escape:html\}" data-sphinx-scope-format="\1">/', $tpl), 'Copy URL and Copy CLI command, buttons that never submit the run');
        self::assertStringContainsString('class="travel-cron-run-form travel-cron-form" data-sphinx-scope>', $tpl);
        self::assertStringContainsString('{script src="js/addons/sphinx_holidays/image-scope.js"}', $tpl);
        self::assertFileExists(dirname(__DIR__, 6) . '/js/addons/sphinx_holidays/image-scope.js');
    }

    public function testTheControllerGivesTheSummaryItsFigures(): void
    {
        $manage = self::manage();

        self::assertStringContainsString("\$view->assign('sphinx_glance', DashboardSummary::glance(", $manage);
        self::assertStringContainsString("\$view->assign('sphinx_attention', DashboardSummary::attention(", $manage);
        self::assertStringContainsString('CronHealth::assess($cron_records, CronRunLog::lastWrongKeyRefusal(CronPlan::ADDON), time())', $manage);
        self::assertStringContainsString("\$cron_health['state'] === CronHealth::REFUSED ? \$cron_health['wrong_key_at'] : 0", $manage);
        self::assertStringContainsString("SyncActivity::page(Container::getSyncLogRepository(), \$_REQUEST, time())", $manage);
        foreach (['counts_by_type', 'dest_last_synced', 'hotel_last_synced', 'unlinked_hotels', 'skipped_hotels', 'sphinx_activity', 'search'] as $var) {
            self::assertStringContainsString("\$view->assign('{$var}', ", $manage, "{$var} is read by the page");
        }
    }
}
