<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Labels must reach the store whichever of init.php / func.php CS-Cart loads first.
 *
 * Field failure: after the cron-key move, Travel Core -> Tools rendered
 * "_travel_core.tools_cron_key_title", "_travel_core.tools_cron_key_desc" and
 * "_travel_core.tools_cron_key_rotate" as raw keys, while every label that
 * existed at install time rendered fine.
 *
 * Cause: the init.php language heals of travel_core, sphinx_holidays and
 * novoton_holidays called fn_<addon>_language_seed_hash(), which lives in
 * func.php — a file init.php never loads. Older CS-Cart cores include init.php
 * FIRST, so on those the call hit an undefined function, the self-heal guard
 * swallowed the Error, and this repeated on every request. No label added
 * after install was ever delivered. fgo_invoicing (54fbc36) and eurosite had
 * already been fixed for exactly this; these three had not.
 *
 * This boots the real init.php files in a clean subprocess with func.php
 * ABSENT, against a small in-memory language table, and checks what landed.
 * Against the old init.php files it writes zero rows and reports three
 * "Call to undefined function" heal failures.
 */
#[CoversNothing]
final class InitFirstLanguageHealTest extends TestCase
{
    /** @return array{written: list<string>, heal_failures: list<string>, func_php_loaded: bool} */
    private static function boot(): array
    {
        $repo = dirname(__DIR__, 7);
        $fixture = dirname(__DIR__, 2) . '/Fixtures/init_first_boot_sim.php';

        $process = proc_open(
            [
                PHP_BINARY,
                $fixture,
                $repo . '/addon-travel-core/app/addons/travel_core/init.php',
                $repo . '/addon-sphinx-holidays/app/addons/sphinx_holidays/init.php',
                $repo . '/addon-novoton-holidays/app/addons/novoton_holidays/init.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "boot failed:\n{$out}\n{$err}");

        $json = strstr($out, '{');
        $result = json_decode($json === false ? '' : $json, true);
        self::assertIsArray($result, "boot printed no JSON:\n{$out}\n{$err}");

        /** @var array{written: list<string>, heal_failures: list<string>, func_php_loaded: bool} $result */
        return $result;
    }

    public function testNoLanguageHealFailsWhenInitPhpLoadsBeforeFuncPhp(): void
    {
        $r = self::boot();

        self::assertFalse($r['func_php_loaded'], 'the simulation must boot WITHOUT func.php, or it proves nothing');
        self::assertSame(
            [],
            $r['heal_failures'],
            "a language heal still depends on func.php:\n" . implode("\n", $r['heal_failures']),
        );
    }

    public function testTheLabelsThatRenderedRawOnTheToolsPageAreDelivered(): void
    {
        $written = self::boot()['written'];

        foreach ([
            'travel_core.tools_cron_key_title',
            'travel_core.tools_cron_key_desc',
            'travel_core.tools_cron_key_rotate',
            'travel_core.tools_cron_key_generate',
        ] as $label) {
            self::assertContains($label, $written, "{$label} would still render raw");
        }
    }

    /** All three heals, not only travel_core's: they shared the defect. */
    public function testEveryAffectedAddonDeliversItsLabels(): void
    {
        $written = self::boot()['written'];

        foreach (['travel_core', 'sphinx_holidays', 'novoton_holidays'] as $addon) {
            self::assertContains(
                $addon . '._lang_seed_hash',
                $written,
                "{$addon}'s language heal never completed a seed",
            );
        }
        // One label each that changed after install, so a seed that writes
        // only the stamp cannot pass.
        self::assertContains('sphinx_holidays.cron_key_not_set', $written);
    }
}
