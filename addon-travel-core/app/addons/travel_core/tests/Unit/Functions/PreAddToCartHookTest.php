<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Tests\Support\SourceCode;

/**
 * The pre_add_to_cart hook end to end: registered in init.php, declared in
 * the order CS-Cart 4.x fires it (fn_set_hook('pre_add_to_cart',
 * $product_data, $cart, $auth, $update) in fn_add_product_to_cart), and
 * stripping by reference on the storefront only. AREA is a process-wide
 * constant, so each area runs in its own process.
 */
#[CoversNothing]
final class PreAddToCartHookTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function load(string $area): void
    {
        if (!defined('BOOTSTRAP')) {
            define('BOOTSTRAP', true);
        }
        if (!defined('AREA')) {
            define('AREA', $area);
        }
        if (!function_exists('fn_log_event')) {
            eval('function fn_log_event(string $t, string $a, array $d = []): void {}');
        }
        require_once self::root() . '/hooks/cart_hooks.php';
    }

    /** @return array<array-key, mixed> */
    private static function forgedAdd(): array
    {
        return [3 => ['product_id' => 3, 'amount' => 1, 'extra' => ['travel_balance_id' => 57, 'parent_order_id' => 1042, 'product_options' => []]]];
    }

    public function testTheHookIsRegistered(): void
    {
        self::assertStringContainsString("'pre_add_to_cart'", SourceCode::code(self::root() . '/init.php'));
    }

    #[RunInSeparateProcess]
    public function testTheStorefrontAddLosesTheForgedKeys(): void
    {
        self::load('C');
        $productData = self::forgedAdd();
        $cart = ['products' => []];
        $auth = [];
        $update = false;

        \fn_travel_core_pre_add_to_cart($productData, $cart, $auth, $update);

        self::assertSame(['product_options' => []], $productData[3]['extra']);
    }

    #[RunInSeparateProcess]
    public function testAdminOrderEditingKeepsItsExtra(): void
    {
        self::load('A');
        $productData = self::forgedAdd();
        $cart = ['products' => []];
        $auth = [];
        $update = false;

        \fn_travel_core_pre_add_to_cart($productData, $cart, $auth, $update);

        self::assertSame(self::forgedAdd(), $productData);
    }

    #[RunInSeparateProcess]
    public function testTheSignatureTakesProductDataByReference(): void
    {
        self::load('C');
        $fn = new \ReflectionFunction('fn_travel_core_pre_add_to_cart');

        self::assertSame(1, $fn->getNumberOfRequiredParameters());
        self::assertTrue($fn->getParameters()[0]->isPassedByReference());
        self::assertGreaterThanOrEqual(4, $fn->getNumberOfParameters());
    }
}
