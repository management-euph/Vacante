<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\BlockSchema;
use Tygh\Addons\EuphPagesInBlocks\Enum\Layout;

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

    /**
     * The add-on's schemas/block_manager/blocks.post.php applied to a schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    private static function apply(array $schema): array
    {
        $result = (static function (array $schema): mixed {
            return include dirname(__DIR__, 3) . '/schemas/block_manager/blocks.post.php';
        })($schema);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function type(): array
    {
        $type = self::apply(['pages' => self::CORE_PAGES])[BlockSchema::BLOCK_TYPE] ?? null;
        self::assertIsArray($type);

        /** @var array<string, mixed> $type */
        return $type;
    }

    public function testTheCorePagesBlockIsLeftAsItIs(): void
    {
        $schema = self::apply(['pages' => self::CORE_PAGES, 'menu' => ['templates' => 'blocks/menu']]);

        self::assertSame(self::CORE_PAGES, $schema['pages']);
        self::assertSame(['templates' => 'blocks/menu'], $schema['menu']);
        self::assertSame(['pages', 'menu', BlockSchema::BLOCK_TYPE], array_keys($schema));
    }

    public function testItsContentIsTheCoreManualFillingServedByTheAddon(): void
    {
        $type = self::type();
        $items = $type['content']['items'] ?? null;
        self::assertIsArray($items);

        self::assertSame('enum', $items['type']);
        self::assertSame('pages', $items['object']);
        self::assertSame('fn_euph_pages_in_blocks_get_pages', $items['items_function']);
        self::assertTrue(function_exists($items['items_function']));
        self::assertTrue($items['hide_label']);
        // Only the manual filling, exactly as the core defines it.
        self::assertSame(['manually' => self::CORE_PAGES['content']['items']['fillings']['manually']], $items['fillings']);
    }

    public function testItIsNeverCached(): void
    {
        self::assertArrayNotHasKey('cache', self::type(), "a form's confirmation or a poll's result must not reach the next visitor");
    }

    public function testItHasTheFourTemplatesWithTheirSettings(): void
    {
        $type = self::type();

        self::assertSame('blocks/wrappers', $type['wrappers']);
        self::assertSame(
            [
                'addons/euph_pages_in_blocks/blocks/page_content/stacked.tpl',
                'addons/euph_pages_in_blocks/blocks/page_content/tabs.tpl',
                'addons/euph_pages_in_blocks/blocks/page_content/accordion.tpl',
                'addons/euph_pages_in_blocks/blocks/page_content/cards.tpl',
            ],
            array_keys($type['templates']),
        );
        self::assertSame([], $type['templates'][Layout::Tabs->template()], 'tabs need no settings');
        self::assertSame(
            [BlockSchema::SHOW_TITLES, BlockSchema::SEPARATORS, BlockSchema::CONTENT, BlockSchema::EXCERPT_LENGTH],
            array_keys($type['templates'][Layout::Stacked->template()]['settings']),
        );
        self::assertSame([BlockSchema::OPEN_FIRST, BlockSchema::ONE_OPEN], array_keys($type['templates'][Layout::Accordion->template()]['settings']));
        self::assertSame([BlockSchema::COLUMNS, BlockSchema::EXCERPT_LENGTH], array_keys($type['templates'][Layout::Cards->template()]['settings']));
    }

    public function testTheSettingsAreValidBlockSettings(): void
    {
        foreach (Layout::cases() as $layout) {
            foreach (BlockSchema::settings($layout) as $name => $setting) {
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
        self::assertSame(['2' => '2', '3' => '3', '4' => '4'], BlockSchema::settings(Layout::Cards)[BlockSchema::COLUMNS]['values']);
        self::assertTrue(BlockSchema::settings(Layout::Cards)[BlockSchema::COLUMNS]['no_lang']);
    }

    public function testWithoutACorePagesBlockItKeepsTheCorePicker(): void
    {
        $type = self::apply([])[BlockSchema::BLOCK_TYPE];
        self::assertIsArray($type);
        $items = $type['content']['items'];

        self::assertSame('pages', $items['object']);
        self::assertSame('fn_euph_pages_in_blocks_get_pages', $items['items_function']);
        self::assertSame(['manually' => ['picker' => 'pickers/pages/picker.tpl', 'picker_params' => ['multiple' => true]]], $items['fillings']);
    }

    public function testACorePagesBlockWithoutAManualFillingGetsThePicker(): void
    {
        $items = BlockSchema::items(['content' => ['items' => ['type' => 'enum', 'object' => '', 'fillings' => ['newest' => []]]]]);

        self::assertSame('pages', $items['object']);
        self::assertSame(['manually' => ['picker' => 'pickers/pages/picker.tpl', 'picker_params' => ['multiple' => true]]], $items['fillings']);
    }

    public function testTheSchemaFileKeepsWhatItIsGivenWhenItIsNotAnArray(): void
    {
        $result = (static function (): mixed {
            $schema = 'not a schema';

            return include dirname(__DIR__, 3) . '/schemas/block_manager/blocks.post.php';
        })();

        self::assertIsArray($result);
        self::assertSame([BlockSchema::BLOCK_TYPE], array_keys($result));
    }
}
