#!/usr/bin/env bash
#
# Pack what the hotel page designs (docs/hotel-page-design-a-plan.md) are laid
# out against, from this store, into var/ of the repo checkout, to attach to a
# design / development session that cannot reach this machine:
#
#   - the product page, photo, price and tabs templates of every installed
#     theme (design/themes/*/templates/...);
#   - the compiled theme CSS (var/cache/.../standalone*.css);
#   - the CS-Cart version;
#   - with a product id, or a supplier (novoton, sphinx, eurosite: it picks
#     that supplier's hotel itself): the hotel page as a logged-out visitor
#     gets it; with dates too, the supplier's results for that stay (two
#     adults) - with a supplier, from the first of its hotels that has offers
#     for the dates (export-theme.php).
#
# The archive is named after what it holds: var/theme-export.tgz,
# var/theme-export-123.tgz, var/theme-export-novoton.tgz.
#
# These are licensed CS-Cart files: the archive lands in var/, which git
# ignores. Never commit it.
#
# Run from docker/fullstore:
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh 123
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh 123 2026-11-02 2026-11-08
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh novoton 2027-07-10 2027-07-17
set -euo pipefail

DOCROOT="${DOCROOT:-/var/www/html}"
REPO="${REPO:-/repo}"
# The store URL answers inside the container too (entrypoint.sh serve_own_url).
STORE_URL="http://${CSCART_HTTP_HOST:-localhost:8080}"

HOTEL="${1:-}"
CHECK_IN="${2:-}"
CHECK_OUT="${3:-}"

if [ ! -d "$REPO/docker/fullstore" ] || [ ! -d "$DOCROOT/design/themes" ]; then
    echo "[export-theme] run this inside the app container, from docker/fullstore:" >&2
    echo "    docker compose exec app bash /repo/docker/fullstore/export-theme.sh" >&2
    exit 1
fi
if [ -n "$HOTEL" ] && ! [[ "$HOTEL" =~ ^([0-9]+|novoton|sphinx|eurosite)$ ]]; then
    echo "[export-theme] first argument: a product id (a number, e.g. 123) or a supplier: novoton, sphinx or eurosite" >&2
    exit 1
fi
for day in "$CHECK_IN" "$CHECK_OUT"; do
    if [ -n "$day" ] && ! [[ "$day" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
        echo "[export-theme] dates are YYYY-MM-DD, e.g. 2027-07-10 2027-07-17" >&2
        exit 1
    fi
done
if [ -n "$CHECK_IN" ] && [ -z "$CHECK_OUT" ]; then
    echo "[export-theme] give the check-out date too, e.g. 2027-07-10 2027-07-17" >&2
    exit 1
fi

OUT="$REPO/var/theme-export"
ARCHIVE="$REPO/var/theme-export${HOTEL:+-$HOTEL}.tgz"
# Gone before anything else: a run that stops half-way must not leave an
# older archive behind to be uploaded instead.
rm -f -- "$ARCHIVE"
rm -rf -- "$OUT"
mkdir -p -- "$OUT"
cd "$DOCROOT"

TEMPLATES=(
    blocks/product_templates/default_template.tpl
    common/image.tpl
    common/previewer.tpl
    common/price.tpl
    common/product_data.tpl
    common/tabsbox.tpl
    views/products/components/product_images.tpl
    views/products/components/product_features_short_list.tpl
    views/tabs/components/product_popup_tabs.tpl
    views/tabs/components/product_tabs.tpl
)
copy() {
    mkdir -p -- "$OUT/$(dirname -- "$1")"
    cp -- "$1" "$OUT/$1"
}
for theme in design/themes/*/; do
    [ -f "${theme}manifest.json" ] && copy "${theme}manifest.json"
    for tpl in "${TEMPLATES[@]}"; do
        [ -f "${theme}templates/$tpl" ] && copy "${theme}templates/$tpl"
    done
done

grep -h "PRODUCT_VERSION" config.php > "$OUT/version.txt" 2>/dev/null || true

# A storefront page first: clearing the cache (update.ps1) drops the compiled
# CSS until the store renders a page again.
php -r '@file_get_contents($argv[1] . "/", false, stream_context_create(["http" => ["timeout" => 90]]));' -- "$STORE_URL" || true

# The hotel page and, with dates, the supplier's results, as a visitor gets them.
if [ -n "$HOTEL" ]; then
    php "$REPO/docker/fullstore/export-theme.php" "$STORE_URL" "$OUT" "$HOTEL" "$CHECK_IN" "$CHECK_OUT"
fi

# The compiled CSS, newest first (CS-Cart builds it on a storefront visit).
css_count=0
while IFS= read -r css; do
    [ -n "$css" ] || continue
    copy "$css"
    css_count=$((css_count + 1))
done < <(find var/cache -type f -name 'standalone*.css' -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -n 3 | cut -d' ' -f2-)

tar czf "$ARCHIVE" -C "$REPO/var" theme-export
echo "[export-theme] $(find "$OUT" -type f | wc -l) file(s), $css_count compiled stylesheet(s):"
(cd "$OUT" && find . -type f | sort | sed 's/^\.\//    /')
echo "[export-theme] on your computer: <repo checkout>/var/$(basename -- "$ARCHIVE")"
