<?php

/**
 * sphinx-relink.php — sandbox report + repair for sphinx hotel/product links.
 *
 * WHY: a sphinx hotel product page (booking form, location line) resolves
 * through ?:sphinx_hotels.product_id. When a product exists in CS-Cart but no
 * hotel row points at it — fresh addon reinstall, hotels table re-synced, a
 * hotel that arrived after the last sync — the page renders as a plain catalog
 * item. The repair is HotelSyncService::relinkExistingProducts(): fetch each
 * such hotel from the Sphinx API by the id inside its product code and
 * re-insert it with the link.
 *
 * PRODUCTION USES THE ADMIN: Sphinx → Product links
 * (admin.php?dispatch=sphinx_holidays.product_links) shows the same report and
 * runs the same repair; dev/ ships only in the sandbox. This page exists for
 * container debugging without an admin session (and works from the CLI), and
 * it calls the SAME ProductLinkAuditor service so the two cannot drift.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/sphinx-relink.php          → report only
 *   http://localhost:8080/dev/tools/sphinx-relink.php?force=1  → run the relink
 *   CLI: docker compose exec app php /var/www/html/dev/tools/sphinx-relink.php [force]
 */

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;
use Tygh\Addons\SphinxHolidays\Services\ProductLinkAuditor;

$sr_is_cli = PHP_SAPI === 'cli';

if (!$sr_is_cli) {
    $sr_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($sr_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("sphinx-relink is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$sr_docroot = dirname(__DIR__, 2);
if (!is_file($sr_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$sr_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $sr_docroot . '/init.php';

$sr_force = $sr_is_cli
    ? in_array('force', array_slice((array) ($GLOBALS['argv'] ?? []), 1), true)
    : !empty($_GET['force']);

function sr_line(string $s = ''): void
{
    echo $s, "\n";
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

sr_line('sphinx-relink — hotel/product link report (' . date('Y-m-d H:i:s') . ')');
sr_line();

if (!class_exists(Container::class)) {
    exit("sphinx_holidays is not installed/active in this store.\n");
}

$sr_prefix = ConfigProvider::getProductCodePrefix();

// SAME report the admin page renders (Sphinx → Product links) — one
// implementation, so the sandbox view can never drift from production.
$sr_report = (new ProductLinkAuditor(Container::getHotelRepository()))->report($sr_prefix);
$sr_rows = (array) ($sr_report['rows'] ?? []);

sr_line('unlinked sphinx products: ' . (int) ($sr_report['total'] ?? 0));
sr_line('(unlinked = no ?:sphinx_hotels row points at the product → no booking form on its page)');
sr_line();

$sr_states = [
    'missing' => 'hotel not synced (relink fetches it from the API)',
    'unlinked' => 'hotel synced, link missing (also heals on first product-page view)',
    'linked_to_other' => 'hotel linked to ANOTHER product (inspect manually)',
];

if ($sr_rows !== []) {
    sr_line('== unlinked products ==');
    foreach ($sr_rows as $sr_row) {
        $sr_state = (string) ($sr_row['state'] ?? '');
        $sr_note = $sr_states[$sr_state] ?? $sr_state;
        if ($sr_state === 'linked_to_other') {
            $sr_note .= ' → product ' . (int) ($sr_row['linked_product_id'] ?? 0);
        }
        sr_line('  ' . str_pad((string) ($sr_row['product_code'] ?? ''), 12)
            . ' product ' . str_pad((string) ($sr_row['product_id'] ?? ''), 6)
            . ' hotel ' . str_pad((string) ($sr_row['hotel_id'] ?? ''), 10) . $sr_note);
        sr_line('  ' . str_pad('', 12) . (string) ($sr_row['name'] ?? ''));
    }
    sr_line();
}

if (!$sr_force) {
    sr_line($sr_rows === []
        ? 'VERDICT: every sphinx-shaped product is linked. If a hotel page still lacks the'
            . "\nbooking form, clear var/cache and hard-refresh (Ctrl+F5)."
        : 'Re-run with ?force=1 (or CLI arg "force") to fetch the missing hotels from the'
            . "\nSphinx API and link them — same repair as the admin's Sphinx → Product links page.");
    exit;
}

if (!ConfigProvider::isConfigured()) {
    exit("Sphinx API is not configured (credentials missing) — cannot fetch hotels.\n");
}

sr_line('== running relink ==');
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

$sr_service = new HotelSyncService(
    Container::getApi(),
    Container::getHotelRepository(),
    Container::getDestinationRepository(),
    Container::getHotelSkipRepository()
);

$sr_stats = $sr_service->relinkExistingProducts(static function (int $i, int $total, string $hotelId): void {
    sr_line('  [' . $i . '/' . $total . '] hotel ' . $hotelId);
});

sr_line();
sr_line('linked:    ' . (int) ($sr_stats['linked'] ?? 0));
sr_line('skipped:   ' . (int) ($sr_stats['skipped'] ?? 0) . '  (already linked)');
sr_line('not found: ' . (int) ($sr_stats['not_found'] ?? 0) . '  (provider no longer offers the hotel)');
sr_line('errors:    ' . (int) ($sr_stats['errors'] ?? 0));
sr_line('total:     ' . (int) ($sr_stats['total'] ?? 0));
sr_line();

if (function_exists('fn_clear_cache')) {
    fn_clear_cache();
    sr_line('cleared CS-Cart cache (var/cache)');
}

sr_line((int) ($sr_stats['linked'] ?? 0) > 0
    ? 'VERDICT: links written. Hard-refresh the hotel page (Ctrl+F5) — the booking form should appear.'
    : 'VERDICT: nothing linked. Check the counts above: "not found" means the Sphinx API does not'
        . "\nreturn that hotel id any more (the product is stale); \"errors\" means API/transport trouble.");
