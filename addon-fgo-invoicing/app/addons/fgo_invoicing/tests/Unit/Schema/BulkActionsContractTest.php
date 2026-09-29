<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The orders-list "FGO invoice" menu is three files that must agree, and
 * nothing at runtime says when they drift:
 *
 *   - schemas/context_menu/orders.post.php  the items and their dispatches;
 *   - schemas/permissions/admin.post.php    a STRING privilege per mode: the
 *     menu asks fn_check_permissions(..., 'post') in lower case, and a
 *     GET/POST map has no 'post' key, i.e. no check at all;
 *   - the controller                        a `$mode === '...'` branch per
 *     dispatch, or the click lands on an empty page.
 *
 * The schema files are included as CS-Cart includes them (separate process:
 * they need fn_url() / __() and RESTRICTED_ADMIN is a constant).
 */
#[CoversNothing]
final class BulkActionsContractTest extends TestCase
{
    private const ADDON = __DIR__ . '/../../..';

    /** Privileges that exist in CS-Cart 4.20's `orders` section (there is no manage_orders). */
    private const ORDER_PRIVILEGES = ['view_orders', 'edit_order', 'create_order', 'change_order_status', 'delete_orders', 'update_order_details'];

    private const EXPECTED_ITEMS = [
        'fgo_issue' => ['fgo_invoicing.m_issue', 10],
        'fgo_retry' => ['fgo_invoicing.m_retry', 20],
        'fgo_divider_1' => [null, 30],
        'fgo_download_pdfs' => ['fgo_invoicing.m_download_pdfs', 40],
        'fgo_email' => ['fgo_invoicing.m_email', 50],
        'fgo_divider_2' => [null, 60],
        'fgo_cancel' => ['fgo_invoicing.m_cancel', 70],
        'fgo_storno' => ['fgo_invoicing.m_storno', 80],
        'fgo_delete' => ['fgo_invoicing.m_delete', 90],
        'fgo_divider_3' => [null, 100],
        'fgo_open_log' => ['fgo_invoicing.manage', 110],
    ];

    /** addon-fgo-invoicing/, the package root holding design/ and js/. */
    private static function package(): string
    {
        return dirname(__DIR__, 6);
    }

    private static function stubCore(): void
    {
        if (!function_exists('fn_url')) {
            eval('function fn_url($url) { return "admin.php?dispatch=" . str_replace("?", "&", $url); }');
        }
        if (!function_exists('__')) {
            eval('function __($key, $params = []) { return "T:" . $key; }');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function contextMenu(): array
    {
        self::stubCore();
        $schema = [
            'items' => [
                'status' => ['position' => 20],
                'print' => ['type' => 'Tygh\\ContextMenu\\Items\\GroupItem', 'position' => 30],
                'actions' => ['type' => 'Tygh\\ContextMenu\\Items\\GroupItem', 'position' => 40],
            ],
        ];
        $result = require self::ADDON . '/schemas/context_menu/orders.post.php';
        self::assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @return array<string, array{permissions: string}>
     */
    private static function permissionModes(): array
    {
        $schema = [];
        $result = require self::ADDON . '/schemas/permissions/admin.post.php';
        self::assertIsArray($result);
        self::assertIsArray($result['fgo_invoicing'] ?? null);
        self::assertIsArray($result['fgo_invoicing']['modes'] ?? null);

        /** @var array<string, array{permissions: string}> $modes */
        $modes = $result['fgo_invoicing']['modes'];

        return $modes;
    }

    /**
     * @return list<string> every mode the controller branches on
     */
    private static function controllerModes(): array
    {
        $src = (string) file_get_contents(self::ADDON . '/controllers/backend/fgo_invoicing.php');
        preg_match_all("/\\\$mode === '([a-z_]+)'/", $src, $m);

        return array_values(array_unique($m[1]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheGroupSitsBetweenPrintAndActions(): void
    {
        $schema = self::contextMenu();
        self::assertIsArray($schema['items']);
        $group = $schema['items']['fgo_invoice'] ?? null;

        self::assertIsArray($group);
        self::assertSame('Tygh\\ContextMenu\\Items\\GroupItem', $group['type']);
        self::assertSame(['template' => 'fgo_invoicing.menu_fgo_invoice'], $group['name']);
        self::assertSame(35, $group['position']);
        self::assertInstanceOf(\Closure::class, $group['permission_callback']);
        self::assertSame(['status', 'print', 'actions', 'fgo_invoice'], array_keys($schema['items']), 'core items untouched');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheItemsAreTheMockupsInItsOrder(): void
    {
        $items = self::contextMenu()['items']['fgo_invoice']['items'];
        self::assertIsArray($items);

        $actual = [];
        foreach ($items as $id => $item) {
            self::assertIsArray($item);
            $actual[$id] = [$item['dispatch'] ?? null, $item['position']];
            if (!isset($item['dispatch'])) {
                self::assertSame('Tygh\\ContextMenu\\Items\\DividerItem', $item['type'], $id);
                continue;
            }
            self::assertArrayNotHasKey('type', $item, "{$id}: children default to GroupActionItem");
            self::assertIsArray($item['name']);
            self::assertStringStartsWith('fgo_invoicing.menu_', $item['name']['template']);
            self::assertInstanceOf(\Closure::class, $item['permission_callback'], "{$id}: CS-Cart type-hints a Closure");
        }
        uasort($actual, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        self::assertSame(self::EXPECTED_ITEMS, $actual);
    }

    /**
     * The pre-check page IS the confirmation; a cm-confirm dialog in front
     * of it would ask twice.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoItemAsksForAConfirmationAndDeleteIsRed(): void
    {
        $items = self::contextMenu()['items']['fgo_invoice']['items'];

        self::assertStringNotContainsString('cm-confirm', (string) json_encode($items));
        self::assertSame(['action_class' => 'text-error'], $items['fgo_delete']['data']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheLogItemIsAPlainLink(): void
    {
        $item = self::contextMenu()['items']['fgo_invoice']['items']['fgo_open_log'];

        $attributes = $item['data']['action_attributes'];
        self::assertNotSame('', $attributes['class'], 'a class drops cm-process-items / cm-submit');
        self::assertSame('admin.php?dispatch=fgo_invoicing.manage', $attributes['href']);
    }

    /**
     * The FGO controller denies admins with a usergroup (RESTRICTED_ADMIN),
     * so they must not see items that would only be denied.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestrictedAdminsDoNotSeeTheMenu(): void
    {
        $group = self::contextMenu()['items']['fgo_invoice'];
        $callbacks = [$group['permission_callback']];
        foreach ($group['items'] as $item) {
            if (isset($item['permission_callback'])) {
                $callbacks[] = $item['permission_callback'];
            }
        }
        self::assertCount(9, $callbacks, 'the group and its eight items');

        foreach ($callbacks as $callback) {
            self::assertTrue($callback([], [], []), 'a full admin sees it');
        }
        define('RESTRICTED_ADMIN', true);
        foreach ($callbacks as $callback) {
            self::assertFalse($callback([], [], []), 'a restricted admin does not');
        }

        $src = (string) file_get_contents(self::ADDON . '/controllers/backend/fgo_invoicing.php');
        self::assertStringContainsString("if (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN) {\n    return [CONTROLLER_STATUS_DENIED];", $src);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEveryMenuDispatchHasAStringPrivilegeAndAControllerBranch(): void
    {
        $modes = self::permissionModes();
        $branches = self::controllerModes();

        foreach (self::EXPECTED_ITEMS as $id => [$dispatch]) {
            if ($dispatch === null) {
                continue;
            }
            [$controller, $mode] = explode('.', $dispatch);
            self::assertSame('fgo_invoicing', $controller);
            self::assertIsString($modes[$mode]['permissions'] ?? null, "{$dispatch} needs a STRING privilege");
            self::assertContains($mode, $branches, "{$dispatch} has no `\$mode === '{$mode}'` branch");
        }
    }

    public function testEveryControllerModeHasAStringPrivilegeThatExists(): void
    {
        $modes = self::permissionModes();

        foreach (self::controllerModes() as $mode) {
            self::assertArrayHasKey($mode, $modes, "mode {$mode} has no privilege");
        }
        foreach ($modes as $mode => $entry) {
            self::assertIsString($entry['permissions'], "{$mode}: a GET/POST map is not enforced by the menu");
            self::assertContains($entry['permissions'], self::ORDER_PRIVILEGES, "{$mode}: unknown privilege");
        }
        self::assertContains('bulk_run', self::controllerModes());
        self::assertContains('bulk', self::controllerModes());
    }

    /**
     * Reading needs view_orders; anything that calls FGO or mails a customer
     * needs edit_order.
     */
    public function testWritingModesNeedEditOrder(): void
    {
        $modes = self::permissionModes();

        foreach (['manage', 'view', 'bulk', 'm_download_pdfs', 'test_connection'] as $mode) {
            self::assertSame('view_orders', $modes[$mode]['permissions'], $mode);
        }
        foreach (['issue', 'cancel', 'storno', 'delete', 'attach_awb', 'm_issue', 'm_retry', 'm_email', 'm_cancel', 'm_storno', 'm_delete', 'bulk_run'] as $mode) {
            self::assertSame('edit_order', $modes[$mode]['permissions'], $mode);
        }

        $schema = [];
        $result = require self::ADDON . '/schemas/permissions/admin.post.php';
        self::assertSame('view_orders', $result['fgo_invoicing']['permissions']);
    }

    /**
     * fn_dispatch() follows the orders list form's own redirect_url unless
     * the controller drops it, and the admin would never see the pre-check.
     */
    public function testTheMenuModesDropTheListsRedirect(): void
    {
        $src = (string) file_get_contents(self::ADDON . '/controllers/backend/fgo_invoicing.php');

        self::assertSame(2, substr_count($src, "unset(\$_REQUEST['redirect_url'], \$_REQUEST['page']);"));
        self::assertStringContainsString("'fgo_invoicing.bulk?action=' . \$bulkAction->value", $src);
        self::assertStringContainsString('return [CONTROLLER_STATUS_NO_CONTENT];', $src, 'bulk_run answers JSON only');
        self::assertStringContainsString("fn_get_file(\$zip['path'], 'fgo-invoices-' . date('Ymd-His') . '.zip', true);", $src);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheOrdersMenuLinksTheInvoiceLog(): void
    {
        self::stubCore();
        $schema = ['central' => ['orders' => ['items' => ['view_orders' => ['position' => 100]]]]];
        $result = require self::ADDON . '/schemas/menu/menu.post.php';

        self::assertSame([
            'attrs' => ['class' => 'is-addon'],
            'href' => 'fgo_invoicing.manage',
            'position' => 1100,
            'title' => 'T:fgo_invoicing.manage_title',
        ], $result['central']['orders']['items']['fgo_invoices']);
        self::assertArrayHasKey('view_orders', $result['central']['orders']['items']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestrictedAdminsGetNoMenuEntry(): void
    {
        self::stubCore();
        define('RESTRICTED_ADMIN', true);
        $schema = ['central' => ['orders' => ['items' => []]]];
        $result = require self::ADDON . '/schemas/menu/menu.post.php';

        self::assertSame([], $result['central']['orders']['items']);
    }

    /**
     * The admin reaches the page by AJAX navigation too, whose response
     * carries only the mainbox: a {script} outside the capture is dropped.
     * And no inline <script>: CS-Cart rewrites those.
     */
    public function testThePageLoadsItsScriptInsideTheMainbox(): void
    {
        $tpl = (string) file_get_contents(self::package() . '/design/backend/templates/addons/fgo_invoicing/views/fgo_invoicing/bulk.tpl');

        $script = strpos($tpl, '{script src="js/addons/fgo_invoicing/bulk.js"}');
        self::assertNotFalse($script);
        self::assertGreaterThan((int) strpos($tpl, '{capture name="mainbox"}'), $script);
        self::assertLessThan((int) strpos($tpl, '{/capture}'), $script);
        self::assertStringNotContainsString('<script', (string) preg_replace('/\{\*.*?\*\}/s', '', $tpl), 'outside Smarty comments');
        self::assertFileExists(self::package() . '/js/addons/fgo_invoicing/bulk.js');
        self::assertStringContainsString('{"fgo_invoicing.bulk_run"|fn_url}', $tpl);
    }

    public function testTheOrdersListColumnUsesTheLoopVariableAndSafeLinks(): void
    {
        $dir = self::package() . '/design/backend/templates/addons/fgo_invoicing/hooks/orders/';
        $header = (string) file_get_contents($dir . 'manage_header.post.tpl');
        $cell = (string) file_get_contents($dir . 'manage_data.post.tpl');

        self::assertStringContainsString('<th', $header);
        self::assertStringContainsString('$o.fgo_invoice', $cell);
        self::assertStringContainsString('data-fgo-status=', $cell);
        self::assertStringContainsString('data-th=', $cell);
        self::assertStringContainsString('rel="noopener noreferrer"', $cell);
        self::assertStringNotContainsString('nofilter', $cell, 'every value is escaped');
        self::assertSame(substr_count($header, 'RESTRICTED_ADMIN'), substr_count($cell, 'RESTRICTED_ADMIN'), 'header and cells hide together');
    }
}
