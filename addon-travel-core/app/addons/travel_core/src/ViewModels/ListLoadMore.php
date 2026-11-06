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

    /** Request keys that never ride into the next page's link: the page itself and CS-Cart's AJAX and service keys. */
    private const array DROP = ['dispatch', 'page', 'is_ajax', 'result_ids', 'full_render', 'callback', 'security_hash', '_'];

    /**
     * The next page's internal URL ("categories.view?category_id=5&sort_by=price&page=2"),
     * keeping what the visitor chose (sorting, view, filters, page size);
     * fn_url() turns it into the store's (SEO) address.
     *
     * @param array<array-key, mixed> $request the page's request ($_REQUEST)
     */
    public static function nextUrl(string $dispatch, array $request, int $nextPage): string
    {
        $keep = [];
        foreach ($request as $key => $value) {
            if (!is_string($key) || in_array($key, self::DROP, true) || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
                continue;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $keep[$key] = (string) $value;
            }
        }
        $keep['page'] = (string) max(2, $nextPage);

        return $dispatch . '?' . http_build_query($keep, '', '&', PHP_QUERY_RFC3986);
    }
}
