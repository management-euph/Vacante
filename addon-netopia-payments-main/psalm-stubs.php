<?php

/**
 * CS-Cart stubs for Psalm static analysis.
 */

namespace Tygh {
    class Registry
    {
        /**
         * @param string $key
         * @return mixed
         */
        public static function get(string $key): mixed
        {
            return null;
        }
    }
}

namespace {
    /**
     * @return array<string, mixed>|false
     */
    function db_get_row(string $query, mixed ...$params): array|false
    {
        return [];
    }

    /**
     * @return string|false
     */
    function db_get_field(string $query, mixed ...$params): string|false
    {
        return '';
    }

    function db_query(string $query, mixed ...$params): mixed
    {
        return null;
    }
}
