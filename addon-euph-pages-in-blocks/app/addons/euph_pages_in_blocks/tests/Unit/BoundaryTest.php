<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\BlockSchema;
use Tygh\Addons\EuphPagesInBlocks\Db\CsCartDatabase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\CsCart;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Pages;

/**
 * The functions CS-Cart calls (functions/*.php), on the stubs of
 * Support/stubs.php.
 */
final class BoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        CsCart::reset();
    }

    public function testTheItemsFunctionReadsThePickedPagesInTheirOrder(): void
    {
        CsCart::$pages = [3 => Pages::page(3, 'Three'), 1 => Pages::page(1, 'One')];
        $params = ['item_ids' => '3,2,1', 'block_data' => ['block_id' => 40]];

        [$pages, $returned] = fn_euph_pages_in_blocks_get_pages($params);

        self::assertSame(['Three', 'One'], array_column($pages, 'page'));
        self::assertSame($params, $returned, 'the params go back as fn_get_pages() gives them');
        self::assertSame([[3, 'en'], [2, 'en'], [1, 'en']], CsCart::$pageCalls, 'in the storefront language');
    }

    public function testTheItemsFunctionTakesTheLanguageItIsGiven(): void
    {
        CsCart::$pages = [1 => Pages::page(1, 'Unu')];

        fn_euph_pages_in_blocks_get_pages(['item_ids' => [1], 'lang_code' => 'ro']);

        self::assertSame([[1, 'ro']], CsCart::$pageCalls);
    }

    public function testTheItemsFunctionWithoutPages(): void
    {
        self::assertSame([[], []], fn_euph_pages_in_blocks_get_pages());
        self::assertSame([[], []], fn_euph_pages_in_blocks_get_pages('garbage'));
        self::assertSame([[], ['item_ids' => '']], fn_euph_pages_in_blocks_get_pages(['item_ids' => '']));
        self::assertSame([], CsCart::$pageCalls);
    }

    public function testTheViewFunctionDrawsWithCsCartsAddresses(): void
    {
        $view = fn_euph_pages_in_blocks_view([Pages::page(5, 'Delivery')], Pages::block([], 901), 'tabs');

        self::assertSame('euph-pib-901', $view['id']);
        self::assertSame('tabs', $view['layout']);
        self::assertIsArray($view['items']);
        self::assertSame('https://shop.example/index.php?dispatch=pages.view&page_id=5', $view['items'][0]['url']);
        self::assertSame([], fn_euph_pages_in_blocks_view(null, null));
    }

    public function testTheContainerIsBuiltOnce(): void
    {
        self::assertSame(fn_euph_pages_in_blocks_container(), fn_euph_pages_in_blocks_container());
        self::assertSame(fn_euph_pages_in_blocks_container()->presenter(), fn_euph_pages_in_blocks_container()->presenter());
        self::assertSame(fn_euph_pages_in_blocks_container()->loader(), fn_euph_pages_in_blocks_container()->loader());
    }

    public function testUninstallRemovesTheBlocksOfTheType(): void
    {
        CsCart::$tables = ['cscart_bm_blocks', 'cscart_bm_snapping', 'cscart_bm_blocks_content', 'cscart_bm_blocks_descriptions'];
        CsCart::$fields = [
            'SELECT block_id FROM ?:bm_blocks WHERE type = ?s' => ['10', '12'],
            'SELECT snapping_id FROM ?:bm_snapping WHERE block_id IN (?n)' => [],
        ];

        self::assertTrue(fn_euph_pages_in_blocks_uninstall());

        $select = array_values(array_filter(CsCart::$queries, static fn (array $query): bool => $query[0] === 'SELECT block_id FROM ?:bm_blocks WHERE type = ?s'));
        self::assertSame([['SELECT block_id FROM ?:bm_blocks WHERE type = ?s', [BlockSchema::BLOCK_TYPE]]], $select);
        $deletes = array_values(array_filter(CsCart::$queries, static fn (array $query): bool => str_starts_with($query[0], 'DELETE')));
        self::assertSame([
            ['DELETE FROM ?:bm_snapping WHERE block_id IN (?n)', [[10, 12]]],
            ['DELETE FROM ?:bm_blocks_content WHERE block_id IN (?n)', [[10, 12]]],
            ['DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)', [[10, 12]]],
            ['DELETE FROM ?:bm_blocks WHERE block_id IN (?n)', [[10, 12]]],
        ], $deletes);
    }

    public function testCsCartDatabaseLooksTablesUpByTheirPrefixedNameOnce(): void
    {
        CsCart::$tables = ['shop_bm_blocks'];
        $db = new CsCartDatabase('shop_');

        self::assertTrue($db->tableExists('?:bm_blocks'));
        self::assertTrue($db->tableExists('bm_blocks'));
        self::assertFalse($db->tableExists('?:bm_snapping'));
        self::assertSame(
            [['SHOW TABLES LIKE ?s', ['shop\\_bm\\_blocks']], ['SHOW TABLES LIKE ?s', ['shop\\_bm\\_snapping']]],
            CsCart::$queries,
            'a name is looked up once, with its wildcards escaped',
        );
        self::assertSame(1, $db->execute('DELETE FROM ?:bm_blocks WHERE block_id IN (?n)', [1]));
        self::assertSame([], $db->column('SELECT block_id FROM ?:bm_blocks WHERE type = ?s', 'none'));
    }
}
