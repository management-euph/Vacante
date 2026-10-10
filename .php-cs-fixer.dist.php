<?php
declare(strict_types=1);

/**
 * PHP CS Fixer configuration — NovotonBUN monorepo.
 *
 * Targets modern PHP 8.3 + PSR-12, focused on SRC directories.
 * Controllers/hooks/functions are excluded because CS-Cart's procedural
 * conventions (Smarty-style variable access, hook signatures) don't match
 * strict PSR-12 and auto-formatting them would cause noisy diffs.
 *
 * Usage:
 *   composer fix          — auto-fix all issues
 *   composer fix:dry      — show what would change without modifying files
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/addon-novoton-holidays/app/addons/novoton_holidays/src',
        __DIR__ . '/addon-travel-core/app/addons/travel_core/src',
        __DIR__ . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src',
        __DIR__ . '/addon-fgo-invoicing/app/addons/fgo_invoicing/src',
        __DIR__ . '/addon-eurosite/app/addons/eurosite/src',
    ])
    ->name('*.php')
    ->exclude(['vendor', 'var', 'tests', 'node_modules'])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();

return $config
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                            => true,
        '@PHP83Migration'                   => true,
        '@PHP82Migration:risky'             => true,
        'array_syntax'                      => ['syntax' => 'short'],
        'no_unused_imports'                 => true,
        'ordered_imports'                   => ['sort_algorithm' => 'alpha'],
        'single_quote'                      => true,
        'trailing_comma_in_multiline'       => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_extra_blank_lines'              => true,
        'no_whitespace_in_blank_line'       => true,
        'binary_operator_spaces'            => ['default' => 'single_space'],
        'concat_space'                      => ['spacing' => 'one'],
        'native_function_invocation'        => false,          // too noisy for legacy
        'declare_strict_types'              => true,
        'void_return'                       => true,
        'return_type_declaration'           => ['space_before' => 'none'],
        'phpdoc_align'                      => ['align' => 'left'],
        'phpdoc_trim'                       => true,
        'phpdoc_no_empty_return'            => true,
        'no_superfluous_phpdoc_tags'        => ['allow_mixed' => true, 'remove_inheritdoc' => false],
        // Keep CS-Cart's Yoda-conditions hands-off (legacy style)
        'yoda_style'                        => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');
