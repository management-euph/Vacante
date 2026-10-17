<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// func.php only require_once's the function files, so loading it here as
// well is harmless whichever of the two CS-Cart includes first.
//
// No fn_register_hooks(): the block type has its own items function
// (fn_euph_pages_in_blocks_get_pages), so nothing of the store's own page
// lists, menus or page views goes through this add-on.
require_once __DIR__ . '/func.php';
