<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Helpers;

/**
 * Parses the order ids a bulk action arrives with.
 *
 * They come in two shapes: the orders list posts its checkboxes as
 * `order_ids[]` (an array), while the pre-check page, the "Retry failed" link
 * and the ZIP download pass them as one comma-separated string in the query
 * or a $.performPostRequest() form. Both, and a mix (an array element that is
 * itself a CSV), yield the same list.
 *
 * Only positive integers survive: an id is a digit string, so "1.5", "-3",
 * "0", "7abc" or an array smuggled into an element are dropped instead of
 * being cast into some other order's id. Duplicates are removed and the
 * first-seen order is kept, because the pre-check page lists the orders in
 * the order the admin saw them on the list.
 */
final class OrderIdList
{
    /** Ten digits: already past CS-Cart's mediumint order ids, and still an int on 32-bit. */
    private const ID_PATTERN = '/^\d{1,10}$/';

    private function __construct()
    {
    }

    /**
     * @return list<int>
     */
    public static function parse(mixed $raw): array
    {
        $pieces = [];
        foreach (is_array($raw) ? $raw : [$raw] as $element) {
            if (is_int($element)) {
                $pieces[] = (string) $element;
                continue;
            }
            if (!is_string($element)) {
                continue;
            }
            foreach (explode(',', $element) as $piece) {
                $pieces[] = trim($piece);
            }
        }

        $ids = [];
        $seen = [];
        foreach ($pieces as $piece) {
            if (preg_match(self::ID_PATTERN, $piece) !== 1) {
                continue;
            }
            $id = (int) $piece;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    public static function toCsv(array $ids): string
    {
        return implode(',', $ids);
    }
}
