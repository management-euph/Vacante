<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

/**
 * What the CS-Cart stubs of stubs.php answer, and the calls they got.
 */
final class CsCart
{
    /** @var array<string, mixed> what Registry::get() answers */
    public static array $registry = ['config.table_prefix' => 'cscart_'];

    /** @var array<int, mixed> page id => what fn_get_page_data() returns (false when missing) */
    public static array $pages = [];

    /** @var list<array{int, string}> [page id, language] of every fn_get_page_data() call */
    public static array $pageCalls = [];

    /** @var list<string> the tables "SHOW TABLES LIKE" finds, by their real names */
    public static array $tables = [];

    /** @var array<string, list<mixed>> query => what db_get_fields() returns for it */
    public static array $fields = [];

    /** @var list<array{string, list<mixed>}> every db_* call: [query, parameters] */
    public static array $queries = [];

    public static function reset(): void
    {
        self::$registry = ['config.table_prefix' => 'cscart_'];
        self::$pages = [];
        self::$pageCalls = [];
        self::$tables = [];
        self::$fields = [];
        self::$queries = [];
    }

    /** What the fn_url() stub makes of a dispatch or an address. */
    public static function url(string $url): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1 || str_starts_with($url, '/')) {
            return $url;
        }
        if (str_starts_with($url, 'index.php')) {
            return 'https://shop.example/' . $url;
        }

        return 'https://shop.example/index.php?dispatch=' . str_replace('?', '&', $url);
    }
}
