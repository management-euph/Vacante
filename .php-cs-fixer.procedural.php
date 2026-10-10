<?php
declare(strict_types=1);

/**
 * PHP CS Fixer configuration — procedural CS-Cart files (audit M6).
 *
 * The main .php-cs-fixer.dist.php deliberately restricts itself to src/
 * because CS-Cart's procedural conventions don't fit full PSR-12. That left
 * the LARGEST files (controllers/, functions/, hooks/, root func.php/init.php
 * — 800-1200 LOC each) with no format gate at all, so unused imports and
 * whitespace drift accumulated exactly where review is hardest.
 *
 * This config closes the gap with a deliberately CONSERVATIVE, semantics-
 * preserving ruleset: imports and whitespace only — no reformatting, no
 * risky rules, no PSR-12 enforcement. It covers everything under the three
 * addon roots EXCEPT src/ and tests/ (those stay on the stricter main config).
 *
 * Usage:
 *   composer fix:proc       — auto-fix
 *   composer fix:proc:dry   — dry-run (what CI enforces)
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/addon-novoton-holidays/app/addons/novoton_holidays',
        __DIR__ . '/addon-travel-core/app/addons/travel_core',
        __DIR__ . '/addon-sphinx-holidays/app/addons/sphinx_holidays',
        __DIR__ . '/addon-fgo-invoicing/app/addons/fgo_invoicing',
        __DIR__ . '/addon-eurosite/app/addons/eurosite',
    ])
    ->name('*.php')
    // src + tests are formatted by the stricter .php-cs-fixer.dist.php
    ->exclude(['vendor', 'var', 'src', 'tests', 'node_modules'])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();

return $config
    ->setRiskyAllowed(false)
    ->setRules([
        // Imports: the concrete pain named in the audit — dead `use` lines in
        // 1000-LOC controllers. (php-cs-fixer keeps imports referenced only in
        // phpdoc, so PHPStan's docblock type resolution is unaffected.)
        'no_unused_imports'           => true,
        // Whitespace-only hygiene:
        'no_trailing_whitespace'      => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof'    => true,
        'line_ending'                 => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.procedural.cache');
