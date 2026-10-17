# Development & Quality Tooling

The monorepo uses a layered quality-assurance pipeline:

1. **Pre-commit** (GrumPHP) — fast local checks that block bad commits
2. **CI on every push/PR** (GitHub Actions) — full suite
3. **Manual commands** (`composer …`) — run any tool on demand

## Installed tools

| Tool                  | Purpose                                | Config file              | Level/Standard     |
|-----------------------|----------------------------------------|--------------------------|--------------------|
| PHPStan               | Static analysis                        | `phpstan.neon`           | level 10 (max), empty baseline |
| PHP CS Fixer          | Auto-formatter                         | `.php-cs-fixer.dist.php` (src/tests) + `.php-cs-fixer.procedural.php` (controllers/functions/hooks) | PSR-12 + PHP 8.3 / conservative |
| Rector                | Automated refactoring rules            | `rector.php`             | dry-run gate in CI |
| GrumPHP               | Pre-commit hook runner                 | `grumphp.yml`            | —                  |
| PHPUnit               | Tests (all four addons)                | `addon-*/app/addons/*/phpunit.xml` (+ novoton `phpunit-integration.xml`) | — |
| ESLint                | Frontend JS/JSX lint                   | `eslint.config.mjs`      | —                  |
| Qodana                | JetBrains static analysis (CI final gate → Qodana Cloud) | `qodana.yaml` | qodana.recommended, tuned |
| Psalm / PHPCS / PHPMD | Binaries installed, **no committed rulesets** — ad-hoc use only | — | not in pipeline |

## Quick start

```bash
# Install everything (root composer install handles all dev tools)
composer install

# Install addon test deps (only if running PHPUnit)
cd addon-novoton-holidays/app/addons/novoton_holidays && composer install

# Install the GrumPHP pre-commit hook (one-time, safe to re-run).
# Separate step so CI skips it (CI has nothing to commit).
composer hooks:install
ls .git/hooks/pre-commit   # verify
```

## Common commands

```bash
# ── Run one tool ──
composer stan          # PHPStan (level 10)
composer fix:dry       # Show PHP CS Fixer diff, both configs (no changes written)
composer fix:proc:dry  # Procedural-files config only (controllers/functions/hooks)
composer rector:dry    # Rector dry-run (fails on drift)
composer test          # PHPUnit — all addon suites
composer test:novoton  # (or test:travel-core / test:sphinx / test:fgo / test:euph / test:euph-pib)

# ── Auto-fix ──
composer fix           # PHP CS Fixer: apply both configs
composer rector:fix    # Rector: apply refactoring rules

# ── Pre-commit hook ──
composer hooks:install    # Install the GrumPHP pre-commit hook (one-time)
composer hooks:uninstall  # Remove it (e.g. temporary opt-out)

# ── Baseline ──
composer stan:baseline # Regenerate PHPStan baseline (should stay empty)

# ── Run the full pipeline ──
composer check         # stan + fix:dry + rector:dry + test

# ── DB-backed integration tests (needs docker-compose.test.yml up) ──
composer test:integration:novoton
```

## Pipeline rules

### PHPStan (level 10 — maximum)
- Current baseline: **0 errors** (`phpstan-baseline.neon` is empty — fully paid down)
- **All code must pass level 10 clean** — any new error fails the build outright

### PHP CS Fixer
- Dry-run in CI (both configs): fails if `composer fix` would change anything
- Run `composer fix` locally before committing to format your changes

### Rector
- Dry-run in CI: fails if any configured rule (see `rector.php`) would change a file
- Run `composer rector:fix` to apply

### Qodana
- Final CI gate (runs after everything else is green); reports to Qodana Cloud
- Inspection profile + muted framework false-positives live in `qodana.yaml`
- Findings are informational (no fail-threshold); triage them in the cloud report

## Pre-commit hook (GrumPHP)

After `composer install`, GrumPHP installs a `.git/hooks/pre-commit` script that runs
(see `grumphp.yml`):

1. `phplint` — syntax check on changed files
2. PHP CS Fixer dry-run (`.php-cs-fixer.dist.php`) on changed files
3. PHPStan (full run, level 10)
4. `composer validate`

Commits that fail any of these checks are **rejected locally**, before reaching
the remote. To bypass (not recommended):

```bash
git commit --no-verify -m "…"
```

## CI (GitHub Actions)

File: `.github/workflows/ci.yml`

Runs on every `push` (any branch) and `pull_request` to `main`:

| Job                       | Fails CI? | What it runs                                              |
|---------------------------|-----------|-----------------------------------------------------------|
| PHPStan (L10 + ratchet)   | yes       | `vendor/bin/phpstan analyse` + baseline-may-only-shrink check |
| PHP CS Fixer (dry-run)    | yes       | both configs: `.php-cs-fixer.dist.php` and `.php-cs-fixer.procedural.php` |
| Rector (dry-run)          | yes       | `vendor/bin/rector process --dry-run`                     |
| PHPUnit ×3                | yes       | novoton (with coverage artifact), travel_core, sphinx     |
| PHPUnit integration       | no        | novoton DB-backed suite — runs only when `INTEGRATION_TESTDB_READY=true` |
| PHP Lint                  | yes       | `php -l` on the whole addon trees (excl. vendor)          |
| ESLint (JS/JSX)           | yes       | `npx eslint .` (addon JS + `react-src`)                   |
| Qodana                    | scan errors only | `JetBrains/qodana-action` → Qodana Cloud (`QODANA_TOKEN_*` secret) |

**Qodana is the final gate**: its `needs` list makes it run only after PHPStan,
CS Fixer, Rector, all PHPUnit suites, Lint, and ESLint have passed. Findings are
published to Qodana Cloud / PR annotations (profile + muted inspections live in
`qodana.yaml`); the job itself only fails on scan errors — no `fail-threshold`
is set, so findings are informational by design.

PHP CS Fixer runs **two configs**: `.php-cs-fixer.dist.php` (full ruleset, `src/` +
tests) and `.php-cs-fixer.procedural.php` (conservative imports/whitespace-only
pass over `controllers/`, `functions/`, `hooks/`, and addon-root `func.php`/
`init.php` — CS-Cart procedural conventions make full PSR-12 too noisy there).

(Psalm, PHPCS, and PHPMD are **not CI jobs**. Their binaries ship in `vendor/bin`,
but no project rulesets (`psalm.xml`/`phpcs.xml`/`phpmd.xml`) or composer scripts
are committed — run them ad-hoc if needed. The GrumPHP pre-commit hook
(`grumphp.yml`) runs: phplint, PHP CS Fixer (dist config), PHPStan, and
`composer validate` on changed files.)

## Fixing common issues

### "PHPStan reported new errors not in the baseline"
Write proper types or use helpers (`PriceInfoFormatter::toFloat/toInt/toScalar`
in novoton, `ValidationHelpers::toString/toFloat/toInt` in travel_core).

### "PHP CS Fixer would change this file"
Run `composer fix` to apply the formatter's suggestions. Review the diff and
commit.

### "Rector would change this file"
Run `composer rector:fix`, review the diff, and commit. If a rule misfires on
CS-Cart idioms, exclude the path/rule in `rector.php` instead of hand-fighting it.

### "Qodana flags an undefined CS-Cart symbol"
Framework symbols (`Tygh\…`, `fn_*`, `db_*`, AREA-style constants) live outside
this repo; those inspections are already muted in `qodana.yaml`. If a new
false-positive class appears, add the inspection id there — don't sprinkle
suppression comments in code.

### GrumPHP is too slow / blocks my commit
Individual tasks can be disabled in `grumphp.yml`. Alternatively, use
`git commit --no-verify` for the specific commit, but run `composer check`
before pushing.
