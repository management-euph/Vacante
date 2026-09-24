<?php
declare(strict_types=1);
/**
 * Sphinx Holidays - Public Cron Controller
 *
 * Accessible via URL for external cron services (cron-job.org, server crontab, etc.)
 * Authentication via access_key parameter (no admin login required).
 *
 * Note: uses 'cron_mode' parameter (not 'mode') because CS-Cart reserves 'mode'
 * for the dispatch system.
 *
 * All modes are handled by Command classes via CronDispatcher.
 * See CronDispatcher::getAvailableModes() for the full list.
 *
 * ── Destination Sync URLs ──────────────────────────────────────────────
 *
 * Run/resume (incremental if previous sync exists, otherwise full):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=destinations
 *
 * Force full re-sync (ignores last sync timestamp):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=destinations&full=1
 *
 * Check progress without running (bypasses execution lock):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=destinations&status=1
 *
 * Clear state and start fresh (bypasses execution lock):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=destinations&reset=1
 *
 * Behavior:
 *   - State is saved to a JSON file after every API page (1000 items each)
 *   - If the browser is closed or process dies mid-sync, progress is preserved
 *   - Next call auto-resumes from the last saved page
 *   - State older than 6 hours with no activity is considered stale and auto-cleared
 *   - The flock-based execution lock prevents two syncs running simultaneously
 *   - status=1 and reset=1 bypass the flock (safe to call while sync is running)
 *
 * ── Add Products URLs (create CS-Cart products from unlinked hotels) ──
 *
 * Run/resume (processes unlinked hotels, skips already-linked ones):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products
 *
 * Filter by country:
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&country=TR
 *
 * Limit number of hotels to process:
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&limit=500
 *
 * Retry previously skipped hotels (all reasons):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&retry_skipped=1
 *
 * Retry only hotels skipped for invalid country:
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&retry_skipped=invalid_country
 *
 * Check progress without running (bypasses execution lock):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&status=1
 *
 * Clear state and start fresh (bypasses execution lock):
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=add_products&reset=1
 *
 * Behavior:
 *   - State is saved to a JSON file after each batch of 200 hotels
 *   - findUnlinked() naturally skips already-linked hotels (implicit resume)
 *   - If the browser is closed mid-run, next call continues where it left off
 *   - State older than 6 hours with no activity is auto-cleared
 *   - Hotels that fail category creation are marked with product_skip_reason='category_failed'
 *   - Use retry_skipped=1 to reset skip reasons and make hotels eligible again
 *
 * ── Hotel Sync URLs ────────────────────────────────────────────────────
 *
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels&country=GR
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels&destination_ids=1234,5678
 *
 * ── Board Discovery URLs ──────────────────────────────────────────────
 *
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=discover_boards
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=discover_boards&country=GR
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=discover_boards&status=1
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=discover_boards&reset=1
 *
 * ── Other Modes ───────────────────────────────────────────────────────
 *
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=package_routes
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=circuits
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=experiences
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=order_status
 *   index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=full          (runs all syncs sequentially)
 */

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// ── Authentication ──

$providedKey = RequestCoerce::string($_REQUEST, 'access_key');
$storedKey = ConfigProvider::getCronAccessKey();

header('Content-Type: text/plain; charset=utf-8');

if (empty($storedKey)) {
    http_response_code(403);
    echo "ERROR: Cron Access Key not configured in Sphinx Holidays addon settings.\n";
    exit;
}

if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    // Noted for Travel Core -> Tools: a WRONG key here is what a crontab
    // still on an old key sends after a rotation.
    if (class_exists(\Tygh\Addons\TravelCore\Cron\CronRunLog::class)) {
        \Tygh\Addons\TravelCore\Cron\CronRunLog::refused('sphinx_holidays', !empty($providedKey));
    }
    http_response_code(403);
    echo "ERROR: Invalid or missing access key.\n";
    exit;
}

// ── Ensure schema deltas are applied in this (frontend) request ──
// Same AREA gap as the SEO defaults below, but fatal rather than cosmetic:
// init.php only self-heals the schema when AREA === 'A', so a store that
// pulls new code and runs a cron URL before ever opening an admin page dies
// with "Unknown column 'geo_city'" / "Unknown column 'geocoded_at'". The cron
// is a first-class entry point for schema-dependent work, so it applies the
// deltas itself. SchemaMigrator::ensure() has a per-request static guard and
// only ALTERs what is genuinely missing (two INFORMATION_SCHEMA reads).
if (function_exists('fn_sphinx_holidays_ensure_schema')) {
    fn_sphinx_holidays_ensure_schema();
}

// ── Ensure SEO template defaults are available in this (frontend) request ──
// The init.php self-heal probe only fires in the admin area (AREA 'A'); the
// cron runs in the storefront area (AREA 'C'), where addons.sphinx_holidays.seo_*
// keys are absent from the Registry. Without them, product creation renders
// blank Page title / Meta description / Meta keywords. Seed them here (in-request)
// so add_products and other modes have the templates. Idempotent — only fills
// keys that are missing or blank, never overwrites admin-saved values.
if (function_exists('fn_sphinx_holidays_seed_seo_defaults')) {
    fn_sphinx_holidays_seed_seo_defaults();
}

// ── Parse mode ──

$mode = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'cron_mode', 'destinations')));

// ── Dispatch ──

echo "[" . date('Y-m-d H:i:s') . "] Sphinx Cron Started - Mode: {$mode}\n";

fn_log_event('general', 'runtime', [
    'message' => "Sphinx frontend cron started (mode: {$mode})",
]);

try {
    $dispatcher = new CronDispatcher();

    if (!$dispatcher->hasMode($mode)) {
        echo "Unknown mode: {$mode}\n\n";
        echo "Available modes:\n";
        foreach (CronDispatcher::getAvailableModes() as $m => $desc) {
            echo "  {$m} - {$desc}\n";
        }
    } else {
        $result = $dispatcher->dispatch($mode, TypeCoerce::toStringMap($_REQUEST));

        $success = TypeCoerce::toBool($result['success'] ?? false);
        $busy = TypeCoerce::toBool($result['busy'] ?? false);

        if ($busy) {
            echo "\n[" . date('Y-m-d H:i:s') . "] " . TypeCoerce::toString($result['message'] ?? "Mode '{$mode}' is already running.") . "\n";
        } else {
            echo "\n[" . date('Y-m-d H:i:s') . "] Cron job " . ($success ? 'completed successfully' : 'finished with errors') . ".\n";
        }

        if (!empty($result['error'])) {
            echo "Error: " . TypeCoerce::toString($result['error']) . "\n";
        }

        if (!empty($result['stats'])) {
            $s = TypeCoerce::toStringMap($result['stats']);
            echo "Stats: " . TypeCoerce::toInt($s['synced'] ?? $s['added'] ?? 0) . "/" . TypeCoerce::toInt($s['total'] ?? 0) . " synced";
            if (TypeCoerce::toInt($s['skipped'] ?? 0) > 0) {
                echo ", " . TypeCoerce::toInt($s['skipped'] ?? 0) . " skipped";
            }
            if (TypeCoerce::toInt($s['failed'] ?? 0) > 0) {
                echo ", " . TypeCoerce::toInt($s['failed'] ?? 0) . " failed";
            }
            if (TypeCoerce::toFloat($s['duration_ms'] ?? 0) > 0) {
                echo " (" . round(TypeCoerce::toFloat($s['duration_ms'] ?? 0) / 1000, 1) . "s)";
            }
            echo "\n";
        }
    }

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";

    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx frontend cron error: ' . $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);
}

exit;
