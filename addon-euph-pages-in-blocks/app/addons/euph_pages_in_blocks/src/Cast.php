<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

/**
 * Narrows the untyped values CS-Cart hands over (block data, db_* results,
 * template arguments) to the types the add-on works with.
 */
final class Cast
{
    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);

            return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
        }
        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $default;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * CS-Cart's Y/N flags, plus the true/1 a checkbox or a numeric column
     * (pages.new_window) may give.
     */
    public static function flag(mixed $value): bool
    {
        return $value === true || $value === 'Y' || $value === 1 || $value === '1';
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * The arrays of a list (rows), in order; anything else is dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = self::map($row);
            }
        }

        return $rows;
    }

    /**
     * Positive unique ids from a list or a comma-separated string, in order.
     *
     * @return list<int>
     */
    public static function idList(mixed $value): array
    {
        if (is_int($value)) {
            $value = [$value];
        }
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::int($item);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
