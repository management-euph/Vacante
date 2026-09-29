<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Install\LanguageSeeder;

/**
 * The runtime label delivery — the only path by which a label added after a
 * store was installed ever reaches it, because CS-Cart imports the .po packs
 * exactly once, at install.
 *
 * When it does not run, `__()` returns '_' . $key and the admin reads
 * "_eurosite.countries", "_eurosite.save_whitelist", "_eurosite.remove_all" —
 * which is precisely what the destination whitelist shipped as, since its
 * labels were added long after the addon was installed. Nothing logs, and the
 * `["[default]" => "…"]` argument in the templates does NOT save it: a missing
 * variable comes back as a non-empty string, so no fallback fires.
 */
#[CoversClass(LanguageSeeder::class)]
final class LanguageSeederTest extends TestCase
{
    private const ADDON_ROOT = __DIR__ . '/../../..';

    public function testVariablesCarryEveryKeyInBothLanguages(): void
    {
        $vars = LanguageSeeder::variables();

        self::assertNotEmpty($vars);
        foreach ($vars as $name => $texts) {
            self::assertStringStartsWith('eurosite.', $name, 'an unprefixed key would collide with the core');
            self::assertArrayHasKey('en', $texts, $name);
            self::assertArrayHasKey('ro', $texts, $name);
            self::assertNotSame('', trim($texts['en']), $name);
            self::assertNotSame('', trim($texts['ro']), $name);
        }
    }

    public function testVariablesMatchLangKeysFileExactly(): void
    {
        /** @var array<string, array<string, string>> $fromFile */
        $fromFile = require self::ADDON_ROOT . '/lang_keys.php';

        self::assertSame($fromFile, LanguageSeeder::variables());
    }

    /**
     * The whitelist page's own labels (the old editor once showed them as
     * raw keys). Listed by name so a future edit cannot drop them silently;
     * the shared words are Travel Core's (travel_core.dest_*).
     */
    public function testTheWhitelistEditorLabelsAreDeliverable(): void
    {
        $vars = LanguageSeeder::variables();

        foreach ([
            'eurosite.whitelist_title',
            'eurosite.dest_mode_all',
            'eurosite.dest_mode_own',
            'eurosite.dest_mode_specific',
            'eurosite.dest_filter_own',
            'eurosite.dest_select_own',
            'eurosite.dest_catalog',
            'eurosite.dest_saved',
            'eurosite.sync_countries',
        ] as $key) {
            self::assertArrayHasKey($key, $vars, "{$key} would render as a raw key in the admin");
        }
    }

    public function testSeedHashIsAStableFingerprint(): void
    {
        $hash = LanguageSeeder::seedHash();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
        self::assertSame($hash, LanguageSeeder::seedHash(), 'the init.php probe must not reseed on every request');
    }

    /**
     * REGRESSION: init.php must reach the seeder through the CLASS, never
     * through the fn_eurosite_language_* wrappers in func.php.
     *
     * Whether func.php is included before or after init.php is not stable
     * across CS-Cart cores. On a core that loads init.php first, a
     * `function_exists('fn_eurosite_language_variables')` guard there is always
     * false, so the heal is disabled on every request with no error anywhere —
     * exactly how this addon's labels went missing. fgo_invoicing was moved off
     * that guard for the same reason; this pins that eurosite stays off it.
     */
    public function testInitPhpDoesNotDependOnFuncPhpForTheHeal(): void
    {
        $init = self::initPhpCode();
        self::assertNotSame('', $init);

        $healBlock = strstr($init, 'fn_travel_core_heal_language_keys');
        self::assertIsString($healBlock, 'init.php no longer wires up the language heal at all');

        self::assertStringNotContainsString(
            "function_exists('fn_eurosite_language_variables')",
            $init,
            'the heal must not be gated on a func.php function — that guard is load-order dependent',
        );
        self::assertStringNotContainsString(
            'fn_eurosite_language_seed_hash()',
            $init,
            'the fingerprint must come from LanguageSeeder, not from func.php',
        );
        self::assertStringContainsString(
            'LanguageSeeder::variables()',
            $init,
            'the heal must read its variables from the autoloaded class',
        );
        self::assertStringContainsString('LanguageSeeder::seedHash()', $init);

        // travel_core IS safe to probe (lower priority, so it loads first) —
        // and must be probed, or a store without it fatals in fn_init_addons().
        self::assertStringContainsString("function_exists('fn_travel_core_heal_language_keys')", $init);
        self::assertStringContainsString("function_exists('fn_travel_core_self_heal_guard')", $init);
    }

    /**
     * init.php with its comments stripped.
     *
     * The comments explain the very guard this test forbids, quoting it
     * verbatim — matching against the raw file would fail on the explanation
     * rather than on the code.
     */
    private static function initPhpCode(): string
    {
        $src = (string) file_get_contents(self::ADDON_ROOT . '/init.php');
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * dev/tools/seed-langs.php force-seeds by calling fn_<addon>_seed_language_keys().
     * Eurosite was missing from that tool entirely, so the one manual repair
     * path skipped it.
     */
    public function testTheForceSeedEntryPointExistsForTheDevTool(): void
    {
        self::assertTrue(method_exists(LanguageSeeder::class, 'seed'));

        $func = (string) file_get_contents(self::ADDON_ROOT . '/func.php');
        self::assertStringContainsString('function fn_eurosite_seed_language_keys(', $func);

        $tool = (string) file_get_contents(dirname(__DIR__, 7) . '/dev/tools/seed-langs.php');
        self::assertStringContainsString("'fn_eurosite_seed_language_keys'", $tool);
        self::assertStringContainsString("'eurosite._lang_seed_hash'", $tool);
    }
}
