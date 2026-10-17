<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

/**
 * The pages of a block, as the storefront shows them.
 *
 * Each page is read the way CS-Cart reads it for the page's own view
 * (fn_get_page_data()): the same status, user group, availability period
 * and storefront rules apply, and the Form builder and Polls add-ons attach
 * the form and the poll through their own hooks. A page the visitor may not
 * see is left out; the others keep the order they were picked in.
 */
final class PageLoader
{
    /** A block shows at most this many pages. */
    public const MAX_PAGES = 50;

    /**
     * @param \Closure(int, string): mixed $fetch one page by id and language, false or empty when it is not shown
     */
    public function __construct(private readonly \Closure $fetch)
    {
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    public function load(array $pageIds, string $langCode): array
    {
        $pages = [];
        foreach (array_slice(array_values(array_unique($pageIds)), 0, self::MAX_PAGES) as $pageId) {
            $page = Cast::map(($this->fetch)($pageId, $langCode));
            // Disabled pages are left out in any area; the storefront query
            // already leaves them out, the admin one does not.
            if ($page === [] || Cast::int($page['page_id'] ?? 0) !== $pageId || Cast::string($page['status'] ?? '') === 'D') {
                continue;
            }
            $pages[] = $page;
        }

        return $pages;
    }
}
