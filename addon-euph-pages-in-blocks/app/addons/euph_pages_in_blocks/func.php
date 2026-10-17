<?php

declare(strict_types=1);

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Pages in blocks: all the add-on's PHP. CS-Cart loads this file for the
 * active add-on; the block manager finds the "Page content" block type in
 * schemas/block_manager/blocks.post.php, whose templates call:
 *
 *  - fn_euph_pages_in_blocks_get_pages()  the block's items: the pages picked
 *                                         on its Content tab;
 *  - fn_euph_pages_in_blocks_view()       what a template prints;
 *  - fn_euph_pages_in_blocks_uninstall()  addon.xml's uninstall function.
 *
 * No PHP hook is registered: nothing of the store's own page lists, menus
 * or page views goes through this add-on.
 */

/**
 * Items of the Page content block. The block manager calls it with the
 * filling's params and the block's content (item_ids) and gets the pages
 * back as fn_get_pages() gives its own: [pages, params].
 *
 * Each page is read as for its own view (fn_get_page_data()): the same
 * status, user group, availability period and storefront rules apply, and
 * the Form builder and Polls add-ons attach the form and the poll. A page
 * the visitor may not see is left out, the others keep the order they were
 * picked in. A block shows at most 50 pages.
 *
 * @param mixed $params the filling's params, with item_ids
 *
 * @return array{0: list<array<string, mixed>>, 1: array<array-key, mixed>}
 */
function fn_euph_pages_in_blocks_get_pages(mixed $params = []): array
{
    $params = is_array($params) ? $params : [];
    $lang_code = fn_euph_pages_in_blocks_string($params['lang_code'] ?? '');
    if ($lang_code === '') {
        $lang_code = defined('CART_LANGUAGE') ? fn_euph_pages_in_blocks_string(constant('CART_LANGUAGE'), 'en') : 'en';
    }

    $pages = [];
    foreach (array_slice(fn_euph_pages_in_blocks_ids($params['item_ids'] ?? ''), 0, 50) as $page_id) {
        $page = fn_euph_pages_in_blocks_map(fn_get_page_data($page_id, $lang_code));
        // Disabled pages are left out in any area: the storefront query
        // leaves them out already, the admin one does not.
        if (fn_euph_pages_in_blocks_int($page['page_id'] ?? 0) === $page_id
            && fn_euph_pages_in_blocks_string($page['status'] ?? '') !== 'D'
        ) {
            $pages[] = $page;
        }
    }

    return [$pages, $params];
}

/**
 * The view of a Page content block for its template:
 * {$pib = fn_euph_pages_in_blocks_view($items, $block, "tabs")}.
 *
 * @return array<string, mixed> empty when the block has nothing to show
 */
function fn_euph_pages_in_blocks_view(mixed $items, mixed $block, mixed $layout = 'stacked'): array
{
    /** @var array<string, int> $ids the block DOM ids handed out in this request */
    static $ids = [];

    return fn_euph_pages_in_blocks_present($items, $block, $layout, $ids);
}

/**
 * What a Page content template draws: the block's settings and one item per
 * page, with everything the markup needs worked out here, so the templates
 * only print.
 *
 * Each item gets a DOM id built from the block's placement and the page
 * (euph-pib-<snapping id>-p<page id>): stable across visits, so a link to
 * #euph-pib-12-p5 opens that page's tab or section. The same placement
 * drawn twice in a page gets a suffix.
 *
 * @param array<string, int> $ids the block DOM ids handed out so far in the page
 *
 * @return array<string, mixed> empty when there is nothing to show
 */
function fn_euph_pages_in_blocks_present(mixed $items, mixed $block, mixed $layout, array &$ids): array
{
    $block = fn_euph_pages_in_blocks_map($block);
    $layout = fn_euph_pages_in_blocks_string($layout);
    if (!in_array($layout, ['stacked', 'tabs', 'accordion', 'cards'], true)) {
        $layout = 'stacked';
    }
    $settings = fn_euph_pages_in_blocks_settings(fn_euph_pages_in_blocks_map($block['properties'] ?? null));
    // Cards are teasers: they always show an excerpt.
    $excerpts = $layout === 'cards' || ($layout === 'stacked' && $settings['content'] === 'excerpt');

    $views = [];
    foreach (is_array($items) ? $items : [] as $page) {
        $view = fn_euph_pages_in_blocks_item(fn_euph_pages_in_blocks_map($page), $excerpts ? $settings['excerpt_length'] : 0);
        if ($view !== null && !isset($views[$view['page_id']])) {
            $views[$view['page_id']] = $view;
        }
    }
    if ($views === []) {
        return [];
    }

    $snapping_id = fn_euph_pages_in_blocks_int($block['snapping_id'] ?? 0);
    $base = $snapping_id > 0 ? 'euph-pib-' . $snapping_id : 'euph-pib-b' . max(0, fn_euph_pages_in_blocks_int($block['block_id'] ?? 0));
    $ids[$base] = ($ids[$base] ?? 0) + 1;
    $block_id = $ids[$base] === 1 ? $base : $base . '-' . $ids[$base];

    $items_view = [];
    foreach (array_values($views) as $position => $view) {
        $view['id'] = $block_id . '-p' . $view['page_id'];
        $view['dialog_id'] = $view['kind'] === 'popup' ? $view['id'] . '-dialog' : '';
        $view['selected'] = $layout === 'tabs' && $position === 0;
        $view['open'] = $layout === 'accordion' && $settings['open_first'] && $position === 0;
        $items_view[] = $view;
    }

    return [
        'id' => $block_id,
        'layout' => $layout,
        'show_titles' => $settings['show_titles'],
        'separators' => $settings['separators'],
        'excerpts' => $excerpts,
        'one_open' => $settings['one_open'],
        'columns' => $settings['columns'],
        'items' => $items_view,
    ];
}

/**
 * The template settings of a block (its properties), typed. A setting the
 * block has no value for (it was saved with another template) takes its
 * default, a value out of range the default or the nearest limit. The
 * defaults are those of schemas/block_manager/blocks.post.php.
 *
 * @param array<string, mixed> $properties
 *
 * @return array{show_titles: bool, separators: bool, content: string, excerpt_length: int, open_first: bool, one_open: bool, columns: int}
 */
function fn_euph_pages_in_blocks_settings(array $properties): array
{
    $flag = static fn (string $key, bool $default): bool => array_key_exists($key, $properties) ? fn_euph_pages_in_blocks_flag($properties[$key]) : $default;
    $length = fn_euph_pages_in_blocks_int($properties['euph_pib_excerpt_length'] ?? null);
    $columns = fn_euph_pages_in_blocks_int($properties['euph_pib_columns'] ?? null);

    return [
        'show_titles' => $flag('euph_pib_show_titles', true),
        'separators' => $flag('euph_pib_separators', true),
        'content' => fn_euph_pages_in_blocks_string($properties['euph_pib_content'] ?? '') === 'excerpt' ? 'excerpt' : 'full',
        'excerpt_length' => $length <= 0 ? 200 : max(40, min(2000, $length)),
        'open_first' => $flag('euph_pib_open_first', false),
        'one_open' => $flag('euph_pib_one_open', true),
        'columns' => in_array($columns, [2, 3, 4], true) ? $columns : 3,
    ];
}

/**
 * One page of a block as the templates draw it: its content, a link (a link
 * page) or a popup (a page set to show in a popup). Null when it cannot be
 * shown.
 *
 * @param array<string, mixed> $page a row of fn_get_page_data()
 * @param int $excerpt_length the length of its excerpt; 0: none
 *
 * @return array{page_id: int, title: string, type: string, kind: string, url: string, new_window: bool, excerpt: string, extra_hook: bool, page: array<string, mixed>}|null
 */
function fn_euph_pages_in_blocks_item(array $page, int $excerpt_length): ?array
{
    $page_id = fn_euph_pages_in_blocks_int($page['page_id'] ?? 0);
    $title = trim(fn_euph_pages_in_blocks_string($page['page'] ?? ''));
    // A page with no name in this language is not ready to be shown.
    if ($page_id <= 0 || $title === '') {
        return null;
    }

    // Another add-on's page type (a blog post…) shows its text, as a text page does.
    $types = ['T' => 'text', 'L' => 'link', 'F' => 'form', 'P' => 'poll'];
    $type = $types[fn_euph_pages_in_blocks_string($page['page_type'] ?? '')] ?? 'text';
    if ($type === 'link') {
        $address = trim(fn_euph_pages_in_blocks_string($page['link'] ?? ''));
        // The address is the administrator's; a script address is never
        // printed into the page.
        if ($address === '' || preg_match('/^\s*(?:javascript|vbscript|data):/i', $address) === 1) {
            return null;
        }
        $kind = 'link';
    } else {
        $address = 'pages.view?page_id=' . $page_id;
        $kind = fn_euph_pages_in_blocks_flag($page['show_in_popup'] ?? 'N') ? 'popup' : 'content';
    }

    return [
        'page_id' => $page_id,
        'title' => $title,
        'type' => $type,
        'kind' => $kind,
        'url' => fn_euph_pages_in_blocks_string(fn_url($address)),
        // The dialog is never a new window.
        'new_window' => $kind !== 'popup' && fn_euph_pages_in_blocks_flag($page['new_window'] ?? 0),
        'excerpt' => $excerpt_length > 0 ? fn_euph_pages_in_blocks_excerpt(fn_euph_pages_in_blocks_string($page['description'] ?? ''), $excerpt_length) : '',
        // Forms and polls also draw through pages:page_extra, as on their
        // page; a text page does not, so what add-ons draw under a page's
        // text on its own view (comments, share buttons) stays out.
        'extra_hook' => $type === 'form' || $type === 'poll',
        'page' => $page,
    ];
}

/**
 * The plain-text start of a page's HTML, for the cards and the "Read more"
 * view: cut at a word, with an ellipsis.
 *
 * @param int $length the most characters of text, the ellipsis aside; 0 or less keeps it whole
 */
function fn_euph_pages_in_blocks_excerpt(string $html, int $length): string
{
    $text = fn_euph_pages_in_blocks_text($html);
    if ($length <= 0 || mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }

    $cut = mb_substr($text, 0, $length + 1, 'UTF-8');
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    // At the last word that ends early enough; a text with no space there
    // (one long word) is cut at the length.
    $cut = $space !== false && $space >= intdiv($length * 3, 5)
        ? mb_substr($cut, 0, $space, 'UTF-8')
        : mb_substr($cut, 0, $length, 'UTF-8');

    return (preg_replace('/[\s.,;:!?\x{2013}\x{2014}-]+$/u', '', $cut) ?? $cut) . '…';
}

/**
 * The text of an HTML fragment, on one line: no markup, no scripts, styles
 * or form fields, entities decoded.
 */
function fn_euph_pages_in_blocks_text(string $html): string
{
    // A pattern that fails (PCRE's backtracking limit on a huge page) leaves
    // the text as it was rather than empty.
    $html = mb_scrub($html, 'UTF-8');
    $html = preg_replace('#<(script|style|template|noscript|iframe|object|svg|select|textarea|button)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
    // Where a block of text ends, a space keeps the words apart.
    $html = preg_replace('#<(?:br|hr|/p|/div|/li|/h[1-6]|/tr|/td|/th|/dt|/dd|/blockquote|/section|/article|/header|/footer|/figcaption|/pre|/table|/ul|/ol)\b[^>]*>#i', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text) ?? $text);
}

/**
 * Uninstall: the Page content blocks leave every layout, with their
 * contents, names, placements and per-page statuses; without the block
 * type they would stay in the layouts. The pages are not touched; CS-Cart
 * removes the add-on's settings and texts itself.
 */
function fn_euph_pages_in_blocks_uninstall(): bool
{
    $block_ids = fn_euph_pages_in_blocks_ids(db_get_fields('SELECT block_id FROM ?:bm_blocks WHERE type = ?s', 'euph_page_content'));
    if ($block_ids === []) {
        return true;
    }
    $snapping_ids = fn_euph_pages_in_blocks_ids(db_get_fields('SELECT snapping_id FROM ?:bm_snapping WHERE block_id IN (?n)', $block_ids));
    if ($snapping_ids !== [] && fn_euph_pages_in_blocks_table_exists('bm_block_statuses')) {
        db_query('DELETE FROM ?:bm_block_statuses WHERE snapping_id IN (?n)', $snapping_ids);
    }
    db_query('DELETE FROM ?:bm_snapping WHERE block_id IN (?n)', $block_ids);
    db_query('DELETE FROM ?:bm_blocks_content WHERE block_id IN (?n)', $block_ids);
    db_query('DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)', $block_ids);
    db_query('DELETE FROM ?:bm_blocks WHERE block_id IN (?n)', $block_ids);

    return true;
}

/** Whether a table (named without the prefix) is in the database. */
function fn_euph_pages_in_blocks_table_exists(string $table): bool
{
    $name = fn_euph_pages_in_blocks_string(Registry::get('config.table_prefix'), 'cscart_') . $table;

    return fn_euph_pages_in_blocks_string(db_get_field('SHOW TABLES LIKE ?s', addcslashes($name, '_%\\'))) !== '';
}

/*
 * The untyped values CS-Cart hands over (block data, db_* results,
 * template arguments), narrowed.
 */

function fn_euph_pages_in_blocks_int(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value)) {
        return preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) trim($value) : $default;
    }
    if (is_float($value) && is_finite($value)) {
        return (int) $value;
    }

    return is_bool($value) ? (int) $value : $default;
}

function fn_euph_pages_in_blocks_string(mixed $value, string $default = ''): string
{
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value) || $value instanceof \Stringable) {
        return (string) $value;
    }

    return $default;
}

/** CS-Cart's Y/N flags, plus the true/1 of a checkbox or a numeric column (pages.new_window). */
function fn_euph_pages_in_blocks_flag(mixed $value): bool
{
    return $value === true || $value === 'Y' || $value === 1 || $value === '1';
}

/**
 * @return array<string, mixed> an array with string keys; anything else gives []
 */
function fn_euph_pages_in_blocks_map(mixed $value): array
{
    $map = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
        $map[(string) $key] = $item;
    }

    return $map;
}

/**
 * Positive unique ids from a list or a comma-separated string, in order.
 *
 * @return list<int>
 */
function fn_euph_pages_in_blocks_ids(mixed $value): array
{
    if (is_int($value)) {
        $value = [$value];
    } elseif (is_string($value)) {
        $value = trim($value) === '' ? [] : explode(',', $value);
    }
    $ids = [];
    foreach (is_array($value) ? $value : [] as $item) {
        $id = fn_euph_pages_in_blocks_int($item);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}
