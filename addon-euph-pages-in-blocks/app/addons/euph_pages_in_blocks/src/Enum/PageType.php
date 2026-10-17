<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Enum;

/**
 * The page types of Website → Pages (pages.page_type). A form needs the
 * Form builder add-on, a poll the Polls add-on; they draw them through the
 * pages:page_content hook, as on the page itself.
 */
enum PageType: string
{
    case Text = 'T';
    case Link = 'L';
    case Form = 'F';
    case Poll = 'P';

    /**
     * Any other type (the blog's posts, another add-on's own) shows its
     * text, as a text page does.
     */
    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? self::Text;
    }

    /** The class suffix of the page in the templates. */
    public function slug(): string
    {
        return strtolower($this->name);
    }

    /**
     * Forms and polls also draw through pages:page_extra; a text page does
     * not take it, so the comments and share buttons of a page's own view
     * stay out of the block.
     */
    public function usesExtraHook(): bool
    {
        return $this === self::Form || $this === self::Poll;
    }
}
