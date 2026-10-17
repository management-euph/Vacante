<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\CsCart;

/**
 * Uninstall takes the Page content blocks out of every layout, and only
 * them: the other blocks and the pages stay.
 */
final class UninstallTest extends TestCase
{
    private const BLOCKS = 'SELECT block_id FROM ?:bm_blocks WHERE type = ?s';
    private const SNAPPINGS = 'SELECT snapping_id FROM ?:bm_snapping WHERE block_id IN (?n)';

    protected function setUp(): void
    {
        CsCart::reset();
    }

    public function testTheBlocksGoWithTheirPlacementsContentsAndNames(): void
    {
        CsCart::$tables = ['cscart_bm_block_statuses'];
        CsCart::$fields = [self::BLOCKS => ['10', '12'], self::SNAPPINGS => ['100', '102', '103']];

        self::assertTrue(fn_euph_pages_in_blocks_uninstall());

        self::assertSame([
            // The block type is a parameter, never part of the query.
            [self::BLOCKS, ['euph_page_content']],
            [self::SNAPPINGS, [[10, 12]]],
            ['SHOW TABLES LIKE ?s', ['cscart\\_bm\\_block\\_statuses']],
            ['DELETE FROM ?:bm_block_statuses WHERE snapping_id IN (?n)', [[100, 102, 103]]],
            ['DELETE FROM ?:bm_snapping WHERE block_id IN (?n)', [[10, 12]]],
            ['DELETE FROM ?:bm_blocks_content WHERE block_id IN (?n)', [[10, 12]]],
            ['DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)', [[10, 12]]],
            ['DELETE FROM ?:bm_blocks WHERE block_id IN (?n)', [[10, 12]]],
        ], CsCart::$queries);
    }

    public function testNoBlocksNoChange(): void
    {
        self::assertTrue(fn_euph_pages_in_blocks_uninstall());

        self::assertSame([[self::BLOCKS, ['euph_page_content']]], CsCart::$queries, 'only the look-up');
    }

    public function testBlocksNeverPlacedOrWithoutAStatusTable(): void
    {
        CsCart::$fields = [self::BLOCKS => ['10']];
        fn_euph_pages_in_blocks_uninstall();
        $unplaced = array_column(CsCart::$queries, 0);

        CsCart::reset();
        CsCart::$fields = [self::BLOCKS => ['10'], self::SNAPPINGS => ['100']];
        fn_euph_pages_in_blocks_uninstall();
        $noStatuses = array_column(CsCart::$queries, 0);

        $removed = [
            'DELETE FROM ?:bm_snapping WHERE block_id IN (?n)',
            'DELETE FROM ?:bm_blocks_content WHERE block_id IN (?n)',
            'DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)',
            'DELETE FROM ?:bm_blocks WHERE block_id IN (?n)',
        ];
        self::assertSame([self::BLOCKS, self::SNAPPINGS, ...$removed], $unplaced);
        self::assertSame([self::BLOCKS, self::SNAPPINGS, 'SHOW TABLES LIKE ?s', ...$removed], $noStatuses);
    }

    public function testATableIsLookedUpByItsPrefixedName(): void
    {
        CsCart::$tables = ['shop_bm_block_statuses'];
        CsCart::$registry = ['config.table_prefix' => 'shop_'];

        self::assertTrue(fn_euph_pages_in_blocks_table_exists('bm_block_statuses'));
        self::assertFalse(fn_euph_pages_in_blocks_table_exists('bm_nothing'));
        // The wildcards of LIKE are escaped: shop_bm_x does not match shopXbmXx.
        self::assertSame([['SHOW TABLES LIKE ?s', ['shop\\_bm\\_block\\_statuses']], ['SHOW TABLES LIKE ?s', ['shop\\_bm\\_nothing']]], CsCart::$queries);

        CsCart::$registry = [];
        CsCart::$tables = ['cscart_bm_block_statuses'];
        self::assertTrue(fn_euph_pages_in_blocks_table_exists('bm_block_statuses'), "CS-Cart's default prefix");
    }
}
