<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\StorefrontDebug;

/**
 * Debug mode showed the supplier's raw requests and answers (net prices,
 * and with them the API login) to every storefront visitor. Now only a
 * visitor signed in with an administrator account sees them.
 */
final class StorefrontDebugTest extends TestCase
{
    public function testOnlyAnAdministratorSeesDebugOutput(): void
    {
        self::assertTrue(StorefrontDebug::allowed(true, ['user_id' => 1, 'user_type' => 'A']));
        self::assertFalse(StorefrontDebug::allowed(true, ['user_id' => 7, 'user_type' => 'C']), 'a customer');
        self::assertFalse(StorefrontDebug::allowed(true, ['user_id' => 0, 'user_type' => '']), 'a guest');
        self::assertFalse(StorefrontDebug::allowed(true, null), 'no session');
    }

    public function testNobodySeesItWhileDebugModeIsOff(): void
    {
        self::assertFalse(StorefrontDebug::allowed(false, ['user_id' => 1, 'user_type' => 'A']));
    }

    public function testEveryStorefrontDebugOutputAsksIt(): void
    {
        $root = dirname(__DIR__, 3);
        $read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

        self::assertStringContainsString('StorefrontDebug::forThisVisitor()) {', $read('controllers/frontend/novoton_booking/ajax_recalculate_price.php'));
        self::assertStringContainsString('$debug = \Tygh\Addons\NovotonHolidays\Helpers\StorefrontDebug::forThisVisitor();', $read('controllers/frontend/novoton_booking/search.php'));
        self::assertStringNotContainsString('ConfigProvider::isDebugMode()', $read('controllers/frontend/novoton_booking/search.php'));
        self::assertStringContainsString("StorefrontDebug::forThisVisitor()) {\n        \$view->assign('novoton_checkout_debug', true);", $read('hooks/cart_hooks.php'));
    }
}
