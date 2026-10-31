#!/usr/bin/env bash
#
# Why the checkout shows no booking card: what a session that cannot reach
# this machine needs to know, in one paste.
#
# travel_core puts the booking card (hotel, dates, guests, "edit guests") on
#   - the cart page, through the hook checkout:product_info (and
#     cart_content:product_info, where a theme calls it);
#   - the checkout's order summary, through block_checkout:product_extra,
#     which blocks/checkout/products_in_cart.tpl calls for each product.
# The card shows only where a template calls its hook and nothing replaces
# that part of the page. This prints, for the store as it runs:
#   - the storefront's theme and the "Skip the cart page for" setting;
#   - the blocks of the checkout page (Design > Layouts > Checkout), each
#     with its template, the theme copy rendered and whether it calls the
#     card's hook;
#   - every add-on's template hooks on the checkout's product list, with the
#     add-on's status: an active add-on's .override.tpl on
#     block_checkout:cart_items / cart_products replaces the list the card
#     hangs on;
#   - which templates call the card's hooks;
#   - every hook the themes' checkout templates call;
#   - travel_core's checkout hook files as each theme sees them.
# Paths, hook names, block names and settings only: no theme code is printed.
#
# Run from docker/fullstore:
#   docker compose exec -T app bash /repo/docker/fullstore/diagnose-checkout.sh
set -uo pipefail

DOCROOT="${DOCROOT:-/var/www/html}"
if ! cd "$DOCROOT/design/themes" 2>/dev/null; then
    echo "[diagnose-checkout] run this inside the app container, from docker/fullstore:" >&2
    echo "    docker compose exec -T app bash /repo/docker/fullstore/diagnose-checkout.sh" >&2
    exit 1
fi

php <<'PHP'
<?php
mysqli_report(MYSQLI_REPORT_OFF);
$db = @mysqli_connect(
    getenv('CSCART_DB_HOST') ?: 'db',
    getenv('CSCART_DB_USER') ?: 'cscart',
    getenv('CSCART_DB_PASSWORD') ?: 'cscart',
    getenv('CSCART_DB_NAME') ?: 'cscart',
);
/** @return list<array<string, string|null>> */
$rows = static function (string $sql) use ($db): array {
    if (!$db) {
        return [];
    }
    $result = mysqli_query($db, $sql);
    if ($result === false) {
        echo '   (' . mysqli_error($db) . ")\n";

        return [];
    }

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
};

echo "== Storefront theme and settings\n";
if (!$db) {
    echo "   (the database does not answer)\n";
}
$theme = '';
foreach ($rows('SELECT storefront_id, url, theme_name FROM cscart_storefronts') as $sf) {
    $theme = $theme !== '' ? $theme : (string) $sf['theme_name'];
    echo "   theme: {$sf['storefront_id']} {$sf['url']}: {$sf['theme_name']}\n";
}
foreach ($rows("SELECT value FROM cscart_settings_objects WHERE name = 'skip_cart_for'") as $s) {
    echo '   Skip the cart page for: ' . ((string) $s['value'] === '' ? '(nothing ticked)' : $s['value']) . "\n";
}

// The card needs the line's extra.travel_booking: a line added as a plain
// product (no booking form) has none. Key names only: no values, no guests.
echo "\n== Cart lines (Orders > Live carts), newest first\n";
$lines = $rows(
    'SELECT user_id, user_type, timestamp, product_id, amount, extra FROM cscart_user_session_products'
    . " WHERE type = 'C' ORDER BY timestamp DESC, item_id LIMIT 12"
);
if ($lines === [] && $db) {
    echo "   (no saved cart lines)\n";
}
foreach ($lines as $line) {
    $item = @unserialize((string) $line['extra'], ['allowed_classes' => false]);
    $item = is_array($item) ? $item : [];
    $extra = is_array($item['extra'] ?? null) ? $item['extra'] : $item;
    $name = $rows('SELECT product FROM cscart_product_descriptions WHERE lang_code = \'en\' AND product_id = ' . (int) $line['product_id'])[0]['product'] ?? '?';
    $travelKeys = array_values(array_filter(
        array_map('strval', array_keys($extra)),
        static fn (string $k): bool => (bool) preg_match('/^(travel|novoton|sphinx|eurosite)_/', $k),
    ));
    printf(
        "   %s %s%s  #%s \"%s\" x%s: travel_booking %s; %d extra keys%s\n",
        date('Y-m-d H:i', (int) $line['timestamp']),
        $line['user_type'] === 'R' ? 'customer ' : 'guest ',
        $line['user_id'],
        $line['product_id'],
        $name,
        $line['amount'],
        empty($extra['travel_booking']) ? 'NO' : 'yes',
        count($extra),
        $travelKeys !== [] ? ' (' . implode(', ', $travelKeys) . ')' : '',
    );
}

echo "\n== Checkout page blocks, main content (Design > Layouts > Checkout)\n";
$layouts = array_filter(
    $rows('SELECT * FROM cscart_bm_layouts'),
    static fn (array $l): bool => (string) ($l['theme_name'] ?? '') === $theme,
);
foreach ($layouts as $layout) {
    $default = in_array((string) ($layout['is_default'] ?? ''), ['1', 'Y'], true);
    echo "-- layout {$layout['layout_id']} \"{$layout['name']}\"" . ($default ? ' (default)' : '') . "\n";
    $blocks = $rows(
        'SELECT c.position, g.grid_id, s.status AS snap_status, b.block_id, b.type, b.properties, d.name'
        . ' FROM cscart_bm_locations l'
        . ' JOIN cscart_bm_containers c ON c.location_id = l.location_id'
        . ' JOIN cscart_bm_grids g ON g.container_id = c.container_id'
        . ' JOIN cscart_bm_snapping s ON s.grid_id = g.grid_id'
        . ' JOIN cscart_bm_blocks b ON b.block_id = s.block_id'
        . " LEFT JOIN cscart_bm_blocks_descriptions d ON d.block_id = b.block_id AND d.lang_code = 'en'"
        . " WHERE l.dispatch = 'checkout' AND c.position = 'CONTENT' AND l.layout_id = " . (int) $layout['layout_id']
        . ' ORDER BY c.position, g.grid_id, s.`order`'
    );
    if ($blocks === []) {
        echo "   (no checkout location in this layout)\n";
    }
    foreach ($blocks as $b) {
        $props = @unserialize((string) $b['properties'], ['allowed_classes' => false]);
        $tpl = is_array($props) ? (string) ($props['template'] ?? '') : '';
        $file = '';
        foreach ([$theme, 'responsive'] as $t) {
            if ($tpl !== '' && is_file("{$t}/templates/{$tpl}")) {
                $file = "{$t}/templates/{$tpl}";
                break;
            }
        }
        $calls = $file !== '' && str_contains((string) file_get_contents($file), 'hook name="block_checkout:product_extra"');
        printf(
            "   %-8s grid %-5s %s \"%s\" [%s]\n      template: %s%s\n",
            $b['position'],
            $b['grid_id'],
            (string) $b['snap_status'] === 'A' ? 'on ' : 'OFF',
            $b['name'] ?? '',
            $b['type'],
            $tpl !== '' ? $tpl : '(none)',
            $file !== '' ? " -> {$file}" . ($calls ? ' (calls the card hook)' : '') : '',
        );
    }
}

echo "\n== Add-on template hooks on the checkout's product list (block_checkout:*)\n";
$status = [];
foreach ($rows('SELECT addon, status FROM cscart_addons') as $a) {
    $status[(string) $a['addon']] = (string) $a['status'];
}
$hookFiles = glob('*/templates/addons/*/hooks/block_checkout/*.tpl') ?: [];
sort($hookFiles);
foreach ($hookFiles as $path) {
    $parts = explode('/', $path);
    $addon = $parts[3] ?? '?';
    $state = $status[$addon] ?? '?';
    echo '   ' . ($state === 'A' ? 'active  ' : 'inactive') . "  {$parts[0]}  {$addon}: block_checkout/" . basename($path) . "\n";
}
if ($hookFiles === []) {
    echo "   (none)\n";
}
PHP

echo
echo "== Templates calling the card's hooks"
for hook in block_checkout:product_extra cart_content:product_info checkout:product_info; do
    echo "-- $hook"
    found=$(grep -rl --include='*.tpl' "hook name=\"$hook\"" . | grep -v '/addons/' | sed 's|^\./|   |')
    echo "${found:-   (none: no template calls it, so its card never shows)}"
done

echo
echo "== Hooks in each theme's checkout templates"
for theme in */; do
    for dir in templates/blocks/checkout templates/views/checkout; do
        [ -d "$theme$dir" ] || continue
        grep -rHo --include='*.tpl' 'hook name="[a-z_]*:[a-z_]*"' "$theme$dir" \
            | sed -e 's|hook name="||' -e 's|"$||' -e 's|^|   |'
    done
done

echo
echo "== travel_core's checkout hook files as each theme sees them"
for theme in */; do
    dir="${theme}templates/addons/travel_core/hooks"
    for hook in block_checkout/product_extra.post.tpl cart_content/product_info.post.tpl checkout/product_info.post.tpl; do
        if [ -e "$dir/$hook" ]; then
            echo "   ${theme}${hook}: present"
        else
            echo "   ${theme}${hook}: MISSING"
        fi
    done
done
