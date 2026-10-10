#!/usr/bin/env bash
#
# Deploy the travel add-ons to a CS-Cart store with rsync, deleting what a
# release removed.
#
# CS-Cart's "Upload & install" and FTP only add or overwrite files, so a file a
# release deletes stays on the server and keeps working against the new code
# (the provider order blocks the booking card replaced would still render).
# This copies each add-on's OWN folders with rsync --delete - app/addons/<id>,
# design/.../addons/<id>, js/addons/<id>: the set scripts/package-addons.php
# zips and docker/fullstore/link-addons.sh links - and nothing else: CS-Cart's
# files and other add-ons' are never touched. Language files are copied only.
# Theme folders go only to themes the store has (responsive, nova_theme).
#
# What is deployed is the COMMITTED code at HEAD (git archive): uncommitted
# edits and untracked files never reach the store, and line endings are the
# repository's (LF). HEAD must be origin/main unless --any-commit is given.
#
# Usage (Linux, macOS or WSL on Windows; Git Bash has no rsync):
#   bash scripts/deploy-addons.sh user@host:/var/www/cscart        dry run: lists what would change
#   bash scripts/deploy-addons.sh user@host:/var/www/cscart --go   deploy
#   (a local store root works too: bash scripts/deploy-addons.sh /var/www/cscart)
#
# Options:
#   --go            deploy for real; without it nothing on the store changes
#   --addons LIST   comma-separated add-on ids (default:
#                   travel_core,novoton_holidays,sphinx_holidays,fgo_invoicing;
#                   add eurosite where the store has it installed)
#   --port N        SSH port
#   --clear-cache   after deploying, delete the store's compiled templates
#                   (var/cache/templates); otherwise use Admin -> Clear cache
#   --backup        before deploying, save the add-on folders (tar) and the
#                   database (mysqldump, credentials read from the store's own
#                   config.local.php) to ~/deploy-backups on the store, outside
#                   the web root; the newest 10 of each are kept
#   --any-commit    deploy HEAD even when it is not origin/main
#
# Files are swapped in at the end of each folder's transfer (--delay-updates),
# so a visitor rarely meets a half-copied folder. Every real deploy appends a
# line (time, commit, add-ons) to ~/deploy-backups/deploy.log on the store.
#
# After a deploy: clear the cache (unless --clear-cache), open any admin page
# once (new language labels add themselves), then check the storefront.
set -euo pipefail

# add-on id -> repository folder (as in package-addons.php and link-addons.sh)
declare -A DIRS=(
    [travel_core]=addon-travel-core
    [novoton_holidays]=addon-novoton-holidays
    [sphinx_holidays]=addon-sphinx-holidays
    [fgo_invoicing]=addon-fgo-invoicing
    [eurosite]=addon-eurosite
)
DEFAULT_ADDONS=travel_core,novoton_holidays,sphinx_holidays,fgo_invoicing
# Never deployed from app/addons/<id>/ (as in package-addons.php). They are
# also left alone on the store: excluded paths are not deleted there.
EXCLUDES=(tests vendor composer.json composer.lock phpunit.xml phpunit-integration.xml
    .phpunit.cache .php-cs-fixer.cache .php-cs-fixer.dist.php .phpunit.result.cache
    .gitignore node_modules)

die() { echo "deploy-addons: $*" >&2; exit 1; }

target='' go=0 addons=$DEFAULT_ADDONS port='' clear_cache=0 any_commit=0 backup=0
while [ $# -gt 0 ]; do
    case "$1" in
        --go) go=1 ;;
        --addons) [ $# -ge 2 ] || die "--addons needs a list"; addons=$2; shift ;;
        --port) [ $# -ge 2 ] || die "--port needs a number"; port=$2; shift ;;
        --clear-cache) clear_cache=1 ;;
        --backup) backup=1 ;;
        --any-commit) any_commit=1 ;;
        -h | --help) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        -*) die "unknown option $1 (see --help)" ;;
        *) [ -z "$target" ] || die "give one store only"; target=$1 ;;
    esac
    shift
done
[ -n "$target" ] || die "no store given. Usage: bash scripts/deploy-addons.sh user@host:/path/to/cscart [--go]"

target=${target%/}
if [[ "$target" =~ ^([^/:]+):(/.*)$ ]]; then
    host=${BASH_REMATCH[1]} root=${BASH_REMATCH[2]}
elif [[ "$target" == *:* ]]; then
    die "use an absolute store path: user@host:/var/www/cscart"
else
    host='' root=$target
fi

for tool in git tar rsync; do
    command -v "$tool" >/dev/null || die "$tool not found (Ubuntu/WSL: sudo apt install $tool)"
done
[ -z "$host" ] || command -v ssh >/dev/null || die "ssh not found (Ubuntu/WSL: sudo apt install openssh-client)"

work=$(mktemp -d)
# One SSH connection for the whole run: a password is asked once, not per folder.
ssh_opts=(-o ControlMaster=auto -o "ControlPath=$work/ssh-%C" -o ControlPersist=120)
[ -z "$port" ] || ssh_opts+=(-p "$port")
cleanup() {
    if [ -n "$host" ]; then ssh "${ssh_opts[@]}" -O exit "$host" >/dev/null 2>&1 || true; fi
    rm -rf "$work"
}
trap cleanup EXIT

q() { printf '%q' "$1"; }
on_store() { # $1 = a shell command, run where the store is
    if [ -n "$host" ]; then ssh "${ssh_opts[@]}" "$host" "$1"; else bash -c "$1"; fi
}
on_store_script() { # stdin = a bash script, run where the store is; $@ = its arguments
    if [ -n "$host" ]; then ssh "${ssh_opts[@]}" "$host" "bash -s -- $(printf '%q ' "$@")"; else bash -s -- "$@"; fi
}
dest() { # $1 = a path under the store root
    if [ -n "$host" ]; then echo "$host:$root/$1"; else echo "$root/$1"; fi
}

# --- What to deploy -----------------------------------------------------------
repo=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
head=$(git -C "$repo" rev-parse HEAD)
git -C "$repo" fetch --quiet origin main 2>/dev/null \
    || echo "warning: could not fetch origin/main, comparing with the last one fetched" >&2
main=$(git -C "$repo" rev-parse --verify --quiet origin/main || true)
if [ "$head" != "$main" ] && [ "$any_commit" -eq 0 ]; then
    die "HEAD ($(git -C "$repo" log -1 --format='%h %s' HEAD)) is not origin/main.
Run: git checkout main && git pull   - or pass --any-commit to deploy it anyway."
fi

IFS=, read -r -a ids <<< "$addons"
srcs=()
for id in "${ids[@]}"; do
    [ -n "${DIRS[$id]:-}" ] || die "unknown add-on '$id' (known: ${!DIRS[*]})"
    git -C "$repo" cat-file -e "HEAD:${DIRS[$id]}" 2>/dev/null || die "$id: ${DIRS[$id]} is not in this commit"
    srcs+=("${DIRS[$id]}")
done
stage=$work/stage
mkdir -p "$stage"
git -C "$repo" archive --format=tar HEAD "${srcs[@]}" | tar -x -C "$stage"

# --- Where it goes --------------------------------------------------------------
on_store "test -f $(q "$root/config.php") && test -d $(q "$root/app/addons")" \
    || die "$target is not a CS-Cart root (no config.php and app/addons there)"
themes=()
for theme in responsive nova_theme; do
    if on_store "test -d $(q "$root/design/themes/$theme")"; then themes+=("$theme"); fi
done

trees_for() { # $1 = add-on id: its folders, store-root relative
    local id=$1 theme
    printf '%s\n' "app/addons/$id" "design/backend/templates/addons/$id" "design/backend/css/addons/$id" \
        "design/backend/js/addons/$id" "design/backend/mail/templates/addons/$id" \
        "design/backend/media/images/addons/$id" "js/addons/$id"
    for theme in ${themes[@]+"${themes[@]}"}; do
        printf '%s\n' "design/themes/$theme/templates/addons/$id" "design/themes/$theme/css/addons/$id"
    done
}

tasks=() # "id<TAB>tree"
for id in "${ids[@]}"; do
    while IFS= read -r tree; do
        if [ -d "$stage/${DIRS[$id]}/$tree" ]; then tasks+=("$id"$'\t'"$tree"); fi
    done < <(trees_for "$id")
done
[ ${#tasks[@]} -gt 0 ] || die "nothing to deploy for: $addons"

# Folders the store does not have yet: created on --go, only listed on a dry run.
check=''
for job in "${tasks[@]}"; do
    tree=${job#*$'\t'}
    check+="[ -d $(q "$root/$tree") ] || echo $(q "$tree"); "
done
mapfile -t missing < <(on_store "$check")
is_missing() { local m; for m in ${missing[@]+"${missing[@]}"}; do [ "$m" = "$1" ] && return 0; done; return 1; }


# -c: compare contents (git archive stamps every file with the commit time).
rsync_opts=(-rlc --itemize-changes --exclude='*.php-cs-fixer.cache')
if [ "$go" -eq 1 ]; then rsync_opts+=(--delay-updates); else rsync_opts+=(--dry-run); fi
[ -z "$host" ] || rsync_opts+=(-e "ssh ${ssh_opts[*]}")

echo "Deploying $(git -C "$repo" log -1 --format='%h %s' HEAD)"
echo "      to $target (themes: ${themes[*]:-none})"
[ "$go" -eq 1 ] || echo "      DRY RUN: nothing changes on the store; add --go to deploy."
echo

if [ "$go" -eq 1 ] && [ "$backup" -eq 1 ]; then
    echo "Backing up the store first (~/deploy-backups on the store):"
    folders=()
    for job in "${tasks[@]}"; do folders+=("${job#*$'\t'}"); done
    on_store_script "$root" "$(date -u +%Y%m%d-%H%M%S)-$(git -C "$repo" rev-parse --short HEAD)" "${folders[@]}" <<'BACKUP' \
        || die "backup failed: nothing was deployed"
set -euo pipefail
root=$1 stamp=$2
shift 2
dir="$HOME/deploy-backups"
case "$dir/" in "$root"/*) echo "the backup folder $dir is inside the store root: refusing" >&2; exit 1 ;; esac
mkdir -p "$dir" && chmod 700 "$dir"
existing=()
for d in "$@"; do if [ -e "$root/$d" ]; then existing+=("$d"); fi; done
if [ ${#existing[@]} -gt 0 ]; then
    tar -czf "$dir/addons-$stamp.tgz" -C "$root" "${existing[@]}"
    echo "  add-on files: $dir/addons-$stamp.tgz"
else
    echo "  add-on files: none yet (first deploy)"
fi
if [ -f "$root/config.local.php" ]; then
    command -v php >/dev/null || { echo "php not found on the store: cannot read the database settings" >&2; exit 1; }
    dump=$(command -v mysqldump || command -v mariadb-dump || true)
    [ -n "$dump" ] || { echo "mysqldump not found on the store: cannot back up the database" >&2; exit 1; }
    # The store's own settings: include config.local.php as CS-Cart does, or
    # (should it need more of CS-Cart than this) read the assignments.
    mapfile -t db < <(cd "$root" && php -r '
        error_reporting(0);
        define("BOOTSTRAP", true); define("AREA", "A"); define("DIR_ROOT", getcwd());
        $config = [];
        try { include "config.local.php"; } catch (\Throwable $e) { $config = []; }
        $src = (string) file_get_contents("config.local.php");
        foreach (["db_host", "db_name", "db_user", "db_password"] as $k) {
            $v = $config[$k] ?? null;
            if (!is_string($v) && preg_match("/\\\$config\\[\\s*[\x27\"]" . $k . "[\x27\"]\\s*\\]\\s*=\\s*\x27((?:[^\x27\\\\]|\\\\.)*)\x27/", $src, $m)) {
                $v = stripslashes($m[1]);
            }
            echo base64_encode((string) $v), "\n";
        }')
    dbhost=$(printf '%s' "${db[0]:-}" | base64 -d) dbname=$(printf '%s' "${db[1]:-}" | base64 -d)
    dbuser=$(printf '%s' "${db[2]:-}" | base64 -d) dbpass=$(printf '%s' "${db[3]:-}" | base64 -d)
    [ -n "$dbname" ] && [ -n "$dbuser" ] || { echo "could not read the database settings from config.local.php" >&2; exit 1; }
    cnf=$(mktemp)
    trap 'rm -f "$cnf"' EXIT
    chmod 600 "$cnf"
    esc() { local v=${1//\\/\\\\}; printf '"%s"' "${v//\"/\\\"}"; }
    {
        echo "[client]"
        echo "user=$(esc "$dbuser")"
        echo "password=$(esc "$dbpass")"
        case "$dbhost" in
            *:/*) echo "socket=$(esc "${dbhost#*:}")"; echo "host=$(esc "${dbhost%%:*}")" ;;
            *:*) echo "host=$(esc "${dbhost%%:*}")"; echo "port=${dbhost##*:}" ;;
            ?*) echo "host=$(esc "$dbhost")" ;;
        esac
    } > "$cnf"
    "$dump" --defaults-extra-file="$cnf" --single-transaction --quick --no-tablespaces \
        --default-character-set=utf8mb4 "$dbname" | gzip > "$dir/db-$stamp.sql.gz"
    echo "  database:     $dir/db-$stamp.sql.gz"
else
    echo "  database:     no config.local.php, skipped"
fi
for kind in addons db; do # keep the newest 10 of each
    set -- "$dir/$kind-"*
    [ -e "$1" ] || continue
    ls -1t -- "$@" | tail -n +11 | while IFS= read -r old; do rm -f -- "$old"; done
done
BACKUP
    echo
fi

# New folders, created after the backup (it saves what was there before).
if [ "$go" -eq 1 ] && [ ${#missing[@]} -gt 0 ]; then
    mk='mkdir -p'
    for m in "${missing[@]}"; do mk+=" $(q "$root/$m")"; done
    on_store "$mk"
    missing=()
fi

log=$work/changes
: > "$log"
for job in "${tasks[@]}"; do
    id=${job%%$'\t'*} tree=${job#*$'\t'}
    src=$stage/${DIRS[$id]}/$tree
    excl=() prune=()
    if [ "$tree" = "app/addons/$id" ]; then
        for name in "${EXCLUDES[@]}"; do excl+=("--exclude=/$name"); prune+=(! -path "$src/$name" ! -path "$src/$name/*"); done
    fi
    if is_missing "$tree"; then
        echo "== $tree  (new folder, $(find "$src" -type f ${prune[@]+"${prune[@]}"} | wc -l | tr -d ' ') files)"
        continue
    fi
    out=$(rsync "${rsync_opts[@]}" --delete ${excl[@]+"${excl[@]}"} "$src/" "$(dest "$tree")/")
    if [ -n "$out" ]; then
        echo "== $tree"
        printf '%s\n' "$out" | sed 's/^/   /'
        printf '%s\n' "$out" >> "$log"
    fi
done
for id in "${ids[@]}"; do
    for lang in en ro; do
        file=var/langs/$lang/addons/$id.po
        [ -f "$stage/${DIRS[$id]}/$file" ] || continue
        out=$(rsync "${rsync_opts[@]}" "$stage/${DIRS[$id]}/$file" "$(dest "var/langs/$lang/addons")/")
        if [ -n "$out" ]; then
            echo "== $file"
            printf '%s\n' "$out" | sed 's/^/   /'
            printf '%s\n' "$out" >> "$log"
        fi
    done
done

updated=$(grep -c '^[<>]f' "$log" || true)
deleted=$(grep -c '^\*deleting' "$log" || true)
echo
if [ "$go" -eq 1 ]; then
    echo "Done: $updated file(s) copied, $deleted deleted."
    on_store "mkdir -p ~/deploy-backups && chmod 700 ~/deploy-backups && echo $(q "$(date -u '+%Y-%m-%d %H:%M:%S UTC')  $(git -C "$repo" rev-parse --short HEAD)  $addons  copied=$updated deleted=$deleted") >> ~/deploy-backups/deploy.log"
    if [ "$clear_cache" -eq 1 ]; then
        on_store "rm -rf $(q "$root/var/cache/templates")"
        echo "Compiled templates cleared (var/cache/templates)."
    else
        echo "Now clear the cache: Admin -> Settings -> Clear cache (or rerun with --clear-cache)."
    fi
    echo "Then open any admin page once (new language labels add themselves) and check the storefront."
else
    echo "Dry run: $updated file(s) would be copied, $deleted deleted${missing[*]:+, ${#missing[@]} new folder(s)}."
    echo "Check the '*deleting' lines above, then rerun with --go."
fi
