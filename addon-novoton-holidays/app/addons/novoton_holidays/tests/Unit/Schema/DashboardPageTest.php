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
    public function testTheResortFormPostsTheStoredNames(): void
    {
        $tpl = self::code('views/novoton_holidays/manage.tpl');

        self::assertStringContainsString('<form action="{"novoton_holidays.save_excluded_resorts"|fn_url}" method="post" id="excluded-resorts-form"', $tpl);
        self::assertStringContainsString('<input type="checkbox" name="excluded_resorts[]" value="{$resort.name|escape:html}"{if $resort.excluded} checked{/if}>', $tpl);
        self::assertStringContainsString('{$resort.label|escape:html}', $tpl);
        self::assertStringContainsString('data-hotels="{$resort.hotels}" data-products="{$resort.products}"', $tpl);
        // Folded by default: a summary, not a wall of 76 checkboxes.
        self::assertMatchesRegularExpression('/<details class="[^"]*novoton-resorts" id="novoton-resorts">/', $tpl);
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

    /** Both page scripts load inside the capture, so they run after the admin's AJAX navigation too. */
    public function testThePageScriptsLoadInsideTheCapture(): void
    {
        $tpl = (string) file_get_contents(self::repo() . '/design/backend/templates/addons/novoton_holidays/views/novoton_holidays/manage.tpl');
        $close = strpos($tpl, '{/capture}');
        self::assertIsInt($close);
        foreach (['dashboard.js', 'resort-manager.js'] as $js) {
            $at = strpos($tpl, '{script src="js/addons/novoton_holidays/' . $js . '"}');
            self::assertIsInt($at, "{$js} is not loaded");
            self::assertLessThan($close, $at);
        }

        $hook = self::code('hooks/index/scripts.post.tpl');
        self::assertStringNotContainsString('resort-manager.js', $hook, 'loaded on every admin page, it never ran after AJAX navigation');
    }
}
