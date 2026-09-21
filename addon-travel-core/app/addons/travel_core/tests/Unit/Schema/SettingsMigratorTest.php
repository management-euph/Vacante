<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the settings self-heal against the real CS-Cart API.
 *
 * Field failure: travel_core's addon.xml declared 30 settings; the store's
 * database held 26. The four missing ones were the whole geocoding section,
 * so the switch could not be turned on from the settings page at all. CS-Cart
 * imports <settings> only at install/upgrade and never re-reads addon.xml on
 * a deploy, and this was the one delivery path with no self-heal (language
 * keys and the schema already had theirs).
 *
 * The API facts below were read from the store's own Tygh\Settings, not
 * assumed — in particular that update() DISCARDS 'value', which would have
 * created settings that exist but read empty.
 */
final class SettingsMigratorTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    /**
     * The same file with every comment removed.
     *
     * Asserting against raw source lets a docblock satisfy a claim about the
     * code — which it did here: a test for "derives the list from
     * KNOWN_PROVIDER_ADDONS" passed against a version that only NAMED the
     * constant in a comment while iterating a hand-written list.
     */
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function testUsesTheSupportedCreationApiNotRawSql(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        // Section lookup, idempotency, creation — all through Tygh\Settings.
        self::assertStringContainsString('getSectionByName($addon, Settings::ADDON_SECTION)', $m);
        self::assertStringContainsString('$settings->isExists($name, $addon)', $m);
        self::assertStringContainsString('$settings->update(', $m);
        // No hand-written INSERT into the settings tables.
        self::assertStringNotContainsString('INSERT INTO ?:settings_', $m);
        self::assertStringNotContainsString('REPLACE INTO ?:settings_', $m);
    }

    /**
     * updateSettingObject() does `if (!empty($data['value'])) unset($data['value']);`
     * — a setting created in one call would exist and read empty, which is a
     * worse failure than the missing field because it looks configured.
     */
    public function testSeedsTheDefaultValueInASecondCallBecauseUpdateStripsIt(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('$settings->updateValue($name, $item[\'default\'], $addon)', $m);
        self::assertStringContainsString("update() strips 'value'", $m);
    }

    /**
     * update() runs checkEdition() and silently returns false when it fails,
     * and handler/parent_id are NOT NULL. Copying the shape of an existing
     * sibling setting cannot fail either check.
     */
    public function testCopiesRowShapeFromAnExistingSiblingSetting(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('siblingTemplate(', $m);
        self::assertStringContainsString("\$template['edition_type']", $m);
        self::assertStringContainsString("\$template['section_tab_id']", $m);
        self::assertStringContainsString("\$template['is_global']", $m);
        // NOT NULL columns REPLACE INTO would otherwise reject under strict mode.
        self::assertStringContainsString("'handler' => ''", $m);
        self::assertStringContainsString("'parent_id' => 0", $m);
    }

    public function testTypeMapUsesTheCsCartEnumNotLiteralChars(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('use Tygh\Enum\SettingTypes;', $m);
        foreach ([
            "'checkbox' => SettingTypes::CHECKBOX",
            "'input' => SettingTypes::INPUT",
            "'selectbox' => SettingTypes::SELECTBOX",
            "'header' => SettingTypes::HEADER",
        ] as $pair) {
            self::assertStringContainsString($pair, $m);
        }
    }

    /**
     * A Windows checkout stores the .po files with CRLF (there is no
     * .gitattributes forcing LF), so a \n-only pattern matched nothing and the
     * first heal created the four geocoding fields with BLANK labels. The
     * parser must tolerate CRLF, and — because isExists() would skip those
     * rows forever — the heal must also be able to give an existing setting
     * its label back.
     */
    public function testToleratesCrlfPoFilesAndRepairsLabelLessSettings(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        self::assertStringContainsString('\r?\nmsgid', $m);
        self::assertStringContainsString('\r?\nmsgstr', $m);

        self::assertStringContainsString('repairDescriptions(', $m);
        // Repair must not clobber a label an admin already edited.
        self::assertStringContainsString('$settings->getDescription(', $m);
        self::assertStringContainsString('already labelled', $m);

        // The stamp has to include the migrator itself, or a fix to the healer
        // never re-runs on a store whose addon.xml is unchanged.
        self::assertStringContainsString(
            "md5_file(dirname(__DIR__) . '/src/Install/SettingsMigrator.php')",
            self::src('functions/self_heal.php'),
        );
    }

    /**
     * The endpoint and contact email must arrive pre-filled: a store that has
     * to discover both the Nominatim URL and that a contact is mandatory
     * before geocoding will run is a store where geocoding never runs.
     */
    public function testGeocodingDefaultsArePreFilledAndEmptyValuesGetRepaired(): void
    {
        $xml = self::src('addon.xml');
        $pos = strpos($xml, '<item id="geocoding_endpoint">');
        self::assertIsInt($pos);
        self::assertStringContainsString(
            '<default_value>https://nominatim.openstreetmap.org</default_value>',
            substr($xml, $pos, 200),
        );

        $pos = strpos($xml, '<item id="geocoding_contact_email">');
        self::assertIsInt($pos);
        self::assertStringContainsString('<default_value>od18in@yahoo.com</default_value>', substr($xml, $pos, 200));

        // Defaults are seeded on create; settings that already exist with an
        // empty value are repaired too, or a store healed before this would
        // keep showing blank fields forever.
        $m = self::src('src/Install/SettingsMigrator.php');
        self::assertStringContainsString('repairValue(', $m);
        self::assertStringContainsString('$settings->getValue($name, $addon)', $m);
        // Never clobber a value an admin actually set.
        self::assertStringContainsString("\$current !== null && \$current !== ''", $m);
    }

    /**
     * The heal runs from the dispatch_before_display hook, NEVER from
     * init.php. Addon init.php files are require'd by fn_init_addons(),
     * which fn_init() runs BEFORE defining CART_LANGUAGE — and
     * Settings::updateValue() dereferences that constant, so seeding a
     * default from init threw "Undefined constant Tygh\CART_LANGUAGE" and
     * 500'd every admin page. By dispatch time the framework is fully
     * initialised (it is the same context the settings pages themselves use).
     */
    public function testHealRunsForEveryTravelAddonAtDispatchTimeAndIsStampGated(): void
    {
        $heal = self::src('functions/self_heal.php');
        self::assertStringContainsString('function fn_travel_core_ensure_all_settings()', $heal);
        self::assertStringContainsString('function fn_travel_core_heal_settings_once()', $heal);
        // The covered addons are DERIVED, never written out again here. A
        // second literal list is what left eurosite unhealed: it was added to
        // the registry, not to this loop, so its cron access key never
        // reached a single store installed before it.
        self::assertStringContainsString('function fn_travel_core_settings_heal_addons()', $heal);
        self::assertStringContainsString(
            'foreach (fn_travel_core_settings_heal_addons() as $addon)',
            $heal,
        );
        // Providers first, travel_core LAST: a value carried out of a retired
        // provider row lands in travel_core before default-seeding, so an
        // operator-configured value beats a repo default.
        self::assertStringContainsString(
            'return [...$providers, \'travel_core\'];',
            $heal,
        );
        // One addon's failure must not shield the others' stale rows — the
        // CART_LANGUAGE crash in travel_core's pass did exactly that to
        // novoton's retirement.
        $loopPos = strpos($heal, 'foreach (fn_travel_core_settings_heal_addons() as $addon) {
        $dir');
        self::assertIsInt($loopPos);
        self::assertStringContainsString('} catch (\Throwable $e) {', substr($heal, $loopPos, 700));

        // Stamp-gated through the guard, fingerprinted on the migrator, THIS
        // orchestration file (__FILE__) and every covered addon.xml: the
        // guard stamps even a failed run, so only a fingerprint change ever
        // re-runs the heal — any fix to the mechanism must re-arm it.
        self::assertStringContainsString("fn_travel_core_self_heal_due('travel_settings'", $heal);
        self::assertStringContainsString('@md5_file(__FILE__)', $heal);
        self::assertStringContainsString(
            "fn_travel_core_self_heal_guard('travel_settings', 'fn_travel_core_ensure_all_settings')",
            $heal,
        );
        self::assertStringContainsString("fn_travel_core_self_heal_stamp('travel_settings'", $heal);

        // Wired to the dispatch hook, BEFORE its storefront-only early return
        // (the heal gates itself to AREA 'A').
        $hook = self::src('hooks/cart_hooks.php');
        $callPos = strpos($hook, 'fn_travel_core_heal_settings_once();');
        $areaPos = strpos($hook, "AREA !== 'C'");
        self::assertIsInt($callPos);
        self::assertIsInt($areaPos);
        self::assertLessThan($areaPos, $callPos);

        // And init.php stays out of it entirely.
        $init = self::src('init.php');
        self::assertStringNotContainsString('fn_travel_core_ensure_all_settings', $init);
        self::assertStringNotContainsString("fn_travel_core_self_heal_due('travel_settings'", $init);
    }

    /**
     * Every provider the registry knows must be healed — and be healable.
     *
     * Field failure, second round: eurosite shipped `cron_access_key` in its
     * addon.xml, but the settings heal iterated a hand-written list that
     * still read novoton + sphinx + travel_core. CS-Cart imports <settings>
     * only at install/upgrade, so on a store installed before that item the
     * row never existed: the "Cron access key" field was absent from the
     * settings page, every scheduled sync URL answered 403, and the
     * dashboard's "Generate a key" button wrote to a setting that was not
     * there and reported success.
     *
     * Two literal lists of the same thing is the bug. This pins the union:
     * the heal derives its addons from KNOWN_PROVIDER_ADDONS, and each of
     * those addons carries what the migrator needs to build a usable field —
     * an addon.xml to read the declaration from, and an English .po to read
     * the label from, or the healed setting renders as a raw key.
     */
    public function testEveryKnownProviderIsCoveredByTheSettingsHealAndHealable(): void
    {
        $heal = self::code('functions/self_heal.php');
        self::assertStringContainsString(
            '? \\Tygh\\Addons\\TravelCore\\Services\\TravelProviderRegistry::KNOWN_PROVIDER_ADDONS',
            $heal,
            'the settings heal must derive its addon list, not repeat it',
        );

        $repoRoot = dirname(__DIR__, 7);
        $dirs = [
            'novoton_holidays' => '/addon-novoton-holidays',
            'sphinx_holidays' => '/addon-sphinx-holidays',
            'eurosite' => '/eurosite_addon',
        ];

        foreach (\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::KNOWN_PROVIDER_ADDONS as $addon) {
            self::assertArrayHasKey($addon, $dirs, "new provider {$addon} needs a directory mapping here");
            $root = $repoRoot . $dirs[$addon];

            self::assertFileExists($root . '/app/addons/' . $addon . '/addon.xml');
            self::assertFileExists(
                $root . '/var/langs/en/addons/' . $addon . '.po',
                "{$addon} has no English .po, so any healed setting would render as a raw key",
            );
        }

        // The fallback used when the autoloader has not reached the registry
        // must not silently shrink the coverage either. Matched against that
        // one line: anywhere in the file, an unrelated mention would do.
        self::assertSame(
            1,
            preg_match('/:\s*(\[[^\]]*\]);/', $heal, $m),
            'the derived list needs a literal fallback for the no-autoloader case',
        );
        foreach (\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::KNOWN_PROVIDER_ADDONS as $addon) {
            self::assertStringContainsString("'{$addon}'", $m[1]);
        }
    }

    /**
     * The specific setting the drift hid, end to end: declared, labelled, and
     * reachable from the code that reads it.
     */
    public function testEurositeCronAccessKeyIsDeclaredAndLabelled(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $xml = (string) file_get_contents($repoRoot . '/eurosite_addon/app/addons/eurosite/addon.xml');
        $po = (string) file_get_contents($repoRoot . '/eurosite_addon/var/langs/en/addons/eurosite.po');

        self::assertStringContainsString('<item id="cron_access_key">', $xml);
        self::assertStringContainsString('msgctxt "SettingsOptions::eurosite::cron_access_key"', $po);

        // The dashboard button must create the row when it is missing — an
        // updateValue() on a non-existent setting is a silent no-op, which is
        // how it came to report success while changing nothing.
        $ctrl = (string) file_get_contents(
            $repoRoot . '/eurosite_addon/app/addons/eurosite/controllers/backend/eurosite.php',
        );
        $pos = strpos($ctrl, "\$mode === 'generate_cron_key'");
        self::assertIsInt($pos);
        $block = substr($ctrl, $pos, 3200);
        self::assertStringContainsString("isExists('cron_access_key', 'eurosite')", $block);
        self::assertStringContainsString('fn_travel_core_ensure_settings(', $block);
        // And verify the write landed rather than trusting it — against the
        // stored value, not the bootstrap-time Registry the ConfigProvider
        // reads, which cannot see a write made in this same request.
        self::assertStringContainsString("\$settings->getValue('cron_access_key', 'eurosite')", $block);
        self::assertStringContainsString('if ($stored !== $newKey) {', $block);
        self::assertStringNotContainsString('ConfigProvider::getCronAccessKey() !==', $block);
    }

    /**
     * The mirror image of the creation path: a setting deleted from addon.xml
     * keeps rendering on stores that already have the row, because CS-Cart
     * builds the page from ?:settings_objects. That is how geocoding became
     * configurable in two places at once, with novoton's copy able to
     * contradict the shared Travel Core one.
     */
    public function testRetiredSettingsAreDeletedFromStoresThatStillHaveThem(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        // Explicit list only — "delete anything absent from addon.xml" would
        // destroy settings created by other means or by a newer version.
        self::assertStringContainsString('private const array RETIRED', $m);
        self::assertStringContainsString("'novoton_holidays' => [", $m);
        foreach (['geocoding_header', 'geocoding_enabled', 'geocoding_contact_email', 'geocoding_endpoint'] as $name) {
            self::assertStringContainsString("'{$name}',", $m);
        }

        // Removal runs BEFORE the create loop, so a retired name can never be
        // re-created in the same pass.
        $retirePos = strpos($m, '$retired = self::retire($addon);');
        $createPos = strpos($m, 'foreach ($declared as $item)');
        self::assertIsInt($retirePos);
        self::assertIsInt($createPos);
        self::assertLessThan($createPos, $retirePos);

        // The kit is not in this repository, so the call is signature-guarded
        // rather than assumed — a wrong guess would fatal on every admin page.
        self::assertStringContainsString("method_exists(\$settings, 'removeById')", $m);
        self::assertStringContainsString('getNumberOfRequiredParameters() > 1', $m);
        self::assertStringContainsString('$settings->removeById($objectId)', $m);

        // Retirement is a MOVE, not just a delete: an operator-configured
        // provider value is carried into the same-named EMPTY travel_core
        // setting BEFORE the row is removed (a failed carry keeps the source),
        // and a travel_core value that already exists is never overwritten.
        $carryPos = strpos($m, 'self::carryValueToCore($addon, $name);');
        $removePos = strpos($m, '$settings->removeById($objectId);');
        self::assertIsInt($carryPos);
        self::assertIsInt($removePos);
        self::assertLessThan($removePos, $carryPos);

        self::assertStringContainsString("isExists(\$name, 'travel_core')", $m);
        self::assertStringContainsString("getValue(\$name, 'travel_core')", $m);
        self::assertStringContainsString("updateValue(\$name, \$value, 'travel_core')", $m);
    }

    /**
     * A heal that throws must degrade to "not healed", never to "no store".
     *
     * These run from init.php on every admin page load, and CS-Cart turns an
     * uncaught throwable into the 503 "Service unavailable" page — which takes
     * out the admin pages you would need to fix it. They call into the CS-Cart
     * kit, which is licensed code outside this repository and so cannot be
     * pinned by any test here, so the guard is the only real protection.
     */
    public function testAFailingHealCannotTakeTheAdminDown(): void
    {
        $heal = self::src('functions/self_heal.php');
        self::assertStringContainsString('function fn_travel_core_self_heal_guard(', $heal);
        self::assertStringContainsString('catch (\Throwable $e)', $heal);

        // Every per-request heal call site goes through the guard. The schema
        // heal stays in init.php (db_*-only, safe there); the settings heal
        // lives in self_heal.php and runs at dispatch time.
        $init = self::src('init.php');
        self::assertStringContainsString("fn_travel_core_self_heal_guard('travel_core_schema'", $init);
        self::assertStringNotContainsString('        fn_travel_core_ensure_schema();', $init);
        self::assertStringContainsString("fn_travel_core_self_heal_guard('travel_settings'", $heal);

        $sphinx = (string) file_get_contents(
            dirname(__DIR__, 6) . '/../addon-sphinx-holidays/app/addons/sphinx_holidays/init.php',
        );
        self::assertStringContainsString("fn_travel_core_self_heal_guard('sphinx_schema'", $sphinx);

        // Deleting settings is the riskiest step — guard each one so a single
        // refusal neither aborts the rest nor escapes the heal.
        $m = self::src('src/Install/SettingsMigrator.php');
        $removePos = strpos($m, '$settings->removeById($objectId);');
        self::assertIsInt($removePos);
        self::assertStringContainsString('try {', substr($m, $removePos - 200, 200));
    }

    public function testLabelsComeFromThePoFilesTheImporterWouldHaveRead(): void
    {
        $m = self::src('src/Install/SettingsMigrator.php');

        // CS-Cart routes msgctxt scopes to settings_descriptions; the healed
        // rows must land in the same table or the page renders raw keys.
        self::assertStringContainsString('Settings(Options|Tooltips)::', $m);
        self::assertStringContainsString('Settings::SETTING_DESCRIPTION', $m);
        self::assertStringContainsString('updateDescription', $m);
    }
}
