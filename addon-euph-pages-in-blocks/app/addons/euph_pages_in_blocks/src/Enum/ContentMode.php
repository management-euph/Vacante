<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Enum;

/**
 * What the "One under another" template shows of each page.
 */
enum ContentMode: string
{
    /** The whole page: its text, its form or its poll. */
    case Full = 'full';
    /** The start of its text and a "Read more" link to the page. */
    case Excerpt = 'excerpt';
}
