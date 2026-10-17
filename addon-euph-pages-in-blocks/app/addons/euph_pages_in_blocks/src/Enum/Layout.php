<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Enum;

/**
 * The templates of the Page content block, one per layout. The value is the
 * template's file name under blocks/page_content/.
 */
enum Layout: string
{
    case Stacked = 'stacked';
    case Tabs = 'tabs';
    case Accordion = 'accordion';
    case Cards = 'cards';

    public const TEMPLATE_DIR = 'addons/euph_pages_in_blocks/blocks/page_content/';

    public function template(): string
    {
        return self::TEMPLATE_DIR . $this->value . '.tpl';
    }

    /** The language variable naming the template in the block's Template list. */
    public function descriptionKey(): string
    {
        return 'tmpl_euph_pib_' . $this->value;
    }

    /** Cards are teasers: they always show an excerpt and link to the page. */
    public function alwaysExcerpts(): bool
    {
        return $this === self::Cards;
    }
}
