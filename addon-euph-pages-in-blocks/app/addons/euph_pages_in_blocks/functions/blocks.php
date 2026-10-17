<?php

declare(strict_types=1);

use Tygh\Addons\EuphPagesInBlocks\Cast;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Items of the Page content block: the block manager calls it with the
 * filling's params and the block's content (item_ids, the pages picked on
 * the Content tab). Returns them as the storefront shows them, in the order
 * they were picked, as fn_get_pages() does: [pages, params].
 *
 * @param mixed $params the filling's params, with item_ids
 *
 * @return array{0: list<array<string, mixed>>, 1: array<array-key, mixed>}
 */
function fn_euph_pages_in_blocks_get_pages(mixed $params = []): array
{
    $params = is_array($params) ? $params : [];
    $langCode = Cast::string($params['lang_code'] ?? '');
    if ($langCode === '') {
        $langCode = defined('CART_LANGUAGE') ? Cast::string(constant('CART_LANGUAGE'), 'en') : 'en';
    }
    $pages = fn_euph_pages_in_blocks_container()->loader()->load(Cast::idList($params['item_ids'] ?? ''), $langCode);

    return [$pages, $params];
}

/**
 * The view of a Page content block for its template:
 * {$pib = fn_euph_pages_in_blocks_view($items, $block, "tabs")}.
 *
 * @param mixed $items the block's items
 * @param mixed $block the block being drawn
 * @param mixed $layout the template's layout: stacked, tabs, accordion or cards
 *
 * @return array<string, mixed> empty when the block has nothing to show
 */
function fn_euph_pages_in_blocks_view(mixed $items, mixed $block, mixed $layout = 'stacked'): array
{
    return fn_euph_pages_in_blocks_container()->presenter()->fromTemplate($items, $block, $layout);
}
