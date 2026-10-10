#!/usr/bin/env bash
#
# After a deploy: the storefront (and the admin login page, when given) must
# answer HTTP 200 with no PHP or Smarty error on the page. The first request
# after a cache clear compiles the templates, so a slow or failed answer is
# retried a few times before it counts.
#
# Usage: bash scripts/smoke-test.sh https://shop.example [https://shop.example/admin.php]
set -euo pipefail

[ $# -ge 1 ] && [ -n "$1" ] || { echo "usage: bash scripts/smoke-test.sh STORE_URL [ADMIN_URL]" >&2; exit 2; }

# What CS-Cart and PHP print when a page breaks (development mode shows them;
# production answers 500, caught by the status check).
ERRORS='Fatal error|Parse error|<b>Warning</b>:|Uncaught (Error|Exception)|SmartyException|Smarty\\Exception|Stack trace:'

fail=0
check() { # $1 = URL, $2 = what it is
    local url=$1 label=$2 body code
    body=$(mktemp)
    for _ in 1 2 3; do
        code=$(curl -sS -L --max-time 60 -A 'deploy-smoke-test' -o "$body" -w '%{http_code}' "$url" 2>/dev/null) || code=000
        [ "$code" = 200 ] && break
        sleep ${SMOKE_RETRY_SLEEP:-10}
    done
    if [ "$code" != 200 ]; then
        echo "FAIL  $label: HTTP $code"
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
    rm -f "$body"
}

check "$1" storefront
[ -z "${2:-}" ] || check "$2" "admin login"
exit "$fail"
