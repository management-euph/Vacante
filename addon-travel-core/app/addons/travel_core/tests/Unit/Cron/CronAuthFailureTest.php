<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;

/**
 * A refused cron request must be loud: non-zero exit, 403, and a log line.
 *
 * None of the three used to happen. `CronRunner::authenticate()` ended both
 * failure paths in a bare `exit("ERROR: …")`, which is exit status **0** —
 * while `run()`, immediately below it in the same class, uses exit(1) for an
 * unknown mode and for a caught Throwable. So a crontab wrapper checking `$?`
 * saw success while every scheduled sync was being refused, which is exactly
 * what a botched key rotation looks like.
 *
 * Driven in a real subprocess rather than asserted from source: the function
 * terminates the process, so the exit status IS the behaviour under test and
 * cannot be observed in-process. Same technique as FuncSelfSufficiencyTest.
 */
final class CronAuthFailureTest extends TestCase
{
    /**
     * @return array{int, string}
     */
    private function runAuth(string $storedKey, string $providedKey): array
    {
        $runner = dirname(__DIR__, 3) . '/src/Cron/CronRunner.php';
        self::assertFileExists($runner);

        $script = <<<'PHP'
            <?php
            // No fn_log_event: the guard must tolerate its absence.
            require $argv[1];
            \Tygh\Addons\TravelCore\Cron\CronRunner::authenticate($argv[2], $argv[3], 'Test Addon');
            echo "REACHED-AFTER-AUTH\n";
            PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'cronauth') . '.php';
        file_put_contents($tmp, $script);

        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp)
                . ' ' . escapeshellarg($runner)
                . ' ' . escapeshellarg($storedKey)
                . ' ' . escapeshellarg($providedKey) . ' 2>&1';
            exec($cmd, $lines, $exitCode);
        } finally {
            @unlink($tmp);
        }

        return [$exitCode, implode("\n", $lines)];
    }

    public function testAWrongKeyExitsNonZero(): void
    {
        [$exitCode, $output] = $this->runAuth('the-real-key', 'a-wrong-key');

        self::assertSame(1, $exitCode, "a refused cron must not report success:\n{$output}");
        self::assertStringContainsString('Invalid or missing access key', $output);
        self::assertStringNotContainsString('REACHED-AFTER-AUTH', $output);
    }

    public function testAnUnconfiguredKeyExitsNonZero(): void
    {
        [$exitCode, $output] = $this->runAuth('', 'anything');

        self::assertSame(1, $exitCode, "an unconfigured key must not report success:\n{$output}");
        self::assertStringContainsString('not set', $output);
        self::assertStringNotContainsString('REACHED-AFTER-AUTH', $output);
    }

    /** The success path must still return normally, not exit. */
    public function testAMatchingKeyPassesThrough(): void
    {
        [$exitCode, $output] = $this->runAuth('the-real-key', 'the-real-key');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('REACHED-AFTER-AUTH', $output);
    }

    /**
     * A key of literally "0" is a key.
     *
     * The old guards used empty(), for which '0' is empty — so a stored key of
     * "0" reported "not set" and a provided key of "0" was rejected before the
     * comparison. Strict string comparison fixes both.
     */
    public function testAKeyOfZeroIsTreatedAsAKey(): void
    {
        [$exitCode, $output] = $this->runAuth('0', '0');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('REACHED-AFTER-AUTH', $output);
    }

    /** Refusal is logged — with one shared key it is the only leak signal. */
    public function testRefusalIsLoggedAndSends403OverHttp(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cron/CronRunner.php');

        $pos = strpos($src, 'private static function refuse(');
        self::assertIsInt($pos, 'refuse() should own the single fail-closed path');
        $body = substr($src, $pos, 1400);

        self::assertStringContainsString('http_response_code(403)', $body);
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $body);
        self::assertStringContainsString('fn_log_event', $body);
        self::assertStringContainsString('REMOTE_ADDR', $body);
        self::assertStringContainsString('exit(1)', $body);
    }

    /**
     * The Core HTTP endpoint used a hand-rolled check that died with HTTP 200.
     * It must now go through the same helper as everything else.
     */
    public function testTheCoreCronControllerUsesTheSharedGuard(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/travel_cron.php',
        );

        self::assertStringContainsString('CronRunner::authenticate(', $controller);
        self::assertStringNotContainsString('die("ERROR: Cron access key not set', $controller);
        self::assertStringNotContainsString('die("ERROR: Invalid or missing access key', $controller);
    }
}
