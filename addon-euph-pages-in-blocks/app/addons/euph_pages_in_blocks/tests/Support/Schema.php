<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

/**
 * The add-on's block manager schema (schemas/block_manager/blocks.post.php),
 * applied as CS-Cart's fn_get_schema() applies it: included where $schema
 * holds the schema built so far.
 */
final class Schema
{
    public const BLOCK_TYPE = 'euph_page_content';

    /** The layouts, in the order of the Template list. */
    public const LAYOUTS = ['stacked', 'tabs', 'accordion', 'cards'];

    public const TEMPLATE_DIR = 'addons/euph_pages_in_blocks/blocks/page_content/';

    public static function file(): string
    {
        return dirname(__DIR__, 2) . '/schemas/block_manager/blocks.post.php';
    }

    public static function apply(mixed $schema): mixed
    {
        return (static function (mixed $schema): mixed {
            return include Schema::file();
        })($schema);
    }

    /**
     * The Page content block type.
     *
     * @param array<string, mixed> $schema the schema built so far
     *
     * @return array<string, mixed>
     */
    public static function blockType(array $schema = []): array
    {
        $result = self::apply($schema);
        $type = is_array($result) ? $result[self::BLOCK_TYPE] ?? null : null;
        if (!is_array($type)) {
            throw new \LogicException('the schema has no ' . self::BLOCK_TYPE . ' block type');
        }

        /** @var array<string, mixed> $type */
        return $type;
    }

    /**
     * The settings of a layout's template.
     *
     * @return array<string, array<string, mixed>> setting => its definition
     */
    public static function settings(string $layout): array
    {
        $templates = self::blockType()['templates'] ?? null;
        $template = is_array($templates) ? $templates[self::TEMPLATE_DIR . $layout . '.tpl'] ?? null : null;
        if (!is_array($template)) {
            throw new \LogicException('no template for ' . $layout);
        }

        /** @var array<string, array<string, mixed>> */
        return $template['settings'] ?? [];
    }
}
