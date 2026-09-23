<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    // ?:storage_data stand-ins. The suite-wide winners are the guarded stubs
    // in AppearanceColorsTest (loaded first alphabetically), which route
    // through StorageStub::$data — these definitions only bind when this
    // test runs in isolation, and route through the same store.
    if (!function_exists('fn_get_storage_data')) {
        function fn_get_storage_data(string $name): ?string
        {
            return \Tygh\Addons\TravelCore\Tests\Unit\Functions\StorageStub::$data[$name] ?? null;
        }
    }
    if (!function_exists('fn_set_storage_data')) {
        function fn_set_storage_data(string $name, string $value): void
        {
            \Tygh\Addons\TravelCore\Tests\Unit\Functions\StorageStub::$data[$name] = $value;
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/self_heal.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;

    // In-isolation runs load this file before AppearanceColorsTest — provide
    // the shared backing store if it isn't defined yet.
    if (!class_exists(StorageStub::class, false)) {
        final class StorageStub
        {
            /** @var array<string, string> */
            public static array $data = [];
        }
    }

    /**
     * Pins the self-heal stamp gate: the schema heals (catalog queries,
     * conditional ALTERs, a CREATE IF NOT EXISTS) used to re-run on EVERY
     * admin request. They are now armed only while the persisted stamp
     * differs from the deployed code's fingerprint, and both init.php gates
     * must stamp only AFTER the heal ran. The language-seed fingerprints are
     * likewise pinned to stat(size+mtime) — hashing hundreds of KB of
     * addon.xml per request was the other half of the tax.
     */
    final class SelfHealStampTest extends TestCase
    {
        protected function setUp(): void
        {
            StorageStub::$data = [];
        }

        protected function tearDown(): void
        {
            StorageStub::$data = [];
        }

        public function testDueUntilStampedThenArmedAgainByANewFingerprint(): void
        {
            self::assertTrue(\fn_travel_core_self_heal_due('t_schema', 'fp-v1'), 'no stamp yet — heal runs');

            \fn_travel_core_self_heal_stamp('t_schema', 'fp-v1');
            self::assertFalse(\fn_travel_core_self_heal_due('t_schema', 'fp-v1'), 'same code — steady state skips');

            self::assertTrue(\fn_travel_core_self_heal_due('t_schema', 'fp-v2'), 'new deployed code re-arms the heal');
        }

        public function testStampsAreNamespacedPerHealKey(): void
        {
            \fn_travel_core_self_heal_stamp('a', 'fp');
            self::assertSame('fp', StorageStub::$data['travel_heal_a'] ?? null);
            self::assertTrue(\fn_travel_core_self_heal_due('b', 'fp'), "a's stamp must not satisfy b");
        }

        public function testInitGatesAreWiredAndStampAfterTheHeal(): void
        {
            $tcInit = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');
            self::assertStringContainsString("fn_travel_core_self_heal_due('travel_core_schema'", $tcInit);
            // The heal is invoked THROUGH the guard: it runs on every admin
            // page load, and an uncaught throwable there is a 503 on the whole
            // admin (see fn_travel_core_self_heal_guard).
            $healPos = strpos($tcInit, "fn_travel_core_self_heal_guard('travel_core_schema'");
            $stampPos = strpos($tcInit, "fn_travel_core_self_heal_stamp('travel_core_schema'");
            self::assertNotFalse($healPos);
            self::assertNotFalse($stampPos);
            self::assertGreaterThan($healPos, $stampPos, 'stamp only after the heal ran');

            $spxInit = (string) file_get_contents(
                dirname(__DIR__, 7) . '/addon-sphinx-holidays/app/addons/sphinx_holidays/init.php',
            );
            self::assertStringContainsString("fn_travel_core_self_heal_due('sphinx_schema'", $spxInit);
            self::assertStringContainsString('md5_file(__DIR__ . \'/src/Install/SchemaMigrator.php\')', $spxInit);
        }

        public function testLanguageSeedFingerprintsAreStatBasedNotContentHashes(): void
        {
            $repoRoot = dirname(__DIR__, 7);
            $sites = [
                // The fingerprints moved from func.php into LanguageSeeder
                // classes, so init.php can reach them whatever the file load
                // order (see InitFirstLanguageHealTest). Pin where they live now.
                dirname(__DIR__, 3) . '/src/Install/LanguageSeeder.php' => 'function seedHash',
                $repoRoot . '/addon-novoton-holidays/app/addons/novoton_holidays/src/Install/LanguageSeeder.php' => 'function seedHash',
                $repoRoot . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Install/LanguageSeeder.php' => 'function seedHash',
            ];

            foreach ($sites as $file => $marker) {
                $src = (string) file_get_contents($file);
                $start = strpos($src, $marker);
                self::assertNotFalse($start, "$marker missing in $file");
                $end = strpos($src, 'function ', $start + strlen($marker));
                $body = substr($src, $start, $end === false ? null : $end - $start);
                self::assertStringContainsString('@stat(', $body, "$marker must stat, not hash content");
                self::assertStringNotContainsString('@md5_file(', $body, "$marker re-hashing full sources per request");
            }
        }
    }
}
