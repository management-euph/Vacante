<?php

declare(strict_types=1);

/**
 * Stubs of the CS-Cart functions and class func.php calls, each defined
 * only if missing. They answer from Support\CsCart.
 */

namespace Tygh {
    use Tygh\Addons\EuphPagesInBlocks\Tests\Support\CsCart;

    if (!class_exists(Registry::class)) {
        final class Registry
        {
            public static function get(string $key): mixed
            {
                return CsCart::$registry[$key] ?? null;
            }
        }
    }
}

namespace {
    use Tygh\Addons\EuphPagesInBlocks\Tests\Support\CsCart;
    use Tygh\Addons\EuphPagesInBlocks\Tests\Support\LanguagePack;

    if (!function_exists('fn_url')) {
        function fn_url(string $url = ''): string
        {
            return CsCart::url($url);
        }
    }

    if (!function_exists('fn_get_page_data')) {
        function fn_get_page_data(int $page_id, string $lang_code = 'en'): mixed
        {
            CsCart::$pageCalls[] = [$page_id, $lang_code];

            return CsCart::$pages[$page_id] ?? false;
        }
    }

    if (!function_exists('db_get_field')) {
        function db_get_field(string $query, mixed ...$params): mixed
        {
            CsCart::$queries[] = [$query, array_values($params)];
            if ($query === 'SHOW TABLES LIKE ?s' && is_string($params[0] ?? null)) {
                $name = str_replace(['\\_', '\\%', '\\\\'], ['_', '%', '\\'], $params[0]);

                return in_array($name, CsCart::$tables, true) ? $name : '';
            }

            return '';
        }
    }

    if (!function_exists('db_get_fields')) {
        /**
         * @return list<mixed>
         */
        function db_get_fields(string $query, mixed ...$params): array
        {
            CsCart::$queries[] = [$query, array_values($params)];

            return CsCart::$fields[$query] ?? [];
        }
    }

    if (!function_exists('db_query')) {
        function db_query(string $query, mixed ...$params): int
        {
            CsCart::$queries[] = [$query, array_values($params)];

            return 1;
        }
    }

    if (!function_exists('__')) {
        /**
         * @param array<string, mixed> $params
         */
        function __(string $name, array $params = []): string
        {
            return LanguagePack::english()->translate($name, $params);
        }
    }
}
