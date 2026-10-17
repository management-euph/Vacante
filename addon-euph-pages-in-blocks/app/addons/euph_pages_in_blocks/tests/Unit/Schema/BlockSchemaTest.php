<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Schema;

/**
 * The Page content block type in the block manager schema: a dedicated
 * type next to the core Pages block, which it leaves as it is.
 */
final class BlockSchemaTest extends TestCase
{
    /** The core Pages block, in the shape of app/schemas/block_manager/blocks.php. */
    private const CORE_PAGES = [
        'content' => [
            'items' => [
                'remove_indent' => true,
                'hide_label' => true,
                'type' => 'enum',
                'object' => 'pages',
                'items_function' => 'fn_get_pages',
                'fillings' => [
                    'manually' => [
                        'picker' => 'pickers/pages/picker.tpl',
                        'picker_params' => ['multiple' => true, 'core_only_param' => 'kept'],
                    ],
                    'newest' => ['params' => ['sort_by' => 'timestamp', 'status' => 'A']],
                    'dynamic_tree_pages' => ['params' => ['get_tree' => 'plain']],
                ],
            ],
        ],
        'templates' => 'blocks/pages',
        'wrappers' => 'blocks/wrappers',
        'cache' => ['update_handlers' => ['pages', 'page_descriptions']],
    ];

    private const PICKER = ['manually' => ['picker' => 'pickers/pages/picker.tpl', 'picker_params' => ['multiple' => true]]];

    /**
     * @param array<string, mixed> $type
     *
     * @return array<string, mixed>
     */
    private static function items(array $type): array
    {
        $content = $type['content'] ?? null;
        self::assertIsArray($content);
        self::assertIsArray($content['items'] ?? null);

        /** @var array<string, mixed> */
        return $content['items'];
    }

    public function testTheCorePagesBlockIsLeftAsItIs(): void
    {
        $schema = Schema::apply(['pages' => self::CORE_PAGES, 'menu' => ['templates' => 'blocks/menu']]);

        self::assertIsArray($schema);
        self::assertSame(self::CORE_PAGES, $schema['pages']);
        self::assertSame(['templates' => 'blocks/menu'], $schema['menu']);
        self::assertSame(['pages', 'menu', Schema::BLOCK_TYPE], array_keys($schema));
    }

    public function testItsContentIsTheCoreManualFillingServedByTheAddon(): void
    {
        $items = self::items(Schema::blockType(['pages' => self::CORE_PAGES]));

        self::assertSame('enum', $items['type']);
        self::assertSame('pages', $items['object']);
        self::assertSame('fn_euph_pages_in_blocks_get_pages', $items['items_function']);
        self::assertTrue(function_exists('fn_euph_pages_in_blocks_get_pages'));
        self::assertTrue($items['hide_label']);
        // Only the manual filling, exactly as the core defines it.
        self::assertSame(['manually' => self::CORE_PAGES['content']['items']['fillings']['manually']], $items['fillings']);
    }

    public function testItIsNeverCached(): void
    {
        self::assertArrayNotHasKey('cache', Schema::blockType(['pages' => self::CORE_PAGES]), "a form's confirmation or a poll's result must not reach the next visitor");
    }

    public function testItHasTheFourTemplatesWithTheirSettings(): void
    {
        $type = Schema::blockType();
        self::assertIsArray($type['templates']);

        self::assertSame('blocks/wrappers', $type['wrappers']);
        self::assertSame(array_map(static fn (string $layout): string => Schema::TEMPLATE_DIR . $layout . '.tpl', Schema::LAYOUTS), array_keys($type['templates']));
        self::assertSame([], $type['templates'][Schema::TEMPLATE_DIR . 'tabs.tpl'], 'tabs need no settings');
        self::assertSame(['euph_pib_show_titles', 'euph_pib_separators', 'euph_pib_content', 'euph_pib_excerpt_length'], array_keys(Schema::settings('stacked')));
        self::assertSame(['euph_pib_open_first', 'euph_pib_one_open'], array_keys(Schema::settings('accordion')));
        self::assertSame(['euph_pib_columns', 'euph_pib_excerpt_length'], array_keys(Schema::settings('cards')));
    }

    public function testTheSettingsAreValidBlockSettings(): void
    {
        foreach (Schema::LAYOUTS as $layout) {
            foreach (Schema::settings($layout) as $name => $setting) {
                self::assertMatchesRegularExpression('/^euph_pib_[a-z_]+$/', $name, 'a block property and a language variable');
                self::assertContains($setting['type'], ['checkbox', 'selectbox', 'input'], $name);
                self::assertIsString($setting['default_value'], $name);
                if ($setting['type'] === 'checkbox') {
                    self::assertContains($setting['default_value'], ['Y', 'N'], $name);
                }
                if ($setting['type'] === 'selectbox') {
                    self::assertIsArray($setting['values']);
                    self::assertArrayHasKey($setting['default_value'], $setting['values'], $name . ': its default is one of its choices');
                }
            }
        }
        self::assertSame(['2' => '2', '3' => '3', '4' => '4'], Schema::settings('cards')['euph_pib_columns']['values']);
        self::assertTrue(Schema::settings('cards')['euph_pib_columns']['no_lang']);
    }

    public function testWithoutACorePagesBlockItKeepsTheCorePicker(): void
    {
        $items = self::items(Schema::blockType());

        self::assertTrue($items['remove_indent']);
        self::assertTrue($items['hide_label']);
        self::assertSame('pages', $items['object']);
        self::assertSame('fn_euph_pages_in_blocks_get_pages', $items['items_function']);
        self::assertSame(self::PICKER, $items['fillings']);
    }

    public function testACorePagesBlockWithoutAManualFillingGetsThePicker(): void
    {
        $items = self::items(Schema::blockType(['pages' => ['content' => ['items' => ['type' => 'list', 'object' => '', 'fillings' => ['newest' => []]]]]]));

        self::assertSame('enum', $items['type']);
        self::assertSame('pages', $items['object']);
        self::assertSame(self::PICKER, $items['fillings']);
        self::assertSame(self::PICKER, self::items(Schema::blockType(['pages' => 'not a block']))['fillings']);
    }

    public function testTheSchemaFileKeepsWhatItIsGivenWhenItIsNotAnArray(): void
    {
        $result = Schema::apply('not a schema');

        self::assertIsArray($result);
        self::assertSame([Schema::BLOCK_TYPE], array_keys($result));
    }

    public function testNothingLeaksIntoTheScopeThatIncludesIt(): void
    {
        // fn_get_schema() includes the file in its own scope, amid its own
        // variables.
        $variables = (static function (): array {
            $schema = [];
            $schema = include Schema::file();

            return array_keys(get_defined_vars());
        })();

        self::assertSame(['schema'], $variables);
    }
}
