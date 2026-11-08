<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The admin's "Package routes" and "Circuits" pages showed their "Sync now"
 * button always disabled: the template disables it unless $is_configured,
 * which neither page assigned. Every admin page whose template reads
 * $is_configured assigns it.
 */
final class AdminSyncButtonsWiringTest extends TestCase
{
    public function testEveryPageThatDisablesSyncKnowsWhetherTheApiIsConfigured(): void
    {
        $root = dirname(__DIR__, 3);
        $controller = (string) file_get_contents($root . '/controllers/backend/sphinx_holidays.php');
        $templates = (string) realpath($root . '/../../../design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays');

        $checked = 0;
        foreach ((array) glob($templates . '/*.tpl') as $file) {
            if (!str_contains((string) file_get_contents((string) $file), '$is_configured')) {
                continue;
            }
            $mode = basename((string) $file, '.tpl');
            $start = strpos($controller, "\$mode === '{$mode}'");
            self::assertNotFalse($start, $mode . ': no admin mode renders it');
            $next = strpos($controller, "\$mode === '", $start + 1);
            $branch = substr($controller, $start, $next === false ? null : $next - $start);
            self::assertStringContainsString("\$view->assign('is_configured', ", $branch, $mode);
            $checked++;
        }

        self::assertGreaterThanOrEqual(4, $checked, 'manage, product_links, circuits, package_routes');
    }
}
