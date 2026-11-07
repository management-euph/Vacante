# Notes for Claude Code sessions

## Qodana on pull requests

Qodana (JetBrains static analysis) is the last CI gate. The `Qodana` job in
`.github/workflows/ci.yml` runs only after PHPStan, php-cs-fixer, Rector,
every PHPUnit suite, PHP Lint and ESLint have passed. If one of them fails,
Qodana is skipped: fix that first.

On every pull request you open or update, check Qodana's result yourself
after each push, without waiting to be asked, and fix what it finds:

- **Where to read it:** the `Qodana for PHP` check run on the PR's head
  commit (its summary and annotations list the problems), and the
  `Qodana for PHP` comment that github-actions[bot] posts on the PR and
  updates on every run ("No new problems were found" when it is clean). The
  `Qodana` job also uploads `qodana.sarif.json` as a build artifact with the
  full list.
- **Fix every new problem it reports** in the code, on the same branch, and
  push. Verify each finding against the code first. If one is a false
  positive, say why in a PR comment instead of changing the code.
- **Don't make it pass by silencing it:** no new exclusions in `qodana.yaml`
  and no baseline entries, unless the user asks.
- **Run what gates it before pushing:** PHPStan
  (`vendor/bin/phpstan analyse --memory-limit=1G --no-progress`), both
  php-cs-fixer configs (`.php-cs-fixer.dist.php`,
  `.php-cs-fixer.procedural.php`), `vendor/bin/rector process --dry-run`,
  the changed add-ons' PHPUnit, and `php scripts/compile-templates.php`.
- **Leave the Qodana Cloud setup PRs alone** (branches `qodana-automation-*`,
  such as #1 and #94): never merge or fix them. The note above the `qodana`
  job in `ci.yml` says why.
