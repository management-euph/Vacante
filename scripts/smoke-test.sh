#!/usr/bin/env bash
#
# After a deploy: the storefront (and the admin login page, when given) must
# answer HTTP 200 with no PHP or Smarty error on the page. The first request
# after a cache clear compiles the templates, so a slow or failed answer is
# retried a few times before it counts.
#
# A closed storefront (Administration -> Stores -> status Closed) answers 503
# to everyone, and CS-Cart's error page looks much like it, so a closed store
# is never taken for a healthy one. Set STORE_ACCESS_KEY to the storefront's
# "Access key to temporarily closed store": it is sent as store_access_key,
# and the test sees the storefront as CS-Cart shows it to whoever has the key.
# An open storefront ignores it.
#
# Usage: [STORE_ACCESS_KEY=...] bash scripts/smoke-test.sh https://shop.example [https://shop.example/admin.php]
set -euo pipefail

[ $# -ge 1 ] && [ -n "$1" ] || { echo "usage: bash scripts/smoke-test.sh STORE_URL [ADMIN_URL]" >&2; exit 2; }

# What CS-Cart and PHP print when a page breaks (development mode shows them;
# production mode answers an error status, caught by the status check).
ERRORS='Fatal error|Parse error|<b>Warning</b>:|Uncaught (Error|Exception)|SmartyException|Smarty\\Exception|Stack trace:'

fail=0
check() { # $1 = URL, $2 = what it is, then extra curl options
    local url=$1 label=$2 body headers jar code hint=''
    shift 2
    body=$(mktemp) headers=$(mktemp) jar=$(mktemp)
    for _ in 1 2 3; do
        # The cookie jar carries CS-Cart's session (where it keeps the access
        # key) across redirects.
        code=$(curl -sS -L --max-time 60 -A 'deploy-smoke-test' -b "$jar" -c "$jar" \
            -D "$headers" -o "$body" -w '%{http_code}' "$@" "$url" 2>/dev/null) || code=000
        [ "$code" = 200 ] && break
        sleep "${SMOKE_RETRY_SLEEP:-10}"
    done
    if [ "$code" != 200 ]; then
        # A closed storefront answers 503 with Retry-After.
        if [ "$label" = storefront ] && [ "$code" = 503 ] && grep -qi '^retry-after:' "$headers"; then
            if [ -n "${STORE_ACCESS_KEY:-}" ]; then
                hint=" (closed? STORE_ACCESS_KEY did not open it: compare it with the storefront's access key)"
            else
                hint=" (a closed storefront answers this: set STORE_ACCESS_KEY to its \"Access key to temporarily closed store\", Administration -> Stores)"
            fi
        fi
        echo "FAIL  $label: HTTP $code$hint"
        fail=1
    elif grep -qE "$ERRORS" "$body"; then
        echo "FAIL  $label: an error is printed on the page"
        # Not in GitHub Actions: the repository's logs are public, and the
        # message carries server paths. Open the page to read it.
        if [ "${GITHUB_ACTIONS:-}" != true ]; then
            grep -oE ".{0,80}($ERRORS).{0,120}" "$body" | head -3 | sed 's/^/        /'
        fi
        fail=1
    elif ! grep -qi '<html' "$body"; then
        echo "FAIL  $label: HTTP 200 but no HTML page"
        fail=1
    else
        echo "OK    $label: HTTP 200, no errors on the page"
    fi
    rm -f "$body" "$headers" "$jar"
}

key=()
[ -z "${STORE_ACCESS_KEY:-}" ] || key=(-G --data-urlencode "store_access_key=$STORE_ACCESS_KEY")
check "$1" storefront ${key[@]+"${key[@]}"}
[ -z "${2:-}" ] || check "$2" "admin login"
exit "$fail"
