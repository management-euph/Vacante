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

    /**
     * The Destinations page is Travel Core's destination picker: resort names
     * come from the API, printed escaped and posted back as the stored name;
     * disabling products is its own form, never nested in (or sent by) Save.
     */
    public function testTheDestinationsPageIsTheSharedPicker(): void
    {
        $tpl = self::code('views/novoton_destinations/manage.tpl');
        self::assertStringContainsString('{include file="addons/travel_core/components/destination_picker.tpl" dest=$novoton_destinations}', $tpl);

        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_destinations.php');
        self::assertStringContainsString("TypeCoerce::toString(fn_url('novoton_destinations.save'))", $controller);
        self::assertStringContainsString("fn_url('novoton_destinations.disable_outside')", $controller);

        $core = dirname(__DIR__, 7) . '/addon-travel-core/design/backend/templates/addons/travel_core/components/';
        $body = (string) file_get_contents($core . 'destination_country_body.tpl');
        self::assertStringContainsString('name="dest[{$_c.key|escape:html}][items][]" value="{$_i.value|escape:html}"{if $_i.selected} checked{/if}', $body);
        self::assertStringContainsString('{$_i.label|escape:html}', $body);
        $country = (string) file_get_contents($core . 'destination_country.tpl');
        self::assertStringContainsString('name="dest[{$_c.key|escape:html}][mode]" value="{$_m.value}"', $country);

        $page = (string) file_get_contents($core . 'destination_picker.tpl');
        $main = strpos($page, 'id="{$_d.id}-dest-form"');
        self::assertIsInt($main);
        $mainEnd = strpos($page, '</form>', $main);
        $disable = strpos($page, '<form action="{$_d.outside_url}"');
        self::assertIsInt($disable);
        self::assertGreaterThan($mainEnd, $disable);
    }

    /** The dashboard shows a summary of the destinations; the choosing happens on their page. */
    public function testTheDashboardSummarisesTheDestinationsWithoutAForm(): void
    {
        $tpl = self::code('views/novoton_holidays/manage.tpl');

        self::assertStringContainsString('{include file="addons/travel_core/components/destinations_card.tpl" card=$novoton_dest_card}', $tpl);
        self::assertStringContainsString("DestinationsPicker::card(\$destinations, \\Tygh\\Addons\\TravelCore\\Helpers\\TypeCoerce::toString(fn_url('novoton_destinations.manage')))", self::controller());
        $picker = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/DestinationsPicker.php');
        self::assertStringContainsString("'id' => 'novoton-destinations'", $picker, 'the anchor other pages link to');
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
        $tpl = self::code('views/novoton_holidays/manage.tpl');
        $close = strpos($tpl, '{/capture}');
        self::assertIsInt($close);
        $at = strpos($tpl, '{script src="js/addons/novoton_holidays/dashboard.js"}');
        self::assertIsInt($at, 'dashboard.js is not loaded');
        self::assertLessThan($close, $at);

        // The Destinations page includes the picker inside its capture; the
        // picker loads its own script, so it comes along.
        $dest = self::code('views/novoton_destinations/manage.tpl');
        $include = strpos($dest, 'components/destination_picker.tpl');
        self::assertIsInt($include);
        self::assertLessThan((int) strpos($dest, '{/capture}'), $include);
        self::assertFileDoesNotExist(dirname(__DIR__, 6) . '/js/addons/novoton_holidays/destinations.js', 'replaced by travel_core/destination-picker.js');
    }
}
