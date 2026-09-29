<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Imports load nothing: the classes are reached only inside the heal, which
// runs on a fully initialised admin request (autoloader registered).
use Tygh\Addons\FgoInvoicing\Install\LanguageSeeder;
use Tygh\Addons\FgoInvoicing\Install\SettingsPlacement;
use Tygh\Addons\FgoInvoicing\Install\SettingsSnapshot;

/**
 * Create the settings addon.xml declares and an existing store lacks, once
 * per deployed version, through travel_core's SettingsMigrator. ADD-ONLY.
 *
 * WHY: CS-Cart creates an add-on's settings only at install. The CIF /
 * Reg. Com. / CNP field selectors were added later, so every store installed
 * before them has no rows for them. Reinstalling is NOT the cure: uninstall
 * drops ?:fgo_invoices, the only record of which orders were invoiced.
 * Without the rows the add-on still works — ConfigProvider reads a missing
 * selector as "auto-detect" — the merchant just cannot pick a field yet.
 *
 * Reuses travel_core's heal rather than copying its ~200 lines of Settings
 * API code, with the same function_exists() guards init.php uses for the
 * language heal: fgo must also install and run without travel_core, and
 * there this is a no-op. travel_core's own heal does not cover fgo (its list
 * is the travel provider add-ons), so fgo calls fn_travel_core_ensure_settings()
 * for itself.
 *
 * Runs from the dispatch_before_display hook, NEVER init.php: init.php is
 * required before CS-Cart defines CART_LANGUAGE, which
 * Settings::updateValue() dereferences (travel_core 500'd every admin page
 * that way). Admin area only: creating settings is an administrative act.
 *
 * Add-only (SettingsSnapshot): the migrator also rewrites EXISTING settings
 * (a cleared value gets the addon.xml default back, a type is re-applied).
 * For FGO that would put CodArticol SHIPPING / DISCOUNT back on the invoices
 * of a merchant who cleared those codes, and re-enable retries of the issue
 * POST. So the migrator runs only when a declared row is actually missing,
 * and every setting that existed before gets its value and type back.
 *
 * After creating rows it:
 *   - moves them under the header addon.xml puts them in (the migrator
 *     appends at the end of the form) — SettingsPlacement;
 *   - re-mirrors the addon.xml labels: the init.php language heal re-arms on
 *     the same addon.xml change but runs BEFORE this creates the rows, so its
 *     mirror missed them.
 *
 * Stamped even when the run fails (fn_travel_core_self_heal_guard reports and
 * swallows), so a store where it cannot succeed does not retry on every admin
 * page; the fingerprint re-arms it on the next change to addon.xml, the .po
 * packs or the healing code.
 */
function fn_fgo_invoicing_heal_settings_once(): void
{
    if (
        !defined('AREA') || AREA !== 'A'
        || !function_exists('fn_travel_core_ensure_settings')
        || !function_exists('fn_travel_core_store_path')
        || !function_exists('fn_travel_core_self_heal_due')
        || !function_exists('fn_travel_core_self_heal_guard')
        || !function_exists('fn_travel_core_self_heal_stamp')
    ) {
        return;
    }

    // Store paths, not __DIR__: on a store whose add-ons are symlinked in,
    // __DIR__ resolves into the source checkout (fn_travel_core_store_path).
    $addonDir = fn_travel_core_store_path('app/addons/fgo_invoicing');
    $langsDir = fn_travel_core_store_path('var/langs');

    $fingerprint = md5(
        (string) @md5_file($addonDir . '/addon.xml')
        . (string) @md5_file($langsDir . '/en/addons/fgo_invoicing.po')
        . (string) @md5_file($langsDir . '/ro/addons/fgo_invoicing.po')
        . (string) @md5_file(fn_travel_core_store_path('app/addons/travel_core/src/Install/SettingsMigrator.php'))
        . (string) @md5_file(dirname(__DIR__) . '/src/Install/SettingsPlacement.php')
        . (string) @md5_file(dirname(__DIR__) . '/src/Install/SettingsSnapshot.php')
        . (string) @md5_file(__FILE__),
    );

    if (!fn_travel_core_self_heal_due('fgo_invoicing_settings', $fingerprint)) {
        return;
    }

    fn_travel_core_self_heal_guard('fgo_invoicing_settings', static function () use ($addonDir, $langsDir): void {
        // The declared names come from the addon.xml shipped with this code
        // (the same file the store path points at, symlinked or not).
        $before = SettingsSnapshot::take();
        $declared = SettingsSnapshot::declaredNames(dirname(__DIR__) . '/addon.xml');
        if ($before === [] || SettingsSnapshot::missing($declared, $before) === []) {
            return; // not installed, or nothing to add: never touch existing rows
        }

        fn_travel_core_ensure_settings('fgo_invoicing', $addonDir, $langsDir);
        SettingsSnapshot::restore($before, SettingsSnapshot::take());
        SettingsPlacement::apply();
        LanguageSeeder::mirrorSettingsDescriptions();
    });
    fn_travel_core_self_heal_stamp('fgo_invoicing_settings', $fingerprint);
}
