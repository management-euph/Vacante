<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the STORE against a disabled travel_core.
 *
 * init.php is require'd from fn_init_addons(), and the
 * Tygh\Addons\TravelCore\* autoloader is registered only by travel_core's own
 * init.php — which CS-Cart does not run for a disabled addon. So any RUNTIME
 * reference to a Core class from this file throws "Class not found" from
 * inside bootstrap, which CS-Cart turns into a 503 on every page: storefront
 * AND admin, including the admin page you would need to re-enable Core from.
 *
 * `use Tygh\Addons\TravelCore\...` is only a compile-time alias and is
 * harmless on its own — this is about the first line that actually resolves
 * the class. It shipped that way: the version constant called
 * TypeCoerce::toString() before anything could have loaded TypeCoerce.
 *
 * Same bug class as travel_core's FuncSelfSufficiencyTest, which records the
 * 2026-07-10 install failure ("click Install, nothing happens") caused by
 * TypeCoerce entering func.php's variants callbacks unguarded. That test
 * proved the technique; it was simply never pointed at a provider's init.php.
 *
 * A grep would not do here. Only executing the file in an environment with no
 * autoloader proves the absence of a resolvable Core reference, including one
 * reached through a helper this file calls.
 */
final class InitSelfSufficiencyTest extends TestCase
{
    public function testInitPhpSurvivesBootstrapWithTravelCoreDisabled(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/core_absent_boot_sim.php';
        $initPhp = dirname(__DIR__, 3) . '/init.php';
        self::assertFileExists($fixture);
        self::assertFileExists($initPhp);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
            . ' ' . escapeshellarg($initPhp) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        self::assertSame(
            0,
            $exitCode,
            "init.php is not self-sufficient with travel_core disabled — this is a 503 "
            . "on every page of the shop, not just a broken addon:\n{$output}",
        );
        self::assertStringContainsString('OK boot', $output);

        // The version block must still have done its job, not been skipped.
        // '2.4.1-beta' from the fixture, with the suffix stripped.
        self::assertStringContainsString('version=2.4.1', $output);
        self::assertStringNotContainsString('(undefined)', $output);
    }
}
