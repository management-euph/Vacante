<#
.SYNOPSIS
    Bring the local Docker store up to date with the repo, in one command.

.DESCRIPTION
    The store links each addon's folders straight from this checkout
    (link-addons.sh), so a file deleted in git disappears with `git pull`,
    and a changed template recompiles on the next page (development mode).
    This script does the rest:

      1. git pull (after switching to -Branch, when given);
      2. re-links the addons with the working-tree link-addons.sh: new
         folders get linked, and per-file links (HP Sure Click checkouts)
         of deleted files are dropped;
      3. clears CS-Cart's cache.

    Then open any admin page once (new language labels add themselves) and
    hard-refresh the storefront (Ctrl+Shift+R).

    Local sandbox only: a server where the files are COPIED also needs the
    deleted files removed (docs/INSTALL.md, section 2).

.EXAMPLE
    .\update.ps1
    Pulls the branch you are on.

.EXAMPLE
    .\update.ps1 -Branch main

.EXAMPLE
    .\update.ps1 -Branch claude/focused-johnson-krch9q
    Tries a pull request's branch before it is merged.
#>
param(
    [string]$Branch = ''
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$compose = Join-Path $PSScriptRoot 'docker-compose.yml'

function Invoke-Step([string]$Title, [scriptblock]$Command) {
    Write-Host "==> $Title" -ForegroundColor Cyan
    & $Command
    if ($LASTEXITCODE -ne 0) {
        throw "Step failed: $Title (exit code $LASTEXITCODE)."
    }
}

if ($Branch -ne '') {
    Invoke-Step "Fetch $Branch" { git -C $repo fetch origin $Branch }
    Invoke-Step "Switch to $Branch" { git -C $repo checkout $Branch }
}
# --ff-only: never creates a merge commit. If it stops, the local branch has
# commits of its own (or the remote one was rewritten): sort that out in git.
Invoke-Step 'Pull the latest code' { git -C $repo pull --ff-only }
Write-Host ('    now at: ' + (git -C $repo log -1 --format='%h %s'))

Invoke-Step 'Re-link the addons into the store' {
    docker compose -f $compose exec -T app bash /repo/docker/fullstore/link-addons.sh
}
Invoke-Step 'Clear the CS-Cart cache' {
    docker compose -f $compose exec -T app sh -c 'rm -rf /var/www/html/var/cache/*'
}

Write-Host ''
Write-Host 'Done. Next:' -ForegroundColor Green
Write-Host '  1. Open any admin page once (http://localhost:8080/admin.php): new language labels add themselves.'
Write-Host '  2. Hard-refresh the storefront (Ctrl+Shift+R).'
