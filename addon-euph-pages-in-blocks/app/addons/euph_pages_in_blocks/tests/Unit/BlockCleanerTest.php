<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\BlockCleaner;
use Tygh\Addons\EuphPagesInBlocks\BlockSchema;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\FakeDatabase;

/**
 * Uninstall takes the Page content blocks out of every layout, and only
 * them: the other blocks and the pages stay.
 */
final class BlockCleanerTest extends TestCase
{
    private static function store(): FakeDatabase
    {
        return new FakeDatabase([
            'bm_blocks' => [
                ['block_id' => '10', 'type' => BlockSchema::BLOCK_TYPE],
                ['block_id' => '11', 'type' => 'pages'],
                ['block_id' => '12', 'type' => BlockSchema::BLOCK_TYPE],
            ],
            'bm_blocks_descriptions' => [
                ['block_id' => '10', 'lang_code' => 'en'],
                ['block_id' => '10', 'lang_code' => 'ro'],
                ['block_id' => '11', 'lang_code' => 'en'],
                ['block_id' => '12', 'lang_code' => 'en'],
            ],
            'bm_blocks_content' => [
                ['block_id' => '10', 'content' => 'a:0:{}'],
                ['block_id' => '11', 'content' => 'a:0:{}'],
            ],
            'bm_snapping' => [
                ['snapping_id' => '100', 'block_id' => '10'],
                ['snapping_id' => '101', 'block_id' => '11'],
                ['snapping_id' => '102', 'block_id' => '12'],
                ['snapping_id' => '103', 'block_id' => '12'],
            ],
            'bm_block_statuses' => [
                ['snapping_id' => '100', 'object_ids' => '5'],
                ['snapping_id' => '101', 'object_ids' => '5'],
                ['snapping_id' => '103', 'object_ids' => '7'],
            ],
            'pages' => [['page_id' => '5']],
        ]);
    }

    public function testTheBlocksOfTheTypeGoWithEverythingTheyHad(): void
    {
        $db = self::store();

        self::assertSame(2, (new BlockCleaner($db))->removeBlocks(BlockSchema::BLOCK_TYPE));

        self::assertSame([['block_id' => '11', 'type' => 'pages']], $db->tables['bm_blocks']);
        self::assertSame([['block_id' => '11', 'lang_code' => 'en']], $db->tables['bm_blocks_descriptions']);
        self::assertSame([['block_id' => '11', 'content' => 'a:0:{}']], $db->tables['bm_blocks_content']);
        self::assertSame([['snapping_id' => '101', 'block_id' => '11']], $db->tables['bm_snapping']);
        self::assertSame([['snapping_id' => '101', 'object_ids' => '5']], $db->tables['bm_block_statuses']);
        self::assertSame([['page_id' => '5']], $db->tables['pages'], 'the pages are not touched');
    }

    public function testNoBlocksNoChange(): void
    {
        $db = new FakeDatabase(['bm_blocks' => [['block_id' => '11', 'type' => 'pages']], 'bm_snapping' => []]);

        self::assertSame(0, (new BlockCleaner($db))->removeBlocks(BlockSchema::BLOCK_TYPE));
        self::assertCount(1, $db->queries, 'only the look-up');
    }

    public function testMissingTablesAreSkipped(): void
    {
        $db = new FakeDatabase(['bm_blocks' => [['block_id' => '10', 'type' => BlockSchema::BLOCK_TYPE]]]);

        self::assertSame(1, (new BlockCleaner($db))->removeBlocks(BlockSchema::BLOCK_TYPE));
        self::assertSame([], $db->tables['bm_blocks']);
        self::assertSame(0, (new BlockCleaner(new FakeDatabase()))->removeBlocks(BlockSchema::BLOCK_TYPE));
    }

    public function testTheTypeIsAParameterNeverPartOfTheQuery(): void
    {
        $db = self::store();
        (new BlockCleaner($db))->removeBlocks("x' OR '1'='1");

        self::assertSame(['SELECT block_id FROM ?:bm_blocks WHERE type = ?s', ["x' OR '1'='1"]], $db->queries[0]);
        self::assertCount(3, $db->tables['bm_blocks']);
    }
}
