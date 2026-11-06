<?php

/**
 * The category-list half of export-theme.sh (run inside the app container):
 * what the hotel list designs (board 15, docs/hotel-list-plan.md) are wired
 * against, from this store.
 *
 *   - list-views.json: the product list views CS-Cart finds
 *     (fn_get_products_views: theme and add-on templates, active or not);
 *   - settings-appearance.json: the Appearance settings (views, products per
 *     page, columns, sortings);
 *   - core-functions.txt: the source of the few core functions the list
 *     template leans on (views, pagination, sorting);
 *   - categories.json: the categories holding the most products, with their
 *     own view settings; filters.json and features.json: the store's product
 *     filters and features;
 *   - category-<id>.html (and -layout-<view>.html): the busiest category as a
 *     logged-out visitor gets it, once per active view.
 *
 * Licensed CS-Cart code lands in var/ with the rest of the export; never commit it.
 *
 * Usage: php export-list.php <store url> <out dir> [category id]
 */

declare(strict_types=1);

[, $base, $out, $categoryArg] = $argv + array_fill(0, 4, '');

function lsay(string $line): void
{
    fwrite(STDERR, "[export-list] $line\n");
}

function lsave(string $out, string $name, string $body): void
{
    file_put_contents($out . '/' . $name, $body);
    lsay(sprintf('%s (%d bytes)', $name, strlen($body)));
}

/** @param mixed $value */
function ljson(mixed $value): string
{
    return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
}

function lfetch(string $url): string
{
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 120, 'ignore_errors' => true],
    ]));
    if ($body === false) {
        lsay("could not fetch $url");

        return '';
    }

    return $body;
}

if ($base === '' || $out === '' || !is_dir($out)) {
    lsay('usage: php export-list.php <store url> <out dir> [category id]');
    exit(1);
}

$docroot = getenv('DOCROOT') ?: '/var/www/html';
chdir($docroot);
const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
// init.php reads these as a web request would (as install-addons.php does).
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
require $docroot . '/init.php';

// What CS-Cart offers as product list views, and the store's Appearance settings.
lsave($out, 'list-views.json', ljson(function_exists('fn_get_products_views') ? fn_get_products_views(false, false) : 'fn_get_products_views() not found'));
lsave($out, 'settings-appearance.json', ljson(\Tygh\Registry::get('settings.Appearance')));

// The source of the core functions the list template depends on.
$source = '';
foreach (['fn_get_products_views', 'fn_get_products_sorting', 'fn_get_products_sorting_orders', 'fn_get_default_products_sorting', 'fn_generate_pagination'] as $fn) {
    if (!function_exists($fn)) {
        $source .= "// {$fn}: not defined\n\n";
        continue;
    }
    $rf = new \ReflectionFunction($fn);
    $file = (string) $rf->getFileName();
    $lines = file($file) ?: [];
    $source .= '// ' . str_replace($docroot . '/', '', $file) . ':' . $rf->getStartLine() . "\n"
        . implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1)) . "\n";
}
lsave($out, 'core-functions.txt', $source);

// The busiest categories (active products, any link), with their own view settings.
/** @return list<array<string, mixed>> the rows, or [] with the error said (a column this version lacks) */
function lrows(string $what, callable $query): array
{
    try {
        $rows = $query();

        return is_array($rows) ? array_values($rows) : [];
    } catch (\Throwable $e) {
        lsay("$what: " . $e->getMessage());

        return [];
    }
}

$lang = (string) (\Tygh\Registry::get('settings.Appearance.frontend_default_language') ?: 'ro');
$categories = lrows('categories', static fn () => db_get_array(
    'SELECT c.*, d.category, COUNT(DISTINCT pc.product_id) AS products'
    . ' FROM ?:categories c'
    . ' LEFT JOIN ?:category_descriptions d ON d.category_id = c.category_id AND d.lang_code = ?s'
    . ' LEFT JOIN ?:products_categories pc ON pc.category_id = c.category_id'
    . ' LEFT JOIN ?:products p ON p.product_id = pc.product_id AND p.status = ?s'
    . ' WHERE p.product_id IS NOT NULL'
    . ' GROUP BY c.category_id ORDER BY products DESC LIMIT 25',
    $lang,
    'A',
));
lsave($out, 'categories.json', ljson($categories));

$filters = lrows('filters', static fn () => db_get_array(
    'SELECT f.*, fd.filter FROM ?:product_filters f'
    . ' LEFT JOIN ?:product_filter_descriptions fd ON fd.filter_id = f.filter_id AND fd.lang_code = ?s ORDER BY f.position',
    $lang,
));
lsave($out, 'filters.json', ljson($filters));

$features = lrows('features', static fn () => db_get_array(
    'SELECT f.*, d.description,'
    . ' (SELECT COUNT(*) FROM ?:product_features_values v WHERE v.feature_id = f.feature_id AND v.lang_code = ?s) AS product_values'
    . ' FROM ?:product_features f LEFT JOIN ?:product_features_descriptions d ON d.feature_id = f.feature_id AND d.lang_code = ?s'
    . ' ORDER BY f.position',
    $lang,
    $lang,
));
lsave($out, 'features.json', ljson($features));

// The busiest category as a visitor gets it, once per active view.
$categoryId = ctype_digit((string) $categoryArg) ? (int) $categoryArg : (int) ($categories[0]['category_id'] ?? 0);
if ($categoryId <= 0) {
    lsay('no category with active products: no category page');
    exit(0);
}
$url = rtrim($base, '/') . '/index.php?dispatch=categories.view&category_id=' . $categoryId;
lsave($out, "category-{$categoryId}.html", lfetch($url));
$views = function_exists('fn_get_products_views') ? fn_get_products_views(false, true) : [];
foreach (array_keys(is_array($views) ? $views : []) as $view) {
    $view = (string) $view;
    if (preg_match('/^[a-z0-9_]+$/', $view) === 1) {
        lsave($out, "category-{$categoryId}-layout-{$view}.html", lfetch($url . '&layout=' . $view));
    }
}
lsave($out, "category-{$categoryId}-page-2.html", lfetch($url . '&page=2'));
