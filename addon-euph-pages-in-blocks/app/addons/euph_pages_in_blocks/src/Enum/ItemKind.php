<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Enum;

/**
 * How a page shows in the block.
 */
enum ItemKind: string
{
    /** Its content, in the block. */
    case Content = 'content';
    /** A link page: a link to its address, in a new window if the page says so. */
    case Link = 'link';
    /** A page set to show in a popup: a link that opens it in CS-Cart's dialog. */
    case Popup = 'popup';
}
