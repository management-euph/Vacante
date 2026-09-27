<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The rest of the dashboard: the attention strip, the tools, excluded resorts
 * and the paginated sync activity.
 *
 * Source-text assertions: the templates render inside CS-Cart's Smarty,
 * which this repository does not ship.
 */
final class DashboardPageTest extends TestCase
{
    private static function repo(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function code(string $path): string
    {
        return (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents(self::repo() . '/design/backend/templates/addons/novoton_holidays/' . $path));
    }

    private static function controller(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_holidays.php');
    }

    /** Each attention item carries the one action that fixes it: a Run of its job. */
    public function testEveryAttentionItemRunsItsJob(): void
    {
        $tpl = self::code('views/novoton_holidays/manage.tpl');
        $start = strpos($tpl, '{foreach from=$novoton_attention item=item}');
        self::assertIsInt($start);
        $loop = substr($tpl, $start, (int) strpos($tpl, '{/foreach}', $start) - $start);

        self::assertStringContainsString('components/run_job.tpl" job=$item.mode', $loop);
        foreach (['failed', 'stalled', 'never', 'late'] as $kind) {
            self::assertStringContainsString('$item.kind == "' . $kind . '"', $loop);
        }
        self::assertStringContainsString('{$item.error|escape:html}', $loop);
    }

    /** Recompute calendar prices rewrites every hotel: a confirmed POST, never a link. */
    public function testRecomputeCalendarIsAConfirmedPost(): void
    {
        $tpl = self::code('views/novoton_holidays/manage.tpl');

        self::assertStringNotContainsString('"novoton_holidays.recompute_calendar_prices"|fn_url', $tpl);
        self::assertMatchesRegularExpression('/run_job\.tpl" job="recompute_calendar_prices"[^}]*confirm=__\(/', $tpl);

        $controller = self::controller();
        $start = strpos($controller, "if (\$mode === 'recompute_calendar_prices') {");
        self::assertIsInt($start);
        $body = substr($controller, $start, 600);
        self::assertStringContainsString("if (\$_SERVER['REQUEST_METHOD'] !== 'POST') {", $body);
    }

    /** Resort names come from the API: printed escaped, posted back as the stored name. */
    public function testTheDestinationsFormPostsTheStoredNames(): void
    {
        $tpl = self::code('views/novoton_destinations/manage.tpl');

        self::assertStringContainsString('<form action="{"novoton_destinations.save"|fn_url}" method="post" id="novoton-dest-form"', $tpl);
        self::assertStringContainsString('<input type="checkbox" name="destinations[{$c.country|escape:html}][resorts][]" value="{$r.name|escape:html}"{if $r.selected} checked{/if}>', $tpl);
        self::assertStringContainsString('<input type="radio" name="destinations[{$c.country|escape:html}][mode]" value="{$m}"{if $c.mode == $m} checked{/if}>', $tpl);
        self::assertStringContainsString('{$r.label|escape:html}', $tpl);
        // Disabling products is its own form: never nested in (or sent by) Save.
        $main = strpos($tpl, 'id="novoton-dest-form"');
        $mainEnd = strpos($tpl, '</form>', (int) $main);
        $disable = strpos($tpl, '"novoton_destinations.disable_outside"|fn_url');
        self::assertIsInt($disable);
        self::assertGreaterThan($mainEnd, $disable);
    }

    /** The dashboard shows a summary of the destinations; the choosing happens on their page. */
    public function testTheDashboardSummarisesTheDestinationsWithoutAForm(): void
    {
        $tpl = self::code('views/novoton_holidays/manage.tpl');

        self::assertStringContainsString('id="novoton-destinations"', $tpl);
        self::assertStringContainsString('{"novoton_destinations.manage"|fn_url}', $tpl);
        self::assertStringNotContainsString('save_excluded_resorts', $tpl);
        self::assertStringNotContainsString('<form action="{"novoton_destinations', $tpl);
    }

    /** The resort count query: SUM(product_id > 0) is NULL for a resort with no linked hotel. */
    public function testResortCountsTreatUnlinkedHotelsAsZeroProducts(): void
    {
        $repo = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Repository/HotelReportingRepository.php');
        $start = strpos($repo, 'public function getResortCounts(');
        self::assertIsInt($start);
        $body = substr($repo, $start, 900);

        self::assertStringContainsString('SUM(CASE WHEN product_id > 0 THEN 1 ELSE 0 END) AS products', $body);
        self::assertStringContainsString('GROUP BY country, city', $body);
    }

    /** CS-Cart pagination is a pair around the one list, fed by $search with total_items. */
    public function testActivityIsPaginatedTheCsCartWay(): void
    {
        $tpl = self::code('components/activity.tpl');
        preg_match_all('/\{include file="common\/pagination\.tpl"[^}]*\}/', $tpl, $m, PREG_OFFSET_CAPTURE);

        self::assertCount(2, $m[0], 'common/pagination.tpl opens and closes: included exactly twice');
        self::assertLessThan((int) strpos($tpl, '<table'), $m[0][0][1]);
        self::assertGreaterThan((int) strrpos($tpl, '</table>'), $m[0][1][1]);
        self::assertStringContainsString('{$log.error_message|escape:html|truncate:160}', $tpl);

        $controller = self::controller();
        self::assertMatchesRegularExpression("/\\\$view->assign\\('search', \\[\\s*'page' => \\\$activity\\['page'\\],\\s*'items_per_page' => \\\$activity\\['per_page'\\],\\s*'total_items' => \\\$activity\\['total'\\],/", $controller);
    }

    /** Each page's script loads inside its capture, so it runs after the admin's AJAX navigation too. */
    public function testThePageScriptsLoadInsideTheCapture(): void
    {
        foreach (['views/novoton_holidays/manage.tpl' => 'dashboard.js', 'views/novoton_destinations/manage.tpl' => 'destinations.js'] as $file => $js) {
            $tpl = self::code($file);
            $close = strpos($tpl, '{/capture}');
            self::assertIsInt($close);
            $at = strpos($tpl, '{script src="js/addons/novoton_holidays/' . $js . '"}');
            self::assertIsInt($at, "{$js} is not loaded");
            self::assertLessThan($close, $at);
        }

        $hook = self::code('hooks/index/scripts.post.tpl');
        self::assertStringNotContainsString('{script src="js/addons/novoton_holidays/destinations.js"}', $hook, 'loaded on every admin page, it would not run after AJAX navigation');
    }
}
