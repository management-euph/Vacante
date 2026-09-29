#!/usr/bin/env bash
#
# Symlink each addon's DEPLOY artifacts from the read-only /repo mount into the
# CS-Cart docroot, so the store runs the repo's live code (edit in the host IDE,
# refresh the browser). Per-addon-id leaf links merge cleanly into CS-Cart's own
# app/addons, design, js and var/langs trees.
#
# Only real artifacts are linked — the [ -e ] guard silently skips what an addon
# doesn't ship (sphinx has no nova_theme / theme CSS; fgo is backend-only + EN
# only). Dev-only trees are excluded by construction: react-src lives OUTSIDE
# app/addons/ (the built bundle is already in js/addons/travel_core), and each
# addon's tests/vendor/composer sit unused under the app dir CS-Cart ignores.
#
# Re-runnable: drop a new addon into the map and re-run. Safe to run on every
# container start.
set -euo pipefail

DOCROOT="${DOCROOT:-/var/www/html}"
REPO="${REPO:-/repo}"

# Run on the host, every [ -e ] guard below fails and nothing is linked, yet
# the script would still print "done". It belongs inside the app container.
if [ ! -d "$REPO" ] || [ ! -d "$DOCROOT" ]; then
    echo "[link-addons] $REPO or $DOCROOT not found: run this inside the app container:" >&2
    echo "    docker compose exec app bash /usr/local/bin/link-addons.sh   (from docker/fullstore)" >&2
    exit 1
fi

# addon-id -> repo top-level dir
declare -A ADDONS=(
    [travel_core]=addon-travel-core
    [novoton_holidays]=addon-novoton-holidays
    [sphinx_holidays]=addon-sphinx-holidays
    [fgo_invoicing]=addon-fgo-invoicing
    # MVP (out of the CI gates): linking only puts it on the Add-ons page —
    # installing stays a manual admin action, and its API credentials are the
    # spec placeholders until an operator fills real ones in its settings.
    [eurosite]=eurosite_addon
    # Payment processor: install it from Add-ons, then Administration ->
    # Payment methods -> add a method with processor "Netopia Payments" and
    # fill in its sandbox signature/API key there (never in the repo).
    [netopia_payments]=addon-netopia-payments-main
)

# Addons that ship templates for the responsive theme only: link those into
# nova_theme too, so a nova_theme store still renders their hooks (the travel
# addons keep real nova_theme copies, see scripts/mirror-themes.php).
declare -A RESPONSIVE_ONLY=(
    [netopia_payments]=1
)

link() { # $1 = source under /repo, $2 = destination under docroot
    local src="$1" dest="$2"
    [ -e "$src" ] || return 0
    mkdir -p "$(dirname "$dest")"
    rm -rf "$dest"
    ln -s "$src" "$dest"
    echo "    $dest -> $src"
}

# Windows checkouts under HP Sure Click get a hidden ~BROMIUM entry in folders
# that came from downloaded archives. Through the bind mount it is listed but
# cannot be opened, and CS-Cart's template scan (SmartyEngine
# templateExistsViaSnapshot, a RecursiveDirectoryIterator) throws on it: the
# storefront dies with "Failed to open directory ... ~BROMIUM".
#
# link_tpl links a TEMPLATE tree like link(), unless the tree holds such an
# entry: then it builds real directories in the docroot and links each file
# one by one, leaving ~BROMIUM out. Edits still show live; a template file
# ADDED later needs this script re-run (or a container restart).
link_tpl() { # $1 = source dir under /repo, $2 = destination under docroot
    local src="$1" dest="$2"
    [ -e "$src" ] || return 0
    if [ ! -d "$src" ] || [ -z "$(find "$src" -name '~BROMIUM*' -print -quit 2>/dev/null)" ]; then
        link "$src" "$dest"
        return 0
    fi
    mkdir -p "$(dirname "$dest")"
    rm -rf "$dest"
    mkdir -p "$dest"
    local rel
    while IFS= read -r -d '' rel; do
        mkdir -p "$dest/${rel#./}"
    done < <(cd "$src" && find . -name '~BROMIUM*' -prune -o -type d -print0 2>/dev/null)
    while IFS= read -r -d '' rel; do
        ln -s "$src/${rel#./}" "$dest/${rel#./}"
    done < <(cd "$src" && find . -name '~BROMIUM*' -prune -o \( -type f -o -type l \) -print0 2>/dev/null)
    echo "    $dest -> $src (per file: skipped HP Sure Click ~BROMIUM entries)"
}

for id in "${!ADDONS[@]}"; do
    base="$REPO/${ADDONS[$id]}"
    echo "[link-addons] $id"

    link "$base/app/addons/$id"                           "$DOCROOT/app/addons/$id"
    link_tpl "$base/design/backend/templates/addons/$id"      "$DOCROOT/design/backend/templates/addons/$id"
    link "$base/design/backend/css/addons/$id"            "$DOCROOT/design/backend/css/addons/$id"
    link "$base/design/backend/js/addons/$id"             "$DOCROOT/design/backend/js/addons/$id"
    link_tpl "$base/design/backend/mail/templates/addons/$id" "$DOCROOT/design/backend/mail/templates/addons/$id"
    link "$base/design/backend/media/images/addons/$id"   "$DOCROOT/design/backend/media/images/addons/$id"

    for theme in responsive nova_theme; do
        # Only link into a theme the kit actually ships.
        [ -d "$DOCROOT/design/themes/$theme" ] || continue
        src_theme="$theme"
        if [ -n "${RESPONSIVE_ONLY[$id]:-}" ] && [ ! -d "$base/design/themes/$theme" ]; then
            src_theme=responsive
        fi
        link_tpl "$base/design/themes/$src_theme/templates/addons/$id"      "$DOCROOT/design/themes/$theme/templates/addons/$id"
        link "$base/design/themes/$src_theme/css/addons/$id"            "$DOCROOT/design/themes/$theme/css/addons/$id"
        link_tpl "$base/design/themes/$src_theme/mail/templates/addons/$id" "$DOCROOT/design/themes/$theme/mail/templates/addons/$id"
    done

    # Payment processors live OUTSIDE app/addons: the processor script CS-Cart
    # runs for the payment method, and its settings form in the admin.
    link "$base/app/payments/$id.php" "$DOCROOT/app/payments/$id.php"
    link "$base/design/backend/templates/views/payments/components/cc_processors/$id.tpl" \
         "$DOCROOT/design/backend/templates/views/payments/components/cc_processors/$id.tpl"

    link "$base/js/addons/$id" "$DOCROOT/js/addons/$id"

    for lang in en ro; do
        link "$base/var/langs/$lang/addons/$id.po" "$DOCROOT/var/langs/$lang/addons/$id.po"
    done
done

# NOTE: the standalone dev/ probes (dev/novoton, dev/sphinx, dev/sphinx_api_dev)
# are NOT linked here. They are served as real files via a docker-compose bind
# mount straight into the docroot (../../dev -> /var/www/html/dev). Linking them
# here would be both redundant and unsafe: link()'s `rm -rf "$dest"` against that
# bind-mount point would delete the mounted host files. See docker-compose.yml.

echo "[link-addons] done"
