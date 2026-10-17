<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * The "Page content" block type (euph_page_content): the content of the
 * pages picked on the block's Content tab, drawn by one of four templates
 * (one under another, tabs, accordion, cards).
 *
 * Its content is the core Pages block's own manual filling (the same page
 * picker), served by fn_euph_pages_in_blocks_get_pages() of func.php; the
 * core Pages block itself is left as it is. The block type is named by the
 * block_euph_page_content language variable, each setting by the language
 * variable of its own name; their defaults are those of
 * fn_euph_pages_in_blocks_settings().
 *
 * No 'cache' key: CS-Cart caches only the blocks whose schema asks for it,
 * and a form's "sent" message or a poll's results must never be served from
 * the cache to the next visitor.
 *
 * Built in a closure: fn_get_schema() includes this file in its own scope,
 * and nothing of it leaks there.
 */
return (static function (array $schema): array {
    $core = $schema['pages'] ?? null;
    $core = is_array($core) ? ($core['content'] ?? null) : null;
    $core = is_array($core) ? ($core['items'] ?? null) : null;
    $items = is_array($core) && $core !== [] ? $core : ['remove_indent' => true, 'hide_label' => true];
    $manual = $items['fillings'] ?? null;
    $manual = is_array($manual) ? ($manual['manually'] ?? null) : null;

    $items['type'] = 'enum';
    $items['object'] = 'pages';
    $items['items_function'] = 'fn_euph_pages_in_blocks_get_pages';
    $items['fillings'] = [
        'manually' => is_array($manual) && $manual !== [] ? $manual : [
            'picker' => 'pickers/pages/picker.tpl',
            'picker_params' => ['multiple' => true],
        ],
    ];

    $excerpt_length = ['type' => 'input', 'default_value' => '200'];
    $dir = 'addons/euph_pages_in_blocks/blocks/page_content/';

    $schema['euph_page_content'] = [
        'content' => ['items' => $items],
        // In the order of the Template list.
        'templates' => [
            $dir . 'stacked.tpl' => [
                'settings' => [
                    'euph_pib_show_titles' => ['type' => 'checkbox', 'default_value' => 'Y'],
                    'euph_pib_separators' => ['type' => 'checkbox', 'default_value' => 'Y'],
                    'euph_pib_content' => [
                        'type' => 'selectbox',
                        'values' => ['full' => 'euph_pib_content_full', 'excerpt' => 'euph_pib_content_excerpt'],
                        'default_value' => 'full',
                    ],
                    'euph_pib_excerpt_length' => $excerpt_length,
                ],
            ],
            $dir . 'tabs.tpl' => [],
            $dir . 'accordion.tpl' => [
                'settings' => [
                    'euph_pib_open_first' => ['type' => 'checkbox', 'default_value' => 'N'],
                    'euph_pib_one_open' => ['type' => 'checkbox', 'default_value' => 'Y'],
                ],
            ],
            $dir . 'cards.tpl' => [
                'settings' => [
                    // Cards per row on wide screens.
                    'euph_pib_columns' => [
                        'type' => 'selectbox',
                        'values' => ['2' => '2', '3' => '3', '4' => '4'],
                        'no_lang' => true,
                        'default_value' => '3',
                    ],
                    'euph_pib_excerpt_length' => $excerpt_length,
                ],
            ],
        ],
        'wrappers' => 'blocks/wrappers',
    ];

    return $schema;
})(isset($schema) && is_array($schema) ? $schema : []);
