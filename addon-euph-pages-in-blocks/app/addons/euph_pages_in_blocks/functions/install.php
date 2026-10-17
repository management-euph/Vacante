<?php

declare(strict_types=1);

use Tygh\Addons\EuphPagesInBlocks\BlockSchema;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Uninstall: the Page content blocks leave every layout (with their
 * contents, names and placements). The pages are not touched; CS-Cart
 * removes the add-on's settings and texts itself.
 */
function fn_euph_pages_in_blocks_uninstall(): bool
{
    fn_euph_pages_in_blocks_container()->cleaner()->removeBlocks(BlockSchema::BLOCK_TYPE);

    return true;
}
