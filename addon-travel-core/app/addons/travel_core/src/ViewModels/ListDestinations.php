<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The destination shortcuts above the hotel list (board 15, decision 7): a
 * category's subcategories (a country's cities, as the suppliers' syncs
 * create them) as chips with their hotel count, the busiest first; the
 * first VISIBLE show, the rest open with "All destinations (N)". Empty
 * destinations are left out.
 */
final class ListDestinations
{
    public const int VISIBLE = 8;

    /**
     * @param mixed $subcategories CS-Cart's $subcategories (category_id, category, product_count)
     *
     * @return array{visible: list<array{category_id: int, name: string, count: int}>, more: list<array{category_id: int, name: string, count: int}>, total: int}|null null when there is nothing to show
     */
    public static function from(mixed $subcategories): ?array
    {
        if (!is_array($subcategories)) {
            return null;
        }
        $all = [];
        foreach ($subcategories as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = TypeCoerce::toInt($row['category_id'] ?? 0);
            $name = trim(TypeCoerce::toString($row['category'] ?? ''));
            $count = TypeCoerce::toInt($row['product_count'] ?? 0);
            if ($id > 0 && $name !== '' && $count > 0) {
                $all[] = ['category_id' => $id, 'name' => $name, 'count' => $count];
            }
        }
        if ($all === []) {
            return null;
        }
        usort($all, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return [
            'visible' => array_slice($all, 0, self::VISIBLE),
            'more' => array_slice($all, self::VISIBLE),
            'total' => count($all),
        ];
    }
}
