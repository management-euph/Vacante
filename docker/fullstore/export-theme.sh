#!/usr/bin/env bash
#
# Pack what the hotel page designs (docs/hotel-page-design-a-plan.md) are laid
# out against, from this store, into var/theme-export.tgz of the repo checkout,
# to attach to a design / development session that cannot reach this machine:
#
#   - the product page, photo, price and tabs templates of every installed
#     theme (design/themes/*/templates/...);
#   - the compiled theme CSS (var/cache/.../standalone*.css);
#   - the CS-Cart version;
#   - with a product id: that hotel page as a logged-out visitor gets it;
#     with dates too: the supplier's results for that stay (two adults), the
#     HTML the hotel page swaps in under the search bar.
#
# These are licensed CS-Cart files: the archive lands in var/, which git
# ignores. Never commit it.
#
# Run from docker/fullstore:
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh 123
#   docker compose exec app bash /repo/docker/fullstore/export-theme.sh 123 2026-11-02 2026-11-08
set -euo pipefail

DOCROOT="${DOCROOT:-/var/www/html}"
REPO="${REPO:-/repo}"
# The store URL answers inside the container too (entrypoint.sh serve_own_url).
STORE_URL="http://${CSCART_HTTP_HOST:-localhost:8080}"

PRODUCT_ID="${1:-}"
CHECK_IN="${2:-}"
CHECK_OUT="${3:-}"

if [ ! -d "$REPO/docker/fullstore" ] || [ ! -d "$DOCROOT/design/themes" ]; then
    echo "[export-theme] run this inside the app container, from docker/fullstore:" >&2
    echo "    docker compose exec app bash /repo/docker/fullstore/export-theme.sh" >&2
    exit 1
fi
if [ -n "$PRODUCT_ID" ] && ! [[ "$PRODUCT_ID" =~ ^[0-9]+$ ]]; then
    echo "[export-theme] the product id is a number, e.g. 123" >&2
    exit 1
fi

OUT="$REPO/var/theme-export"
ARCHIVE="$REPO/var/theme-export.tgz"
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
if [ -n "$PRODUCT_ID" ]; then
    php -r '
        [, $base, $productId, $checkIn, $checkOut, $out] = $argv;
        $get = static function (string $url): string {
            $html = @file_get_contents($url, false, stream_context_create(["http" => ["timeout" => 90]]));
            if ($html === false) {
                fwrite(STDERR, "[export-theme] could not fetch $url\n");
                return "";
            }
            return $html;
        };
        $page = $get("$base/index.php?dispatch=products.view&product_id=$productId");
        if ($page !== "") {
            file_put_contents("$out/product-$productId.html", $page);
        }
        if ($checkIn === "" || $checkOut === "") {
            exit(0);
        }
        $config = json_decode($get("$base/index.php?dispatch=travel_booking.booking_config&product_id=$productId&design=a"), true);
        if (!is_array($config) || empty($config["searchDispatch"])) {
            fwrite(STDERR, "[export-theme] product $productId has no booking form (not a supplier hotel?)\n");
            exit(0);
        }
        file_put_contents("$out/booking-config-$productId.json", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $query = http_build_query([
            "dispatch" => $config["searchDispatch"],
            "hotel_id" => $config["hotelId"] ?? "",
            "product_id" => $productId,
            "check_in" => $checkIn,
            "check_out" => $checkOut,
            "adults" => 2,
            "children" => 0,
            "rooms" => 1,
            "rooms_data" => json_encode([["adults" => 2, "children" => 0, "childrenAges" => []]]),
        ]);
        $results = $get("$base/index.php?$query");
        if ($results !== "") {
            file_put_contents("$out/results-$productId-$checkIn.html", $results);
        }
    ' -- "$STORE_URL" "$PRODUCT_ID" "$CHECK_IN" "$CHECK_OUT" "$OUT"
fi

# The compiled CSS, newest first (CS-Cart builds it on a storefront visit).
css_count=0
while IFS= read -r css; do
    [ -n "$css" ] || continue
    copy "$css"
    css_count=$((css_count + 1))
done < <(find var/cache -type f -name 'standalone*.css' -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -n 3 | cut -d' ' -f2-)

rm -f -- "$ARCHIVE"
tar czf "$ARCHIVE" -C "$REPO/var" theme-export
echo "[export-theme] $(find "$OUT" -type f | wc -l) file(s), $css_count compiled stylesheet(s):"
(cd "$OUT" && find . -type f | sort | sed 's/^\.\//    /')
echo "[export-theme] on your computer: <repo checkout>/var/theme-export.tgz"
