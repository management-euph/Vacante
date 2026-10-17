<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * The "Page content" block type (euph_page_content): the content of the
 * pages picked on the block's Content tab, drawn by one of four templates
 * (one under another, tabs, accordion, cards). Its content is the core
 * Pages block's manual filling, served by the add-on's items function; the
 * core Pages block itself is left as it is. See BlockSchema.
 *
 * The block's name in the Add block list is the block_euph_page_content
 * language variable.
 */

// The autoloader may not be registered yet when the block manager builds
// its schema (see functions/bootstrap.php).
require_once dirname(__DIR__, 2) . '/functions/bootstrap.php';

$schema = isset($schema) && is_array($schema) ? $schema : [];

return \Tygh\Addons\EuphPagesInBlocks\BlockSchema::extend($schema);
