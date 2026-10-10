<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards sphinx_holidays' INSTALLABILITY.
 *
 * While the add-on installs it is not active yet, so CS-Cart never runs its
 * init.php. CS-Cart loads func.php alone, calls the settings-variants
 * callbacks (the category selectboxes) and then post_install, and both reach
 * classes in src/. Without the add-on's own autoloader they die in a silent
 * fatal: "click Install, nothing happens", which a fresh store hit on its
 * first install. travel_core's FuncSelfSufficiencyTest records the same bug
 * class; this one also allows what an install really has: travel_core is a
 * dependency, so its autoloader is registered.
 */
final class FuncSelfSufficiencyTest extends TestCase
{
    public function testFuncPhpSurvivesInstallRequestWithoutOwnAutoloader(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/install_environment_sim.php';
        $funcPhp = dirname(__DIR__, 3) . '/func.php';
        $coreSrc = dirname(__DIR__, 7) . '/addon-travel-core/app/addons/travel_core/src';
        self::assertFileExists($fixture);
        self::assertFileExists($funcPhp);
        self::assertDirectoryExists($coreSrc);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' '
            . escapeshellarg($funcPhp) . ' ' . escapeshellarg($coreSrc) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        self::assertSame(
            0,
            $exitCode,
            "func.php is not self-sufficient for the install request (no sphinx autoloader):\n{$output}",
        );
        self::assertStringContainsString('OK variants', $output);
        self::assertStringContainsString('hotels_category_id=2', $output);
        self::assertMatchesRegularExpression('/OK classes: [1-9]\d*/', $output);
        self::assertStringNotContainsString('Fatal error', $output);
    }
}
