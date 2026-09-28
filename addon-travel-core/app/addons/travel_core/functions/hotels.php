<?php

declare(strict_types=1);

/**
 * Travel Core - Hotel Utility Functions
 *
 * Shared hotel-related utility functions used by all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Canonical read of the booking-form appearance colors.
 *
 * Source of truth is CS-Cart's ?:storage_data KV (survives cache clears and
 * is shared by both areas), because Settings::updateValue() proved unreliable
 * for these addon-section settings on live installs: values "saved" via the
 * Settings API landed only in the per-area registry cache — the admin form
 * kept showing them while the storefront (a separate cache) and any
 * cache-cleared request fell back to the defaults. Registry values are read
 * first as legacy fallback for installs where the API did write; storage
 * wins whenever it has a value.
 *
 * @return array<string, string> color_* => hex ('' = theme default)
 */
function fn_travel_core_get_appearance_colors(): array
{
    $out = [];

    $tc = \Tygh\Registry::get('addons.travel_core');
    if (is_array($tc)) {
        foreach ($tc as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'color_') && is_scalar($v)) {
                $out[$k] = (string) $v;
            }
        }
    }

    if (function_exists('fn_get_storage_data')) {
        $raw = fn_get_storage_data('travel_core_appearance');
        if (is_string($raw) && $raw !== '') {
            $stored = json_decode($raw, true);
            if (is_array($stored)) {
                foreach ($stored as $k => $v) {
                    if (is_string($k) && str_starts_with($k, 'color_') && is_scalar($v)) {
                        $out[$k] = (string) $v;
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * Persist the appearance colors to ?:storage_data (see the reader above for
 * why storage is the source of truth) and refresh the in-request Registry so
 * hooks later in the same request already see the new values.
 *
 * @param array<string, string> $colors color_* => hex ('' = theme default)
 */
function fn_travel_core_save_appearance_colors(array $colors): void
{
    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data('travel_core_appearance', (string) json_encode($colors));
    }

    $existing = \Tygh\Registry::get('addons.travel_core');
    \Tygh\Registry::set('addons.travel_core', array_merge(is_array($existing) ? $existing : [], $colors));
}

/**
 * Render the React booking engine mount-point HTML entirely in PHP.
 *
 * This replaces $view->fetch('booking_engine.tpl') and Smarty {include} for
 * search result pages. The Smarty template (booking_engine.tpl) is still used
 * for product detail pages and homepage blocks.
 *
 * IMPORTANT: If you add data-attributes, colors, or translations here,
 * also update design/themes/responsive/templates/addons/travel_core/blocks/booking_engine.tpl
 * to keep both implementations in sync.
 *
 * The booking engine is a stateless "empty shell" — just
 * a <div> with data-* attributes, a skeleton loader, and two <script> tags.
 * Building it in PHP avoids Smarty 5's scope chain traversal that causes
 * 256MB OOM (Data.php:265) when parent scope contains large result arrays.
 *
 * The Smarty template (booking_engine.tpl) is still used for product detail
 * pages and the homepage block where scope chain is not an issue.
 *
 * @param array<string, mixed> $params {
 * @type string $provider        'novoton' or 'sphinx'
 * @type string $search_dispatch 'novoton_booking.search' or 'sphinx_booking.search'
 * @type string $mode            'search' (default) or 'product'
 * @type array $search_params   Search params for pre-filling (check_in, check_out, etc.)
 * @type string $calendar_prices_json  Optional JSON with per-day prices
 * @type string $calendar_prices_currency  Currency code for calendar prices
 *              }
 * @return string Complete HTML string (safe for {$var nofilter} output)
 */
function fn_travel_core_render_booking_engine(array $params = []): string
{
    $vh = \Tygh\Addons\TravelCore\Helpers\ValidationHelpers::class;
    $provider = $vh::toString($params['provider'] ?? '');
    $searchDispatch = $vh::toString($params['search_dispatch'] ?? '');
    $mode = $vh::toString($params['mode'] ?? 'search');
    /** @var array<string, mixed> $sp */
    $sp = is_array($params['search_params'] ?? null) ? $params['search_params'] : [];
    $calPricesJson = $vh::toString($params['calendar_prices_json'] ?? '');
    $calPricesCurr = $vh::toString($params['calendar_prices_currency'] ?? '');

    // Colors from the canonical appearance store (bypasses Smarty completely)
    $tc = fn_travel_core_get_appearance_colors();
    $colors = json_encode([
        'primary' => $vh::toString($tc['color_primary'] ?? ''),
        'accent' => $vh::toString($tc['color_accent'] ?? ''),
        'text' => $vh::toString($tc['color_text'] ?? ''),
        'textLight' => $vh::toString($tc['color_text_light'] ?? ''),
        'bg' => $vh::toString($tc['color_bg'] ?? ''),
        'border' => $vh::toString($tc['color_border'] ?? ''),
        'btnBg' => $vh::toString($tc['color_search_btn_bg'] ?? ''),
        'btnHover' => $vh::toString($tc['color_search_btn_hover'] ?? ''),
        'btnText' => $vh::toString($tc['color_search_btn_text'] ?? ''),
        'calCheapest' => $vh::toString($tc['color_cal_cheapest'] ?? ''),
        'calPrice' => $vh::toString($tc['color_cal_price'] ?? ''),
        'danger' => $vh::toString($tc['color_danger'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);

    // Translations (50+ keys — built via PHP __() instead of Smarty {__()})
    $translationKeys = [
        'availability', 'checkInDate' => 'check_in_date', 'checkOutDate' => 'check_out_date',
        'checkIn' => 'check_in', 'checkOut' => 'check_out',
        'selectDatesMessage' => 'select_dates_message',
        'search', 'changeSearch' => 'change_search', 'applyChanges' => 'apply_changes',
        'adult', 'adults', 'child', 'children', 'rooms', 'room', 'done',
        'addRoom' => 'add_room', 'adultsLabel' => 'adults_label',
        'childrenLabel' => 'children_label',
        'nightsStay' => 'nights_stay', 'nightStay' => 'night_stay',
        'night', 'nights',
        'childrenAges' => 'childrens_ages', 'childAge' => 'child_age',
        'childNAge' => 'child_n_age',
        'selectAge' => 'select_age', 'yearsOld' => 'years_old', 'yearOld' => 'year_old',
        'selected', 'selectedSingular' => 'selected_singular',
        'selectCheckOut' => 'select_check_out',
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
        'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
        'remove',
        'pleaseEnterDates' => 'please_enter_dates',
        'selectCheckIn' => 'select_check_in',
        'selectMissingAges' => 'select_missing_ages',
        'selectAgeForOneChild' => 'select_age_for_one_child',
        'selectAgeForChildren' => 'select_age_for_children',
    ];

    $translations = [];
    foreach ($translationKeys as $jsKey => $langSuffix) {
        if (is_int($jsKey)) {
            $jsKey = $langSuffix;
        }
        $translations[$jsKey] = __('travel_core.' . $langSuffix);
    }
    // Special key with default fallback
    $calFooter = __('travel_core.calendar_price_footer');
    if (empty($calFooter) || $calFooter === 'travel_core.calendar_price_footer') {
        $calFooter = 'Approximate prices in %s for a 1-night stay';
    }
    $translations['calendarPriceFooter'] = $calFooter;

    $translationsJson = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Resolve IDs
    $hotelId = htmlspecialchars($vh::toString($sp['hotel_id'] ?? ''), ENT_QUOTES);
    $productId = htmlspecialchars($vh::toString($sp['product_id'] ?? ''), ENT_QUOTES);
    $lang = $vh::toString(defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en');
    $cacheVer = $vh::toString(defined('TRAVEL_CACHE_VER') ? TRAVEL_CACHE_VER : '1');
    $baseUrl = $vh::toString(\Tygh\Registry::get('config.current_location') ?: '');
    $maxRooms = \Tygh\Addons\TravelCore\Services\ProviderRoomLimit::maxRooms($provider);

    // Build data attributes for search mode
    $searchAttrs = '';
    if ($mode === 'search' && !empty($sp)) {
        $searchAttrs = sprintf(
            ' data-check-in="%s" data-check-out="%s" data-adults="%s" data-children="%s"'
            . ' data-children-ages="%s" data-rooms="%s" data-rooms-data=\'%s\'',
            htmlspecialchars($vh::toString($sp['check_in'] ?? ''), ENT_QUOTES),
            htmlspecialchars($vh::toString($sp['check_out'] ?? ''), ENT_QUOTES),
            $vh::toInt($sp['adults'] ?? 2),
            $vh::toInt($sp['children_count'] ?? $sp['children'] ?? 0),
            htmlspecialchars($vh::toString($sp['children_ages'] ?? $sp['children_ages_str'] ?? ''), ENT_QUOTES),
            $vh::toInt($sp['num_rooms'] ?? $sp['rooms'] ?? 1),
            htmlspecialchars($vh::toString($sp['rooms_data_json'] ?? '[]'), ENT_QUOTES),
        );
    }

    // Calendar prices
    $calAttrs = '';
    if (!empty($calPricesJson) && $calPricesJson !== '{}') {
        $calAttrs = sprintf(
            ' data-calendar-prices=\'%s\' data-calendar-prices-currency="%s"',
            $calPricesJson,
            htmlspecialchars($calPricesCurr, ENT_QUOTES),
        );
    }

    // Defense-in-depth: escape JSON strings for safe HTML attribute embedding.
    // Prevents attribute injection if admin-controlled values ever contain quotes.
    $colorsAttr = htmlspecialchars((string) $colors, ENT_QUOTES, 'UTF-8');
    $translationsAttr = htmlspecialchars((string) $translationsJson, ENT_QUOTES, 'UTF-8');

    return <<<HTML
        <div id="travel-booking-root"
             data-travel-booking
             data-colors='{$colorsAttr}'
             data-search-dispatch="{$searchDispatch}"
             data-provider="{$provider}"
             data-hotel-id="{$hotelId}"
             data-product-id="{$productId}"
             data-debug="false"
             data-mode="{$mode}"
             data-max-rooms="{$maxRooms}"
             data-lang="{$lang}"
             {$searchAttrs}
             {$calAttrs}
             data-translations='{$translationsAttr}'>
            <div class="travel-loading-state">
                <div class="nvt-skeleton-row">
                    <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
                    <div class="nvt-skeleton-field"></div>
                    <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
                </div>
            </div>
        </div>
        <script src="{$baseUrl}/js/addons/travel_core/react-vendor.js?v={$cacheVer}" defer></script>
        <script src="{$baseUrl}/js/addons/travel_core/react19-bundle.js?v={$cacheVer}" defer></script>
        HTML;
}

/**
 * Get or create a nested CS-Cart category tree from a path string.
 *
 * Example: "Hotels/Greece/Crete" creates/reuses three nested categories.
 * Idempotent — reuses existing categories by name match at each level.
 *
 * @param string $path Forward-slash-delimited category path
 * @return int Leaf category_id, or 0 on failure
 */
function fn_travel_core_get_or_create_category(string $path): int
{
    $parts = array_filter(array_map('trim', explode('/', $path)));
    if (empty($parts)) {
        return 0;
    }

    $parent_id = 0;

    foreach ($parts as $part) {
        $category_id = TypeCoerce::toInt(db_get_field(
            'SELECT c.category_id FROM ?:categories c
             JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
             WHERE c.parent_id = ?i AND cd.category = ?s
             LIMIT 1',
            CART_LANGUAGE,
            $parent_id,
            $part,
        ));

        if ($category_id > 0) {
            $parent_id = $category_id;
            continue;
        }

        // Inherit company_id from parent category (required in frontend/cron context
        // where Registry::get('runtime.company_id') may not be set)
        $company_id = ($parent_id > 0)
            ? TypeCoerce::toInt(db_get_field('SELECT company_id FROM ?:categories WHERE category_id = ?i', $parent_id))
            : 0;

        // Create new category
        $category_data = [
            'category' => $part,
            'parent_id' => $parent_id,
            'company_id' => $company_id,
            'status' => 'A',
        ];

        $category_id = TypeCoerce::toInt(fn_update_category($category_data, 0, CART_LANGUAGE));

        if ($category_id <= 0) {
            fn_log_event('general', 'runtime', [
                'message' => "travel_core: fn_update_category() returned 0 for part='{$part}', parent_id={$parent_id}, company_id={$company_id}, path='{$path}'",
            ]);
            return 0;
        }

        // Ensure descriptions exist for all active languages
        $languages = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
        foreach ($languages as $lang_code) {
            if ($lang_code === CART_LANGUAGE) {
                continue; // Already created by fn_update_category
            }
            db_query(
                'INSERT INTO ?:category_descriptions (category_id, lang_code, category)
                 VALUES (?i, ?s, ?s)
                 ON DUPLICATE KEY UPDATE category = ?s',
                $category_id,
                $lang_code,
                $part,
                $part,
            );
        }

        $parent_id = $category_id;
    }

    return $parent_id;
}

/**
 * Get or create a single child category under a given parent.
 *
 * Uses parent_id directly — no path parsing needed.
 * Idempotent: reuses an existing category if name matches under parent.
 *
 * @param int $parent_id Parent category_id (must already exist)
 * @param string $name Category name (e.g. "Turkey")
 * @return int The child category_id, or 0 on failure
 */
function fn_travel_core_get_or_create_child_category(int $parent_id, string $name): int
{
    $name = trim($name);
    if ($name === '' || $parent_id <= 0) {
        return 0;
    }

    // Look for existing category by name under this parent
    $category_id = TypeCoerce::toInt(db_get_field(
        'SELECT c.category_id FROM ?:categories c
         JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
         WHERE c.parent_id = ?i AND cd.category = ?s
         LIMIT 1',
        CART_LANGUAGE,
        $parent_id,
        $name,
    ));

    if ($category_id > 0) {
        return $category_id;
    }

    // Inherit company_id from parent category (required in frontend/cron context
    // where Registry::get('runtime.company_id') may not be set)
    $company_id = TypeCoerce::toInt(db_get_field(
        'SELECT company_id FROM ?:categories WHERE category_id = ?i',
        $parent_id,
    ));

    // Create new category under parent
    $category_data = [
        'category' => $name,
        'parent_id' => $parent_id,
        'company_id' => $company_id,
        'status' => 'A',
    ];

    $category_id = TypeCoerce::toInt(fn_update_category($category_data, 0, CART_LANGUAGE));

    if ($category_id <= 0) {
        fn_log_event('general', 'runtime', [
            'message' => "travel_core: fn_update_category() returned 0 for name='{$name}', parent_id={$parent_id}, company_id={$company_id}",
        ]);
        return 0;
    }

    // Ensure descriptions exist for all active languages
    $languages = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
    foreach ($languages as $lang_code) {
        if ($lang_code === CART_LANGUAGE) {
            continue;
        }
        db_query(
            'INSERT INTO ?:category_descriptions (category_id, lang_code, category)
             VALUES (?i, ?s, ?s)
             ON DUPLICATE KEY UPDATE category = ?s',
            $category_id,
            $lang_code,
            $name,
            $name,
        );
    }

    return $category_id;
}

/**
 * Render an SEO template by replacing {{placeholder}} tokens with data values.
 *
 * Supports scalar values and arrays (arrays are joined as comma-separated,
 * limited to the first 3 items). Leftover unreplaced tokens are removed.
/**
 * Run a long-running admin task with progress bar and redirect.
 *
 * Wraps the common controller boilerplate: set_time_limit, progress init/finish,
 * notification, and redirect. Returns a CS-Cart controller redirect array.
 *
 * @param string $progressLabel Translation key for the progress bar
 * @param callable $task fn(): mixed — the work to execute
 * @param string $redirectUrl CS-Cart dispatch URL to redirect to after completion
 * @param callable|null $onResult fn(mixed $result): void — optional post-task notification
 * @return array{0: string, 1: string} CS-Cart [CONTROLLER_STATUS_REDIRECT, $url]
 */
function fn_travel_core_run_long_task(string $progressLabel, callable $task, string $redirectUrl, ?callable $onResult = null): array
{
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    fn_set_progress('init', $progressLabel);
    $result = $task();
    fn_set_progress('finish');

    if ($onResult !== null) {
        $onResult($result);
    }

    return [TypeCoerce::toString(CONTROLLER_STATUS_REDIRECT), $redirectUrl];
}

/**
 * Attach one or more public image URLs to a CS-Cart product via fn_update_product.
 *
 * CS-Cart's pipeline (fn_attach_image_pairs → fn_filter_uploaded_data → fn_get_url_data)
 * downloads, resizes, and stores each image through Storage::instance('images')->put().
 * No temp-file management or session shim required.
 *
 * Use this for all publicly accessible image URLs (no auth headers needed).
 * For auth-required downloads (e.g. Sphinx API-hosted), use fn_travel_core_attach_product_image.
 *
 * @param int          $productId    CS-Cart product ID
 * @param list<string> $urls         List of image URLs. Index 0 = main image when $firstIsMain is true.
 * @param bool         $firstIsMain  True to set URL[0] as the main (M) product image
 * @return int  Number of images actually attached (images_links rows added). 0 means
 *              nothing was stored — fn_update_product reports no error for unreachable
 *              URLs, so the row-count delta is the only reliable success signal.
 */
function fn_travel_core_attach_images_from_urls(int $productId, array $urls, bool $firstIsMain = true): int
{
    if ($productId <= 0 || empty($urls)) {
        return 0;
    }

    $before = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $productId,
    ));

    // Build the request payload in local arrays first, then assign each $_REQUEST key
    // once. Writing nested offsets directly onto $_REQUEST (mixed) is not statically
    // analysable; whole-array assignment to a single key is.
    $mainData = $mainType = $mainFile = [];
    $addData  = $addType  = $addFile  = [];

    $addIdx = 0;
    foreach ($urls as $i => $url) {
        if ($url === '') {
            continue;
        }

        if ($firstIsMain && $i === 0) {
            $mainData[0] = ['type' => 'M', 'object_id' => $productId, 'position' => 0];
            $mainType[0] = 'url';
            $mainFile[0] = $url;
        } else {
            $addData[$addIdx] = ['type' => 'A', 'object_id' => $productId, 'position' => $addIdx];
            $addType[$addIdx] = 'url';
            $addFile[$addIdx] = $url;
            $addIdx++;
        }
    }

    if ($mainData !== []) {
        $_REQUEST['product_main_image_data']          = $mainData;
        $_REQUEST['type_product_main_image_detailed'] = $mainType;
        $_REQUEST['file_product_main_image_detailed'] = $mainFile;
    }
    if ($addData !== []) {
        $_REQUEST['product_add_additional_image_data']          = $addData;
        $_REQUEST['type_product_add_additional_image_detailed'] = $addType;
        $_REQUEST['file_product_add_additional_image_detailed'] = $addFile;
    }

    fn_update_product([], $productId, CART_LANGUAGE);

    $after = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $productId,
    ));
    $attached = max(0, $after - $before);

    unset(
        $_REQUEST['product_main_image_data'],
        $_REQUEST['type_product_main_image_detailed'],
        $_REQUEST['file_product_main_image_detailed'],
        $_REQUEST['product_add_additional_image_data'],
        $_REQUEST['type_product_add_additional_image_detailed'],
        $_REQUEST['file_product_add_additional_image_detailed'],
    );

    // Only record the success breadcrumb if rows were actually added — fn_update_product
    // silently no-ops on unreachable URLs, so a path marker without a delta would lie.
    if ($attached > 0) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachPath = 'url/fn_update_product';
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::log(
            'image attach OK [url/fn_update_product]',
            ['product_id' => $productId, 'requested' => count($urls), 'attached' => $attached],
        );
    }

    return $attached;
}

/**
 * Attach a pre-downloaded image temp file to a CS-Cart product.
 *
 * Use this only when the image requires custom auth headers and must be downloaded
 * manually via cURL before attachment (e.g. Sphinx API-hosted images).
 * For public URLs, prefer fn_travel_core_attach_images_from_urls().
 *
 * @param int    $productId CS-Cart product ID
 * @param string $tempFile  Path to already-downloaded temp file (will be unlinked)
 * @param string $prefix    Filename prefix (e.g. 'sphinx')
 * @param bool   $isMain    True for main product image, false for additional
 * @return bool True on success
 */
function fn_travel_core_attach_product_image(int $productId, string $tempFile, string $prefix, bool $isMain = false): bool
{
    \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = '';

    if ($productId <= 0) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = 'attach: invalid product_id';
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        return false;
    }
    if (!file_exists($tempFile)) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = 'attach: temp file missing';
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        return false;
    }
    $tempSize = (int) filesize($tempFile);
    if ($tempSize < 1000) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = "attach: file too small ({$tempSize} bytes)";
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        unlink($tempFile);
        return false;
    }

    try {
        $imageInfo = getimagesize($tempFile);
    } catch (\Throwable) {
        $imageInfo = false;
    }

    if ($imageInfo === false) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = 'attach: getimagesize failed (not a valid image)';
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        unlink($tempFile);
        return false;
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $ext = $mimeToExt[$imageInfo['mime']] ?? 'jpg';
    $filename = "{$prefix}_hotel_{$productId}_" . time() . '_' . random_int(100, 999) . ".{$ext}";

    $existingPairs = TypeCoerce::toInt(db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $productId,
    ));

    // fn_update_image_pairs: canonical import/API attach path. Does not touch $_FILES or
    // session. The loop is driven by $pairsData (arg 3) — both $detailed and $pairsData
    // must share the same key (0) or the loop runs zero times and returns [].
    // fn_update_image() reads 'path' (absolute) + 'name' via Storage::put() — no is_uploaded_file().
    $detailed = [
        0 => [
            'name' => $filename,
            'path' => $tempFile,
            'size' => $tempSize,
            'type' => $imageInfo['mime'],
        ],
    ];
    $pairsData = [
        0 => [
            'pair_id'  => 0,
            'type'     => $isMain ? 'M' : 'A',
            'position' => $existingPairs,
        ],
    ];

    // $icons (arg 1) is empty — CS-Cart auto-generates the thumbnail from $detailed.
    $pairIds = fn_update_image_pairs([], $detailed, $pairsData, $productId, 'product');

    // fn_update_image_pairs copies the source via Storage::put() and leaves it in place,
    // so the temp file is still present here — remove it unconditionally.
    unlink($tempFile);

    if (empty($pairIds)) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = "attach: fn_update_image_pairs returned no pair (object_id={$productId}, size={$tempSize}, mime={$imageInfo['mime']})";
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        return false;
    }

    \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachPath = 'curl/fn_update_image_pairs';
    \Tygh\Addons\TravelCore\Helpers\DebugLogger::log(
        'image attach OK [curl/fn_update_image_pairs]',
        ['product_id' => $productId, 'type' => $isMain ? 'M' : 'A', 'size' => $tempSize],
    );

    return true;
}

/**
 * The hotel product's main image pair, for the booking-form sidebar hero.
 *
 * Returned in CS-Cart's own image-pair shape so the template can hand it
 * straight to common/image.tpl — which is what makes the hero honour the
 * store's Settings -> Thumbnails sizes instead of hard-coding pixels.
 *
 * Lives in functions/ rather than a src/ service because fn_get_image_pairs()
 * is a CS-Cart procedural API; src/ classes stay framework-free.
 *
 * @return array<string, mixed> Empty when the product has no image.
 */
function fn_travel_core_product_main_pair(int $productId): array
{
    if ($productId <= 0 || !function_exists('fn_get_image_pairs')) {
        return [];
    }

    $pair = fn_get_image_pairs($productId, 'product', 'M', true, true, CART_LANGUAGE);

    return is_array($pair) ? \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toStringMap($pair) : [];
}
