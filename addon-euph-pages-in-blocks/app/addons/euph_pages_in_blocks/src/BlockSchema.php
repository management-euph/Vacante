<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

use Tygh\Addons\EuphPagesInBlocks\Enum\ContentMode;
use Tygh\Addons\EuphPagesInBlocks\Enum\Layout;

/**
 * The "Page content" block type of the block manager
 * (schemas/block_manager/blocks.post.php).
 *
 * Its content is the core Pages block's own manual filling (the same page
 * picker, the same dynamic-object support), served by the add-on's items
 * function. Its templates are the four layouts, each with its settings: the
 * keys below are both the block properties and the language variables that
 * label them in the block's Settings tab.
 */
final class BlockSchema
{
    public const BLOCK_TYPE = 'euph_page_content';
    public const ITEMS_FUNCTION = 'fn_euph_pages_in_blocks_get_pages';

    public const SHOW_TITLES = 'euph_pib_show_titles';
    public const SEPARATORS = 'euph_pib_separators';
    public const CONTENT = 'euph_pib_content';
    public const EXCERPT_LENGTH = 'euph_pib_excerpt_length';
    public const OPEN_FIRST = 'euph_pib_open_first';
    public const ONE_OPEN = 'euph_pib_one_open';
    public const COLUMNS = 'euph_pib_columns';

    /** Labels of the "Show" choices of the One under another template. */
    public const CONTENT_LABELS = [
        'full' => 'euph_pib_content_full',
        'excerpt' => 'euph_pib_content_excerpt',
    ];

    /**
     * The content definition used when the core schema has no Pages block
     * to take it from. It is the core's: the multiple page picker.
     */
    private const FALLBACK_ITEMS = [
        'remove_indent' => true,
        'hide_label' => true,
        'type' => 'enum',
        'object' => 'pages',
        'fillings' => [
            'manually' => [
                'picker' => 'pickers/pages/picker.tpl',
                'picker_params' => [
                    'multiple' => true,
                ],
            ],
        ],
    ];

    /**
     * @param array<array-key, mixed> $schema the block manager schema built so far
     *
     * @return array<array-key, mixed>
     */
    public static function extend(array $schema): array
    {
        $schema[self::BLOCK_TYPE] = self::blockType($schema['pages'] ?? null);

        return $schema;
    }

    /**
     * @param mixed $corePages the core Pages block's schema
     *
     * @return array<string, mixed>
     */
    public static function blockType(mixed $corePages): array
    {
        // No 'cache' key: CS-Cart caches only the blocks whose schema asks
        // for it, and a form's "sent" message or a poll's results must never
        // be served from the cache to the next visitor.
        return [
            'content' => [
                'items' => self::items($corePages),
            ],
            'templates' => self::templates(),
            'wrappers' => 'blocks/wrappers',
        ];
    }

    /**
     * The core Pages block's items, on the manual filling only, served by
     * the add-on's items function.
     *
     * @return array<string, mixed>
     */
    public static function items(mixed $corePages): array
    {
        $core = Cast::map(Cast::map(Cast::map($corePages)['content'] ?? null)['items'] ?? null);
        $manual = Cast::map(Cast::map($core['fillings'] ?? null)['manually'] ?? null);

        $items = $core === [] ? Cast::map(self::FALLBACK_ITEMS) : $core;
        $items['type'] = 'enum';
        if (Cast::string($items['object'] ?? '') === '') {
            $items['object'] = 'pages';
        }
        $items['items_function'] = self::ITEMS_FUNCTION;
        $items['fillings'] = [
            'manually' => $manual !== [] ? $manual : self::FALLBACK_ITEMS['fillings']['manually'],
        ];

        return $items;
    }

    /**
     * The templates of the block type, in the order of the Template list.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function templates(): array
    {
        $templates = [];
        foreach (Layout::cases() as $layout) {
            $settings = self::settings($layout);
            $templates[$layout->template()] = $settings === [] ? [] : ['settings' => $settings];
        }

        return $templates;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function settings(Layout $layout): array
    {
        $excerptLength = [
            'type' => 'input',
            'default_value' => (string) BlockSettings::DEFAULT_EXCERPT_LENGTH,
        ];

        return match ($layout) {
            Layout::Stacked => [
                self::SHOW_TITLES => ['type' => 'checkbox', 'default_value' => 'Y'],
                self::SEPARATORS => ['type' => 'checkbox', 'default_value' => 'Y'],
                self::CONTENT => [
                    'type' => 'selectbox',
                    'values' => self::CONTENT_LABELS,
                    'default_value' => ContentMode::Full->value,
                ],
                self::EXCERPT_LENGTH => $excerptLength,
            ],
            Layout::Tabs => [],
            Layout::Accordion => [
                self::OPEN_FIRST => ['type' => 'checkbox', 'default_value' => 'N'],
                self::ONE_OPEN => ['type' => 'checkbox', 'default_value' => 'Y'],
            ],
            Layout::Cards => [
                self::COLUMNS => [
                    'type' => 'selectbox',
                    'values' => array_combine(
                        array_map('strval', BlockSettings::COLUMNS),
                        array_map('strval', BlockSettings::COLUMNS),
                    ),
                    'no_lang' => true,
                    'default_value' => (string) BlockSettings::DEFAULT_COLUMNS,
                ],
                self::EXCERPT_LENGTH => $excerptLength,
            ],
        };
    }
}
