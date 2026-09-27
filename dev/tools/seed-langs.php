<?php

/**
 * seed-langs.php — sandbox language-seed diagnostic + force-seeder.
 *
 * WHY: CS-Cart imports addon language variables (addon.xml / .po) only when an
 * addon is INSTALLED. Labels added in later releases reach existing stores
 * through each addon's runtime self-heal seeder (an init.php probe compares a
 * stored stamp against a stat-based fingerprint of addon.xml + lang_keys.php
 * and reseeds on change). When a storefront still renders raw keys such as
 * "_travel_core.your_booking_details", either that probe never fired or the
 * store is loading stale addon files. This page shows exactly which, and can
 * force-run all three addons' seeders.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev, so this file is served by the
 * container's PHP as a real page. It refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/seed-langs.php          → diagnose only
 *   http://localhost:8080/dev/tools/seed-langs.php?force=1  → diagnose, then
 *       force-seed every addon's language keys + clear the CS-Cart cache
 *   CLI: docker compose exec app php /var/www/html/dev/tools/seed-langs.php [force]
 *
 * NOTE: loading this page bootstraps CS-Cart with AREA 'A', and the bootstrap
 * itself runs every enabled addon's init.php self-heal probe BEFORE anything
 * below prints — so if a probe was merely waiting for an admin request, the
 * bootstrap already healed the store and the report shows the healed state.
 * Everything printed is post-bootstrap.
 */

$sl_is_cli = PHP_SAPI === 'cli';

if (!$sl_is_cli) {
    $sl_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($sl_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("seed-langs is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$sl_docroot = dirname(__DIR__, 2);
if (!is_file($sl_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$sl_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $sl_docroot . '/init.php';

$sl_force = $sl_is_cli
    ? in_array('force', array_slice((array) ($GLOBALS['argv'] ?? []), 1), true)
    : !empty($_GET['force']);

// ---------------------------------------------------------------------------

/** name => [seeder fn, hash fn, stamp lang-var] per addon, in seed order. */
$sl_addons = [
    'travel_core' => [
        'fn_travel_core_seed_language_keys',
        'fn_travel_core_language_seed_hash',
        'travel_core._lang_seed_hash',
    ],
    'novoton_holidays' => [
        'fn_novoton_holidays_seed_language_keys',
        'fn_novoton_holidays_language_seed_hash',
        'novoton_holidays._lang_seed_hash',
    ],
    'sphinx_holidays' => [
        'fn_sphinx_holidays_seed_language_keys',
        'fn_sphinx_holidays_language_seed_hash',
        'sphinx_holidays._lang_seed_hash',
    ],
    'fgo_invoicing' => [
        'fn_fgo_invoicing_seed_language_keys',
        'fn_fgo_invoicing_language_seed_hash',
        'fgo_invoicing._lang_seed_hash',
    ],
    'eurosite' => [
        'fn_eurosite_seed_language_keys',
        'fn_eurosite_language_seed_hash',
        'eurosite._lang_seed_hash',
    ],
];

/**
 * The labels the user actually sees broken: the shared checkout booking card
 * (travel_core, seeded only via lang_keys.php) + the Appearance dropdown label,
 * the PDP Reserve button, and novoton's search-results offer counter.
 */
$sl_sentinels = [
    'travel_core.your_booking_details',
    'travel_core.check_in',
    'travel_core.check_out',
    'travel_core.total_stay',
    'travel_core.for',
    'travel_core.occupancy',
    'travel_core.guest_names',
    'travel_core.holder',
    'travel_core.meal_plan',
    'travel_core.edit_guest_details',
    'travel_core.save_changes',
    'travel_core.guest_details_invalid',
    'travel_core.price_updated_badge',
    'travel_core.price_dropped_badge',
    'travel_core.you_selected',
    'travel_core.selected_line',
    'travel_core.change_selection',
    'travel_core.cancel_cost_title',
    'travel_core.booking_conditions_link',
    'travelcore_template',
    'travel_core.reserve_now',
    'novoton_holidays.n_offers',
    // The eurosite whitelist editor — every one of these rendered as a raw
    // "_eurosite.*" key in the admin because the heal was disabled.
    'eurosite.countries',
    'eurosite.cities',
    'eurosite.own_offer_cities',
    'eurosite.whitelist_summary',
    'eurosite.whitelisted_countries',
    'eurosite.whitelisted_cities',
    'eurosite.save_whitelist',
    'eurosite.remove_all',
];

function sl_line(string $s = ''): void
{
    echo $s, "\n";
}

/**
 * Stamps are written PER LANGUAGE, and only for a language whose labels were
 * read back from the database afterwards — so a missing entry here means that
 * language's labels genuinely did not land.
 *
 * @return array<string,string> lang_code => fingerprint
 */
function sl_stamps(string $name): array
{
    return sl_values($name);
}

/** @return array<string,string> lang_code => value */
function sl_values(string $name): array
{
    $rows = db_get_array(
        "SELECT lang_code, value FROM ?:language_values WHERE name = ?s ORDER BY lang_code",
        $name
    );
    $out = [];
    foreach ((array) $rows as $row) {
        $out[(string) $row['lang_code']] = (string) $row['value'];
    }

    return $out;
}

/** @return list<string> */
function sl_store_languages(): array
{
    static $cached = null;
    if ($cached === null) {
        $cached = array_map('strval', (array) db_get_fields("SELECT lang_code FROM ?:languages ORDER BY lang_code"));
    }

    return $cached;
}

function sl_file_stat(string $file): string
{
    $stat = @stat($file);
    if (!is_array($stat)) {
        return 'ABSENT';
    }

    // CRLF makes every deployed file bigger than its repo counterpart by
    // exactly its line count, which turns any "does the store match the repo?"
    // size comparison into a false discrepancy. Worth naming rather than
    // re-deriving each time.
    $head = (string) @file_get_contents($file, false, null, 0, 65536);
    $eol = str_contains($head, "\r\n") ? '  eol=CRLF' : '  eol=LF';

    return 'size=' . $stat['size'] . '  mtime=' . date('Y-m-d H:i:s', (int) $stat['mtime']) . $eol;
}

/**
 * Can this store write to ?:language_values at all?
 *
 * The one question the label census cannot answer. If every seeder reports
 * success and the rows are still absent, the writes are being rejected — a
 * denied GRANT, a read-only replica, a collation the value cannot survive. One
 * sentinel row, inserted, read back, deleted.
 */
function sl_write_probe(): string
{
    $name = 'travel_core._write_probe';
    $lang = sl_store_languages()[0] ?? 'en';
    $value = 'probe-' . substr(md5((string) getmypid() . $name), 0, 12);

    try {
        db_query(
            "INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)
             ON DUPLICATE KEY UPDATE value = ?s",
            $name, $lang, $value, $value
        );
    } catch (\Throwable $e) {
        return 'INSERT THREW — ' . get_debug_type($e) . ': ' . $e->getMessage();
    }

    $back = db_get_field(
        "SELECT value FROM ?:language_values WHERE name = ?s AND lang_code = ?s LIMIT 1",
        $name, $lang
    );
    @db_query("DELETE FROM ?:language_values WHERE name = ?s", $name);

    if ((string) $back === $value) {
        return 'OK — wrote and read back a row in lang "' . $lang . '"';
    }

    return 'FAILED — wrote "' . $value . '" to lang "' . $lang . '" but read back '
        . (is_scalar($back) ? '"' . (string) $back . '"' : get_debug_type($back))
        . '. The database is rejecting writes to ?:language_values.';
}

function sl_report_addon(string $addon, array $fns, string $docroot): void
{
    [$seederFn, $hashFn, $stampName] = $fns;
    $dir = $docroot . '/app/addons/' . $addon;

    sl_line("== {$addon} ==");

    $status = db_get_field("SELECT status FROM ?:addons WHERE addon = ?s", $addon);
    sl_line('  installed status:   ' . ($status === null || $status === ''
        ? 'NOT INSTALLED'
        : $status . ($status === 'A' ? ' (active)' : ' (NOT active — its init.php never loads!)')));

    $link = is_link($dir) ? 'symlink -> ' . (string) readlink($dir)
        : (is_dir($dir) ? 'real directory (NOT the repo symlink — possibly stale copy)' : 'MISSING');
    sl_line('  addon dir:          ' . $dir);
    sl_line('                      ' . $link);

    sl_line('  seeder function:    ' . $seederFn . '  ' . (function_exists($seederFn) ? 'loaded' : 'NOT LOADED'));

    // THE gate init.php actually tests. Checking only the seeder above missed
    // the case where functions/self_heal.php is stale: init.php looks current,
    // the block is skipped, and nothing is logged.
    sl_line('  heal function:      fn_travel_core_heal_language_keys  '
        . (function_exists('fn_travel_core_heal_language_keys') ? 'loaded' : 'NOT LOADED — init.php skips the heal silently!'));

    // The ?:storage_data attempt throttle. Invisible until now: once written,
    // the heal returns early even while the labels are still missing.
    $attempt = function_exists('fn_get_storage_data')
        ? fn_get_storage_data('travel_heal_lang_' . $addon)
        : null;
    sl_line('  attempt throttle:   ' . (is_string($attempt) && $attempt !== ''
        ? $attempt . '  (a run has already been attempted for this deploy + language set)'
        : 'none — the next request will attempt a seed'));

    $stored = sl_stamps($stampName);
    $computed = function_exists($hashFn) ? (string) $hashFn() : null;
    sl_line('  live fingerprint:   ' . ($computed ?? '(hash function not loaded)'));
    if ($stored === []) {
        sl_line('  stamped languages:  NONE — the seeder has never completed on this store');
    } else {
        $sl_parts = [];
        foreach (sl_store_languages() as $sl_lang) {
            $sl_parts[] = $sl_lang . '=' . (!isset($stored[$sl_lang])
                ? 'MISSING'
                : ($stored[$sl_lang] === $computed ? 'current' : 'STALE'));
        }
        sl_line('  stamped languages:  ' . implode('  ', $sl_parts));
        sl_line('                      (any MISSING/STALE re-arms the probe on the next request)');
    }

    sl_line('  fingerprint inputs:');
    foreach (['addon.xml', 'lang_keys.php'] as $f) {
        sl_line('    ' . str_pad($f, 14) . sl_file_stat($dir . '/' . $f));
    }

    // What the container actually loads: a stale init.php (old image copy, dead
    // symlink) is the one failure the DB can't show.
    $init = (string) @file_get_contents($dir . '/init.php');
    if ($init === '') {
        $gate = 'init.php UNREADABLE';
    } elseif (str_contains($init, 'fn_travel_core_heal_language_keys(')) {
        $gate = 'shared heal, guarded, every area (current)';
    } elseif (!str_contains($init, $seederFn)) {
        $gate = 'NO lang self-heal probe in the loaded init.php — stale copy, update the container files';
    } else {
        $gate = 'OLD probe: single "en" stamp row, no read-back verification'
            . (str_contains($init, "AREA === 'A'") ? ', ADMIN-GATED' : '')
            . ' — update the container files';
    }
    sl_line('  loaded init gate:   ' . $gate);
    sl_line();
}

function sl_report_sentinels(array $sentinels): int
{
    sl_line('== label rows in ?:language_values ==');
    $missing = 0;
    foreach ($sentinels as $key) {
        $values = sl_values($key);
        if ($values === []) {
            sl_line('  ' . str_pad($key, 36) . 'MISSING (all languages)');
            $missing++;
            continue;
        }
        $parts = [];
        foreach ($values as $lang => $value) {
            $parts[] = $lang . "='" . $value . "'";
        }
        sl_line('  ' . str_pad($key, 36) . implode('  ', $parts));
    }
    sl_line();

    return $missing;
}

// ---------------------------------------------------------------------------

sl_line('seed-langs — addon language self-heal diagnostic (' . date('Y-m-d H:i:s') . ')');
sl_line('(state below is AFTER bootstrap: the addons\' own init probes already ran for this request)');
sl_line();

sl_line('store languages:    ' . implode(', ', sl_store_languages())
    . '   (every one of these gets rows: exact source match, else its base'
    . ' language, else English)');
sl_line();

foreach ($sl_addons as $sl_addon => $sl_fns) {
    sl_report_addon($sl_addon, $sl_fns, $sl_docroot);
}

$sl_missing = sl_report_sentinels($sl_sentinels);

if ($sl_force) {
    sl_line('== force seeding ==');
    sl_line('  write probe:      ' . sl_write_probe());
    foreach ($sl_addons as $sl_addon => $sl_fns) {
        $sl_seeder = $sl_fns[0];
        if (!function_exists($sl_seeder)) {
            sl_line('  skipped ' . $sl_seeder . ' — not loaded (addon disabled or files missing)');
            continue;
        }
        // Unguarded on purpose: init.php wraps the heal so a throw cannot 503
        // the store, which also means a throw is invisible there. Here we WANT
        // to see it.
        try {
            $sl_seeder();
            sl_line('  ran ' . $sl_seeder . '()');
        } catch (\Throwable $sl_e) {
            sl_line('  THREW in ' . $sl_seeder . '() — ' . get_debug_type($sl_e) . ': ' . $sl_e->getMessage());
            sl_line('              @ ' . $sl_e->getFile() . ':' . $sl_e->getLine());
        }
    }
    if (function_exists('fn_clear_cache')) {
        fn_clear_cache();
        sl_line('  cleared CS-Cart cache (var/cache)');
    }
    sl_line();

    $sl_missing = sl_report_sentinels($sl_sentinels);
    sl_line($sl_missing === 0
        ? 'VERDICT: all labels seeded. Hard-refresh the storefront (Ctrl+F5) — done.'
        : "VERDICT: {$sl_missing} label(s) STILL missing after force-seeding — the loaded lang_keys.php"
            . ' predates those keys. Check the "addon dir" lines above: the container must load the'
            . ' repo files (git pull in the WSL clone, then re-run docker/fullstore/link-addons.sh).');
} elseif ($sl_missing > 0) {
    sl_line("VERDICT: {$sl_missing} label(s) missing. The probes above already ran during this bootstrap,");
    sl_line('so a stamp MISMATCH shown above means the reseed is not reaching the DB — most likely the');
    sl_line('container loads stale addon files (see the "addon dir" / "loaded init gate" lines).');
    sl_line('Re-run with ?force=1 (or CLI arg "force") to seed everything unconditionally right now.');
} else {
    sl_line('VERDICT: every checked label is present. If the storefront still shows raw "_travel_core…"');
    sl_line('keys, hard-refresh (Ctrl+F5) and clear var/cache — the rows are in the database.');
}
