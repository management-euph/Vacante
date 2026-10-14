#!/usr/bin/env bash
#
# First-run provisioner for the full-store sandbox. On the very first boot it
# ingests the CS-Cart kit, links the repo addons, runs the console installer,
# enables development mode and clears the cache; a sentinel file in the docroot
# volume makes every later boot skip straight to Apache (only re-linking addons
# so a freshly added addon dir shows up).
set -euo pipefail

DOCROOT=/var/www/html
KIT=/kit
SENTINEL="$DOCROOT/.novoton-provisioned"

DB_HOST="${CSCART_DB_HOST:-db}"
DB_NAME="${CSCART_DB_NAME:-cscart}"
DB_USER="${CSCART_DB_USER:-cscart}"
DB_PASSWORD="${CSCART_DB_PASSWORD:-cscart}"
ADMIN_EMAIL="${CSCART_ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${CSCART_ADMIN_PASSWORD:-admin}"
LICENSE="${CSCART_LICENSE_KEY:-}"
HTTP_HOST="${CSCART_HTTP_HOST:-localhost:8080}"
# Storefront theme. Empty = auto: prefer the modern nova_theme when the kit
# ships it (that's what production runs — its product templates fire the hook
# anchors the travel addons' PDP booking form + location line attach to),
# falling back to classic responsive. Set CSCART_THEME=responsive to force.
THEME="${CSCART_THEME:-}"

log() { echo "[entrypoint] $*"; }

# The working tree's link-addons.sh when the repo is mounted (a new addon in its
# map is linked by a plain restart), else the copy baked into the image.
LINK_ADDONS=/repo/docker/fullstore/link-addons.sh
[ -f "$LINK_ADDONS" ] || LINK_ADDONS=/usr/local/bin/link-addons.sh

wait_for_db() {
    # Probe with PHP's mysqli — the exact driver CS-Cart uses, and always
    # present in this image. (The old `mysqladmin ping` broke once the
    # php:8.3-apache base moved to Debian trixie, whose MariaDB client 11.x
    # dropped the mysql-named tools; the command was simply absent.)
    log "Waiting for the database at ${DB_HOST}..."
    for _ in $(seq 1 60); do
        if php -r 'mysqli_report(MYSQLI_REPORT_OFF); $c=@mysqli_connect(getenv("CSCART_DB_HOST")?:"db", getenv("CSCART_DB_USER")?:"cscart", getenv("CSCART_DB_PASSWORD")?:"cscart"); exit($c?0:1);' >/dev/null 2>&1; then
            log "Database is ready."
            return 0
        fi
        sleep 2
    done
    log "FATAL: database never became ready." >&2
    exit 1
}

ingest_kit() {
    # Accept either a .zip archive or an already-extracted directory in /kit
    # (same contract as docker/test-db/build.sh's CSCART_SRC).
    local zip src inner tmp
    # Several zips (an old kit left next to a new one): the highest version.
    zip="$(find "$KIT" -maxdepth 1 -iname '*.zip' 2>/dev/null | sort -V | tail -n1 || true)"
    if [ -n "$zip" ]; then
        log "Unzipping kit: $zip"
        tmp="$(mktemp -d)"
        unzip -q "$zip" -d "$tmp"
        src="$tmp"
    else
        src="$KIT"
    fi

    # Normalise: CS-Cart zips often nest under one top-level folder. Locate the
    # dir that actually contains install/index.php.
    if [ ! -f "$src/install/index.php" ]; then
        inner="$(find "$src" -maxdepth 3 -type f -path '*/install/index.php' 2>/dev/null | head -n1 || true)"
        if [ -n "$inner" ]; then
            src="$(dirname "$(dirname "$inner")")"
        fi
    fi

    if [ ! -f "$src/install/index.php" ]; then
        log "FATAL: no CS-Cart kit found in ./kit (need a cscart_*.zip or an extracted dir with install/index.php)." >&2
        exit 1
    fi

    log "Copying CS-Cart into the docroot..."
    cp -a "$src/." "$DOCROOT/"
}

resolve_theme() {
    if [ -n "$THEME" ]; then
        log "Storefront theme (from CSCART_THEME): $THEME"
        return 0
    fi
    if [ -d "$DOCROOT/var/themes_repository/nova_theme" ]; then
        THEME=nova_theme
        log "Storefront theme: nova_theme (kit ships it — matches production)."
    else
        THEME=responsive
        log "Storefront theme: responsive (kit has no nova_theme)."
    fi
}

write_install_config() {
    log "Writing install/config.php..."
    cat > "$DOCROOT/install/config.php" <<PHP
<?php
return array(
    // fgo_invoicing is intentionally NOT installed by the console installer:
    // its install fatals (exit 255) here (Romanian e-invoicing addon, needs
    // api.fgo.ro credentials), which would abort the whole provision. The
    // three travel addons install cleanly. install-addons.php still ATTEMPTS
    // fgo afterward (tolerated), so it activates if its install ever succeeds.
    'addons' => array('travel_core', 'novoton_holidays', 'sphinx_holidays'),
    'cart_settings' => array(
        'email' => '${ADMIN_EMAIL}',
        'password' => '${ADMIN_PASSWORD}',
        'secret_key' => 'novotonbun-fullstore-secret',
        'languages' => array('en', 'ro'),
        'main_language' => 'en',
        'demo_catalog' => false,
        'theme_name' => '${THEME}',
        'license_number' => '${LICENSE}',
    ),
    'database_settings' => array(
        'host' => '${DB_HOST}',
        'name' => '${DB_NAME}',
        'user' => '${DB_USER}',
        'password' => '${DB_PASSWORD}',
        'table_prefix' => 'cscart_',
        'database_backend' => 'mysqli',
        'notify' => false,
        'allow_override' => 'Y',
    ),
    'server_settings' => array(
        'http_host' => '${HTTP_HOST}',
        'http_path' => '',
        'https_host' => '${HTTP_HOST}',
        'https_path' => '',
        // false: the addons are symlinked to the read-only /repo mount, so
        // CS-Cart's chmod-everything pass would fail on them and abort the
        // install. This entrypoint runs the installer as root and
        // chown -R www-data's the docroot afterward, so the installer's own
        // permission pass is unnecessary.
        'correct_permissions' => false,
    ),
);
PHP
}

enable_dev_mode() {
    # DEVELOPMENT turns on template recompilation (compile_check) + error
    # display, so storefront/admin .tpl edits show up without a manual cache
    # clear. config.local.php is included before init, so appending is fine.
    local cfg="$DOCROOT/config.local.php"
    if [ -f "$cfg" ] && ! grep -q "define('DEVELOPMENT'" "$cfg"; then
        printf "\n// Local sandbox: recompile templates on change, show errors.\nif (!defined('DEVELOPMENT')) { define('DEVELOPMENT', true); }\n" >> "$cfg"
        log "Enabled DEVELOPMENT mode in config.local.php."
    fi
}

provision() {
    log "=== First-run provisioning ==="
    ingest_kit
    resolve_theme

    log "Linking repo addons into the docroot..."
    DOCROOT="$DOCROOT" REPO=/repo bash "$LINK_ADDONS"

    write_install_config

    log "Running the CS-Cart console installer (this can take a few minutes)..."
    # Non-fatal: a single addon's install fataling must not crash-loop the
    # whole provision. The core schema + the three travel addons install
    # cleanly; install-addons.php below reconciles anything the console
    # installer missed.
    ( cd "$DOCROOT/install" && php index.php ) || log "console installer reported errors; continuing (install-addons.php will reconcile)."

    log "Verifying addon install order (reconciling any the installer missed)..."
    php /usr/local/bin/install-addons.php || log "install-addons.php reported an issue (non-fatal); check the admin Add-ons page."

    # Second link pass: the installer only NOW copied the chosen theme into
    # design/themes/<theme>, so the first pass (which guards on that dir
    # existing) skipped the theme-level overlays. Re-running is idempotent
    # and picks them up — without this, the storefront booking form /
    # location line templates would be missing until the next restart.
    log "Re-linking addon design files for the installed theme..."
    DOCROOT="$DOCROOT" REPO=/repo bash "$LINK_ADDONS"

    enable_dev_mode

    log "Clearing the template/cache dir..."
    rm -rf "${DOCROOT:?}/var/cache/"* 2>/dev/null || true

    log "Fixing docroot ownership for Apache..."
    chown -R www-data:www-data "$DOCROOT" 2>/dev/null || true

    touch "$SENTINEL"
    log "=== Provisioning complete — store is live ==="
}

wait_for_db

if [ ! -f "$SENTINEL" ]; then
    provision
else
    log "Already provisioned; re-linking addons for freshness."
    DOCROOT="$DOCROOT" REPO=/repo bash "$LINK_ADDONS" || true
fi

exec "$@"
