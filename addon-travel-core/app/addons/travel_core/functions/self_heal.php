<?php
declare(strict_types=1);
/**
 * Travel Core - Self-heal stamp helpers
 *
 * The addons' self-heals (schema migrations, index backfills) are
 * idempotent but not free — catalog queries against INFORMATION_SCHEMA,
 * SHOW COLUMNS, even a CREATE TABLE IF NOT EXISTS — and they used to
 * re-run on EVERY admin request. These helpers persist a per-heal
 * fingerprint in ?:storage_data so the steady state costs one indexed
 * row read; any change to the fingerprint (new migration code deployed)
 * re-arms the heal exactly once.
 *
 * Shared by travel_core and the provider addons (they depend on
 * travel_core, so these are always loaded first).
 *
 * @package TravelCore
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * TRUE when the self-heal identified by $key must run for $fingerprint.
 *
 * Fail-open: without a storage backend (partial bootstrap) the heal runs,
 * matching the pre-stamp behavior.
 */
function fn_travel_core_self_heal_due(string $key, string $fingerprint): bool
{
    if (!function_exists('fn_get_storage_data')) {
        return true;
    }

    $stored = fn_get_storage_data('travel_heal_' . $key);

    return !is_string($stored) || $stored !== $fingerprint;
}

/**
 * Persist the fingerprint after a heal run, so the next admin request skips it.
 */
function fn_travel_core_self_heal_stamp(string $key, string $fingerprint): void
{
    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data('travel_heal_' . $key, $fingerprint);
    }
}

/**
 * Heal every travel addon's settings, once per deployed version, at a point
 * in the request where CS-Cart is actually ready for it.
 *
 * NOT callable from an addon's init.php. That file is require'd by
 * fn_init_addons(), which fn_init() runs BEFORE defining CART_LANGUAGE, and
 * Settings::updateValue() -> updateValueById() -> getData() dereferences that
 * constant. Seeding a default there threw "Undefined constant
 * Tygh\CART_LANGUAGE" from inside bootstrap, i.e. a 500 on every admin page
 * including the ones needed to diagnose it. The dispatch_before_display hook
 * fires after the framework is fully initialised, so the whole Settings API
 * is usable there.
 *
 * Admin area only: creating settings is an administrative act and the
 * storefront must never race it.
 */
function fn_travel_core_heal_settings_once(): void
{
    if (!defined('AREA') || AREA !== 'A' || !function_exists('fn_travel_core_ensure_all_settings')) {
        return;
    }

    // Fingerprint the whole healing mechanism alongside the addon.xml files:
    // the migrator AND this orchestration file. The guard stamps even a
    // FAILED run (see fn_travel_core_self_heal_guard), so the only way a fix
    // ever re-runs the heal is a fingerprint change — a fix to either file
    // must re-arm on stores whose addon.xml has not changed since the bad run
    // (the CRLF label bug, the CART_LANGUAGE crash, this very move).
    $addonsRoot = dirname(__DIR__, 2);
    $fingerprint = (string) @md5_file(dirname(__DIR__) . '/src/Install/SettingsMigrator.php');
    $fingerprint .= (string) @md5_file(__FILE__);
    foreach (fn_travel_core_settings_heal_addons() as $addon) {
        $fingerprint .= (string) @md5_file($addonsRoot . '/' . $addon . '/addon.xml');
    }
    $fingerprint = md5($fingerprint);

    if (!fn_travel_core_self_heal_due('travel_settings', $fingerprint)) {
        return;
    }

    fn_travel_core_self_heal_guard('travel_settings', 'fn_travel_core_ensure_all_settings');
    fn_travel_core_self_heal_stamp('travel_settings', $fingerprint);
}

/**
 * Deliver an addon's language variables to this store, at most once per
 * (sources x installed languages) change.
 *
 * Called from every addon's init.php, in EVERY area — a deploy followed by
 * storefront-only traffic used to leave customers looking at raw
 * "_travel_core.…" keys until somebody happened to open an admin page.
 *
 * Two gates, deliberately different:
 *   - LanguageDelivery::isCurrent() is the CORRECTNESS gate. It asks the
 *     database whether every installed language carries this fingerprint, so
 *     a store that lost its rows heals without any file needing to change.
 *     This is the steady-state path: one indexed read per request.
 *   - The ?:storage_data stamp is the ATTEMPT throttle. If the writes cannot
 *     land (denied grant, collation mismatch), isCurrent() stays false
 *     forever; without this we would re-run hundreds of upserts on every
 *     request. It re-arms whenever the sources OR the store's language set
 *     change — i.e. exactly when a retry could produce a different outcome.
 *
 * @param callable():array<string, array<string, string>> $varsProvider
 * @param null|callable(array<string, array<string, string>>):void $afterSeed extra
 *                                                                            per-addon delivery that must run with the
 *                                                                            same freshly-read variables (sphinx mirrors
 *                                                                            settings labels into ?:settings_descriptions)
 */
function fn_travel_core_heal_language_keys(
    string $addon,
    callable $varsProvider,
    string $fingerprint,
    ?callable $afterSeed = null,
): void {
    $stampName = $addon . '._lang_seed_hash';

    if (\Tygh\Addons\TravelCore\Install\LanguageDelivery::isCurrent($stampName, $fingerprint)) {
        return;
    }

    $languages = \Tygh\Addons\TravelCore\Install\LanguageDelivery::storeLanguages();
    $attemptKey = 'lang_' . $addon;
    $attemptFingerprint = md5($fingerprint . '|' . implode(',', $languages));

    if (!fn_travel_core_self_heal_due($attemptKey, $attemptFingerprint)) {
        return;
    }

    // finally, NOT after the seed: when seed() throws, the guard around this
    // function swallows it, and without the stamp the heal re-entered on EVERY
    // request — several hundred upserts on a page that should cost one indexed
    // read. A heal that cannot succeed must still be attempted only once per
    // deploy, exactly as fn_travel_core_self_heal_guard's docblock requires.
    try {
        $vars = $varsProvider();
        $result = \Tygh\Addons\TravelCore\Install\LanguageDelivery::seed($stampName, $vars, $fingerprint);
        if ($afterSeed !== null) {
            $afterSeed($vars);
        }
    } finally {
        fn_travel_core_self_heal_stamp($attemptKey, $attemptFingerprint);
    }

    if ($result['failed'] !== []) {
        // Loud, because the symptom (raw keys on the storefront) is otherwise
        // indistinguishable from "nobody added the label yet".
        fn_travel_core_heal_report(
            'language seed for "' . $addon . '" could not be verified for language(s) '
            . implode(', ', $result['failed'])
            . ' — those labels will render as raw keys. Installed languages: '
            . (implode(', ', $languages) ?: '(none readable)')
            . '. Attempted ' . $result['written'] . ' upsert(s); '
            . implode('; ', array_map(
                static fn (string $lang, string $why): string => $lang . ': ' . $why,
                array_keys($result['detail']),
                array_values($result['detail']),
            )),
        );
    }
}

/**
 * Run a self-heal so that it CANNOT take the store down.
 *
 * These heals run from init.php on every admin page load. CS-Cart turns an
 * uncaught throwable into the 503 "Service unavailable" page, so a heal that
 * throws locks the admin out — including the admin pages you would need to
 * fix it. The heals touch CS-Cart's own APIs and schema, which are licensed
 * kit code that is NOT in this repository and therefore cannot be pinned by
 * a test: "it works on the deployed kit" is a hope, not a guarantee. So the
 * failure mode has to be "not healed", never "no store".
 *
 * The caller stamps the fingerprint either way, deliberately: a heal that
 * throws on this store would otherwise throw on every subsequent request
 * forever. The stamp re-arms on the next change to the healer or to any
 * addon.xml — precisely when a fix ships — so a genuine repair is not lost.
 */
function fn_travel_core_self_heal_guard(string $key, callable $heal): void
{
    try {
        $heal();
    } catch (\Throwable $e) {
        fn_travel_core_heal_report(
            'self-heal "' . $key . '" failed and was skipped — '
            . get_debug_type($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine(),
        );
    }
}

/**
 * Report a self-heal failure somewhere a human will actually find it.
 *
 * error_log() alone was not enough. It writes to the PHP error log, which on a
 * containerised store needs shell access, and on a managed host may not be
 * readable at all — so a guarded heal that failed on every request for months
 * looked exactly like a heal that had nothing to do. Guarding these heals is
 * right (a throw inside fn_init_addons() is a 503 on every page), but silence
 * is not part of the deal.
 *
 * fn_log_event puts the same line in Administration ▸ Logs, where an operator
 * can read it without a terminal. Both paths are attempted, and this function
 * itself can never throw: it is called from a catch block, and from init.php,
 * where CS-Cart's logging may not be wired up yet.
 */
function fn_travel_core_heal_report(string $message): void
{
    error_log('travel_core: ' . $message);

    if (!function_exists('fn_log_event')) {
        return;
    }

    try {
        fn_log_event('general', 'runtime', ['message' => 'travel_core self-heal: ' . $message]);
    } catch (\Throwable) {
        // Already in error_log; a logging failure must not escalate.
    }
}

/**
 * Create settings an addon.xml declares that the database never received.
 *
 * CS-Cart imports <settings> only at install/upgrade, so every setting added
 * after a store was installed is invisible there — the field is missing from
 * the settings page with no way to set it. Language keys and the schema
 * already self-heal for the same reason; this closes the third gap.
 *
 * Safe for any addon: only creates what `isExists()` says is absent, and
 * returns the names it created so callers can log or report them.
 *
 * @return list<string> settings created (empty when already in sync)
 */
function fn_travel_core_ensure_settings(string $addon, string $addonDir, string $langsDir): array
{
    if (!class_exists(\Tygh\Settings::class)) {
        return [];
    }

    return \Tygh\Addons\TravelCore\Install\SettingsMigrator::ensure($addon, $addonDir, $langsDir);
}

/**
 * The addons the settings heal covers: every known provider, then travel_core.
 *
 * Derived from TravelProviderRegistry::KNOWN_PROVIDER_ADDONS rather than
 * written out a second time. The literal list this replaces fell a provider
 * behind: eurosite was added to the registry but not here, so its
 * `cron_access_key` — declared in addon.xml, imported by CS-Cart only at
 * install/upgrade — was never created on stores installed before it. The
 * field was simply absent from the addon's settings page, with no way to set
 * it and no way to authenticate a single scheduled sync.
 *
 * PROVIDERS FIRST, travel_core LAST is load-bearing; see the caller.
 *
 * The literal fallback is for the case where the autoloader has not reached
 * this class: the heal must still cover what it covered before, never
 * silently shrink to travel_core alone.
 *
 * @return list<string>
 */
function fn_travel_core_settings_heal_addons(): array
{
    $providers = class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)
        ? \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::KNOWN_PROVIDER_ADDONS
        : ['novoton_holidays', 'sphinx_holidays', 'eurosite'];

    return [...$providers, 'travel_core'];
}

/**
 * Heal the settings of every travel addon that is installed and deployed.
 *
 * Each addon ships its own addon.xml + .po pair, so the paths are derived
 * from the addon name; addons whose directory is absent are skipped.
 *
 * PROVIDER ADDONS FIRST, travel_core last — deliberately. Retiring a
 * provider's duplicate setting carries its value into the same-named
 * travel_core setting when that one is empty; travel_core's own pass then
 * seeds addon.xml defaults into whatever is STILL empty. Providers-first
 * means an operator-configured provider value beats a repo default. And each
 * addon heals inside its own catch: the CART_LANGUAGE crash proved that one
 * addon's failure would otherwise shield the others' stale rows forever.
 *
 * @return array<string, list<string>> addon => created/removed setting names
 */
function fn_travel_core_ensure_all_settings(): array
{
    $addonsRoot = dirname(__DIR__, 2);          // …/app/addons
    $varLangs = dirname($addonsRoot, 2) . '/var/langs';

    $created = [];
    foreach (fn_travel_core_settings_heal_addons() as $addon) {
        $dir = $addonsRoot . '/' . $addon;
        if (!is_file($dir . '/addon.xml')) {
            continue;
        }

        try {
            $names = fn_travel_core_ensure_settings($addon, $dir, $varLangs);
        } catch (\Throwable $e) {
            fn_travel_core_heal_report("settings heal for {$addon} failed — " . $e->getMessage());

            continue;
        }

        if ($names !== []) {
            $created[$addon] = $names;
        }
    }

    return $created;
}
