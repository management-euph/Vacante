<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * "Load N more" under a list page (board 15): the page holds as many
 * products as CS-Cart is set to show; the button brings the next page in
 * under them, N = what is left, at most one page (29 hotels, 20 a page →
 * "Load 9 more"). The page links stay for search engines and for anyone
 * without JavaScript.
 */
final class ListLoadMore
{
    /**
     * @param mixed $search CS-Cart's $search for the list (page, items_per_page, total_items)
     *
     * @return array{from: int, to: int, total: int, next_page: int, count: int}|null null on the last page, or with no paging
     */
    public static function from(mixed $search): ?array
    {
        if (!is_array($search)) {
            return null;
        }
        $total = TypeCoerce::toInt($search['total_items'] ?? 0);
        $perPage = TypeCoerce::toInt($search['items_per_page'] ?? 0);
        $page = max(1, TypeCoerce::toInt($search['page'] ?? 1));
        if ($total <= 0 || $perPage <= 0) {
            return null;
        }
        $to = min($total, $page * $perPage);
        $left = $total - $to;
        if ($left <= 0) {
            return null;
        }

        return [
            'from' => min($to, ($page - 1) * $perPage + 1),
            'to' => $to,
            'total' => $total,
            'next_page' => $page + 1,
            'count' => min($perPage, $left),
        ];
    }
}
