<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * travel_core must refuse to go while any provider still depends on it.
 *
 * `fn_travel_core_uninstall()` drops `?:travel_bookings`, `?:travel_api_alias`,
 * `?:travel_feature_map`, `?:travel_unmapped_values` and
 * `?:travel_alternative_requests` — shared tables every provider reads and
 * writes. Its guard listed novoton and sphinx only, so it would return true
 * and drop all five out from under a live eurosite install.
 *
 * The list is deliberately a LITERAL rather than a read of
 * KNOWN_PROVIDER_ADDONS, and that is correct: uninstall runs in a context
 * where init.php — the only registrar of the Tygh\Addons\TravelCore\*
 * autoloader — may not have run, so referencing the registry class there could
 * fatal. This test supplies what the literal gives up. It reads the constant
 * and the source and fails when they disagree, so adding a fourth provider
 * breaks the build instead of quietly widening the blast radius.
 *
 * Third instance of this exact drift. `fn_travel_core_settings_heal_addons()`
 * had it (eurosite's cron key was never created on any store), and the debug
 * dump in cart_hooks.php had it. Both now derive; this one cannot, so it is
 * pinned instead.
 */
final class UninstallGuardCoverageTest extends TestCase
{
    private static function funcPhp(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
    }

    public function testEveryKnownProviderBlocksTheUninstall(): void
    {
        $src = self::funcPhp();

        $pos = strpos($src, 'function fn_travel_core_uninstall(');
        self::assertIsInt($pos);
        $body = substr($src, $pos, 1800);

        self::assertSame(
            1,
            preg_match('/\$provider_addons = (\[[^\]]*\]);/', $body, $m),
            'the uninstall guard should hold exactly one provider list',
        );

        foreach (TravelProviderRegistry::KNOWN_PROVIDER_ADDONS as $addon) {
            self::assertStringContainsString(
                "'{$addon}'",
                $m[1],
                "fn_travel_core_uninstall() does not block on {$addon}, so travel_core can be "
                . 'uninstalled while it is live — dropping the shared tables it writes to. Add it '
                . 'to the literal (it must stay a literal; see the comment above it).',
            );
        }
    }

    /**
     * The guard must actually stop the uninstall, not just warn. If this ever
     * becomes a warning, the shared tables go regardless of the notification.
     */
    public function testTheGuardReturnsFalseRatherThanOnlyNotifying(): void
    {
        $src = self::funcPhp();

        $pos = strpos($src, 'function fn_travel_core_uninstall(');
        self::assertIsInt($pos);
        $body = substr($src, $pos, 1800);

        $notify = strpos($body, 'fn_set_notification');
        $return = strpos($body, 'return false;');
        $drop = strpos($body, 'DROP TABLE IF EXISTS');

        self::assertIsInt($notify);
        self::assertIsInt($return);
        self::assertIsInt($drop);
        self::assertLessThan($return, $notify, 'notify, then refuse');
        self::assertLessThan($drop, $return, 'the refusal must come before any DROP');
    }

    /**
     * The debug surface should show every provider too — it is the first
     * place an operator looks to ask "is my addon even active?", and eurosite
     * was invisible there.
     */
    public function testTheDebugDumpCoversEveryKnownProvider(): void
    {
        $hooks = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/cart_hooks.php');

        self::assertStringContainsString(
            "\$addons = ['travel_core', ...TravelProviderRegistry::KNOWN_PROVIDER_ADDONS];",
            $hooks,
            'the debug dump runs at dispatch time, so it can and should derive the list',
        );
    }
}
