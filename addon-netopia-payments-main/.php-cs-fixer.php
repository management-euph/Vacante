<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/app/addons/netopia_payments',
        __DIR__ . '/app/payments',
    ])
    ->exclude('lib/Psr')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP83Migration' => true,

        // Imports
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
            'import_constants' => false,
        ],

        // Spacing & alignment
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use']],
        'no_whitespace_in_blank_line' => true,
        'blank_lines_before_namespace' => ['min_line_breaks' => 2, 'max_line_breaks' => 2],

        // Strict
        'declare_strict_types' => false, // CS-Cart procedural files don't use it
        'strict_param' => true,
        'strict_comparison' => true,

        // Clean code
        'no_empty_statement' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'simplified_if_return' => true,
        'cast_spaces' => ['space' => 'single'],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'single_quote' => true,
        'no_trailing_comma_in_singleline' => true,
    ])
    ->setFinder($finder);
