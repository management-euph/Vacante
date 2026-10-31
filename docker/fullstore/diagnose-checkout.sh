#!/usr/bin/env bash
#
# Why the checkout shows no booking card: what a session that cannot reach
# this machine needs to know, in one paste.
#
# travel_core puts the booking card (hotel, dates, guests, "edit guests") on
#   - the cart page, through the hook cart_content:product_info;
#   - the checkout's order summary, through block_checkout:product_extra.
# A theme whose own templates do not call a hook never shows that card. This
# prints, for the store as it runs:
#   - the storefront's theme and the "Skip the cart page for" setting;
#   - which templates call the card's hooks;
#   - every hook the themes' checkout templates call;
#   - travel_core's checkout hook files as each theme sees them.
# Paths, hook names and settings only: no theme code is printed.
#
# Run from docker/fullstore:
#   docker compose exec app bash /repo/docker/fullstore/diagnose-checkout.sh
set -uo pipefail

DOCROOT="${DOCROOT:-/var/www/html}"
if ! cd "$DOCROOT/design/themes" 2>/dev/null; then
    echo "[diagnose-checkout] run this inside the app container, from docker/fullstore:" >&2
    echo "    docker compose exec app bash /repo/docker/fullstore/diagnose-checkout.sh" >&2
    exit 1
fi

echo "== Storefront theme and settings"
php <<'PHP'
<?php
mysqli_report(MYSQLI_REPORT_OFF);
$db = @mysqli_connect(
    getenv('CSCART_DB_HOST') ?: 'db',
    getenv('CSCART_DB_USER') ?: 'cscart',
    getenv('CSCART_DB_PASSWORD') ?: 'cscart',
    getenv('CSCART_DB_NAME') ?: 'cscart',
);
if (!$db) {
    echo "   (the database does not answer)\n";
    exit;
}
$queries = [
    'theme' => "SELECT CONCAT(storefront_id, ' ', url, ': ', theme_name) FROM cscart_storefronts",
    'Skip the cart page for' => "SELECT value FROM cscart_settings_objects WHERE name = 'skip_cart_for'",
];
foreach ($queries as $label => $sql) {
    $rows = mysqli_query($db, $sql);
    if ($rows === false) {
        echo "   {$label}: (" . mysqli_error($db) . ")\n";
        continue;
    }
    while ($row = mysqli_fetch_row($rows)) {
        echo "   {$label}: " . ((string) $row[0] === '' ? '(nothing ticked)' : $row[0]) . "\n";
    }
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
    for hook in block_checkout/product_extra.post.tpl cart_content/product_info.post.tpl; do
        if [ -e "$dir/$hook" ]; then
            echo "   ${theme}${hook}: present"
        else
            echo "   ${theme}${hook}: MISSING"
        fi
    done
done
