<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

use Tygh\Addons\EuphPagesInBlocks\Db\Database;

/**
 * Takes the blocks of a block type out of every layout: their placements
 * (and the per-page statuses of those), their contents, their names and the
 * blocks themselves. Run at uninstall: once the add-on is gone the block
 * manager no longer knows the type, and its blocks would stay in the layouts.
 * The pages they showed are not touched.
 */
final class BlockCleaner
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return int how many blocks were removed
     */
    public function removeBlocks(string $blockType): int
    {
        if (!$this->db->tableExists('?:bm_blocks')) {
            return 0;
        }
        $blockIds = Cast::idList($this->db->column('SELECT block_id FROM ?:bm_blocks WHERE type = ?s', $blockType));
        if ($blockIds === []) {
            return 0;
        }

        if ($this->db->tableExists('?:bm_snapping')) {
            $snappingIds = Cast::idList($this->db->column('SELECT snapping_id FROM ?:bm_snapping WHERE block_id IN (?n)', $blockIds));
            if ($snappingIds !== [] && $this->db->tableExists('?:bm_block_statuses')) {
                $this->db->execute('DELETE FROM ?:bm_block_statuses WHERE snapping_id IN (?n)', $snappingIds);
            }
            $this->db->execute('DELETE FROM ?:bm_snapping WHERE block_id IN (?n)', $blockIds);
        }
        foreach (['?:bm_blocks_content', '?:bm_blocks_descriptions'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->execute('DELETE FROM ' . $table . ' WHERE block_id IN (?n)', $blockIds);
            }
        }
        $this->db->execute('DELETE FROM ?:bm_blocks WHERE block_id IN (?n)', $blockIds);

        return count($blockIds);
    }
}
