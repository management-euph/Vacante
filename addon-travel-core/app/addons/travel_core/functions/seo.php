<?php

declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/functions/seo.php                    *
 *                                                                          *
 ***************************************************************************/

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * SEO template subsystem — placeholder rendering, per-language template
 * resolution, and the per-product / bulk write paths. Shared by both
 * provider addons; extracted from functions/hotels.php (god-file ratchet).
 */

/**
 * Apply a text modifier to a value.
 *
 * Supported modifiers: lower, upper, title, capitalize, trim, slug,
 * first, last, abs, round, strip_tags.
 *
 * Usage in templates: {{name|upper}}, {{price|round}}, {{city|title}}
 *
 * @param string $value The raw placeholder value
 * @param string $modifier Modifier name (case-insensitive)
 * @return string Modified value
 */
function fn_travel_core_apply_modifier(string $value, string $modifier): string
{
    return match (strtolower($modifier)) {
        'lower' => mb_strtolower($value, 'UTF-8'),
        'upper' => mb_strtoupper($value, 'UTF-8'),
        'title' => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
        'capitalize' => mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8'),
        'trim' => trim($value),
        'slug' => TypeCoerce::toString(function_exists('fn_generate_seo_name') ? fn_generate_seo_name($value) : preg_replace('/-{2,}/', '-', trim((string) preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower($value, 'UTF-8')), '-'))),
        'first' => mb_substr($value, 0, 1, 'UTF-8'),
        'last' => mb_substr($value, -1, 1, 'UTF-8'),
        'abs' => (string) abs((float) $value),
        'round' => (string) round((float) $value),
        'strip_tags' => strip_tags($value),
        default => $value,
    };
}

/**
 * Truncate a string at a word boundary, appending ellipsis if needed.
 *
 * @param string $value The string to truncate
 * @param int $maxLength Maximum length (0 = no limit)
 * @param string $ellipsis Suffix when truncated (default: empty)
 * @return string Truncated string
 */
function fn_travel_core_truncate_seo(string $value, int $maxLength, string $ellipsis = ''): string
{
    if ($maxLength <= 0 || mb_strlen($value, 'UTF-8') <= $maxLength) {
        return $value;
    }

    $cut = mb_substr($value, 0, $maxLength - mb_strlen($ellipsis, 'UTF-8'), 'UTF-8');
    // Find last space to avoid cutting mid-word
    $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($lastSpace !== false && $lastSpace > $maxLength * 0.6) {
        $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
    }

    return rtrim($cut, ' .,;:-') . $ellipsis;
}

/**
 * Build a star rating emoji string (e.g., 4 → "★★★★").
 *
 * @param int $stars Number of stars (0-5)
 * @return string Unicode star characters
 */
function fn_travel_core_build_star_emoji(int $stars): string
{
    return str_repeat('★', max(0, min(5, $stars)));
}

/**
 * Render an SEO template by replacing {{placeholder}} tokens with values.
 *
 * Supports pipe modifiers: {{name|upper}}, {{city|lower}}, {{price|round}}.
 * Arrays are joined as comma-separated (first 3 items).
 * Leftover unreplaced tokens are removed.
 * Dangling separators are cleaned up.
 * Extra spaces are collapsed.
 *
 * @param string $pattern Template string with {{placeholder}} tokens
 * @param array<string, mixed> $placeholders Key => value map (keys without braces)
 * @return string Rendered string, trimmed
 */
function fn_travel_core_render_seo_template(string $pattern, array $placeholders): string
{
    if ($pattern === '') {
        return '';
    }

    // Resolve array placeholders to strings upfront
    $resolved = [];
    foreach ($placeholders as $key => $value) {
        if (is_array($value)) {
            $resolved[$key] = implode(', ', array_slice(array_filter(array_map(
                static fn ($item): string => trim(TypeCoerce::toString($item)),
                $value,
            )), 0, 3));
        } else {
            $resolved[$key] = TypeCoerce::toString($value);
        }
    }

    // Replace {{key}} and {{key|modifier}} in one pass
    $result = (string) preg_replace_callback(
        '/\{\{([a-z_][a-z0-9_]*)(?:\|([a-z_]+))?}}/',
        function ($m) use ($resolved) {
            $value = $resolved[$m[1]] ?? '';
            if (isset($m[2])) {
                $value = fn_travel_core_apply_modifier($value, $m[2]);
            }
            return $value;
        },
        $pattern,
    );

    // Clean up dangling separators left by empty placeholders
    $result = (string) preg_replace('/,\s*,/', ',', $result);           // collapse double commas
    $result = (string) preg_replace('/\s*-\s*,/', ',', $result);        // "- ," → ","
    $result = (string) preg_replace('/,\s*-\s*/', ' - ', $result);      // ", -" → " - "
    $result = (string) preg_replace('/^\s*[-,]\s*/', '', $result);       // leading separator
    $result = (string) preg_replace('/\s*[-,]\s*$/', '', $result);       // trailing separator
    $result = (string) preg_replace('/\(\s*\)/', '', $result);           // empty parentheses
    $result = (string) preg_replace('/\s*-\s*-\s*/', ' - ', $result);   // double dashes

    // Collapse multiple spaces and trim
    return trim((string) preg_replace('/\s{2,}/', ' ', $result));
}

/**
 * Render an SEO template and convert the result to a URL-safe slug.
 *
 * @param string $pattern Template string with {{placeholder}} tokens
 * @param array<string, mixed> $placeholders Key => value map (keys without braces)
 * @return string URL-safe slug
 */
function fn_travel_core_render_seo_slug(string $pattern, array $placeholders): string
{
    $rendered = fn_travel_core_render_seo_template($pattern, $placeholders);
    if ($rendered === '') {
        return '';
    }

    // Use CS-Cart's built-in SEO name generator if available
    if (function_exists('fn_generate_seo_name')) {
        return TypeCoerce::toString(fn_generate_seo_name($rendered));
    }

    // Fallback: basic slug generation
    $slug = mb_strtolower($rendered, 'UTF-8');
    $slug = (string) preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = (string) preg_replace('/-{2,}/', '-', $slug); // collapse multiple dashes
    return trim($slug, '-');
}

// ============================================================================
// SEO settings store — one per provider add-on
// ============================================================================
//
// The overwrite mode, the six "Apply" ticks and every "<key>__<lang>" template
// live in CS-Cart's ?:storage_data (key travel_core_seo_<addon>), as JSON.
// They used to be written only through Settings::updateValue(), but these rows
// are declared in no addon.xml, and on live stores such writes land in the
// registry cache at best: a reload showed the built-in defaults again (the
// same failure the booking colors had, see fn_travel_core_save_appearance_colors).
// Old Settings rows are still read; a stored value wins over them.

function _travel_core_seo_storage_key(string $addonName): string
{
    return 'travel_core_seo_' . $addonName;
}

/**
 * The stored JSON map (one ?:storage_data read).
 *
 * @return array<string, string>
 */
function _travel_core_seo_stored(string $addonName): array
{
    $stored = [];
    if (function_exists('fn_get_storage_data')) {
        $raw = fn_get_storage_data(_travel_core_seo_storage_key($addonName));
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $stored[$key] = (string) $value;
            }
        }
    }

    return $stored;
}

/**
 * A provider's saved SEO settings: its add-on settings (old Settings rows)
 * overlaid with the SEO store, which wins.
 *
 * @return array<string, mixed>
 */
function fn_travel_core_seo_settings(string $addonName): array
{
    return array_merge(
        TypeCoerce::toStringMap(\Tygh\Registry::get('addons.' . $addonName)),
        _travel_core_seo_stored($addonName),
    );
}

/**
 * Merge values into the provider's SEO store. Also mirrors them into the
 * Registry (this request) and, best effort, into Settings.
 *
 * @param array<string, string> $values
 */
function _travel_core_seo_store(string $addonName, array $values): void
{
    if ($values === []) {
        return;
    }

    if (function_exists('fn_set_storage_data')) {
        $merged = array_merge(_travel_core_seo_stored($addonName), $values);
        fn_set_storage_data(
            _travel_core_seo_storage_key($addonName),
            (string) json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    $settings = class_exists(\Tygh\Settings::class) ? \Tygh\Settings::instance() : null;
    if (is_object($settings) && method_exists($settings, 'updateValue')) {
        foreach ($values as $key => $value) {
            // auto_create: these settings aren't declared in addon.xml.
            $settings->updateValue($key, $value, $addonName, true);
        }
    }
    $existing = \Tygh\Registry::get('addons.' . $addonName);
    \Tygh\Registry::set('addons.' . $addonName, array_merge(is_array($existing) ? $existing : [], $values));
}

// ============================================================================
// SEO Field Application — shared by all provider addons
// ============================================================================

/**
 * Field mapping: setting key → [template registry key, product_data key].
 */
/** @return array<string, array{0: string, 1: string}> */
function _travel_core_seo_field_map(): array
{
    return [
        'seo_field_product_name' => ['seo_product_name',     'product'],
        'seo_field_page_title' => ['seo_page_title',       'page_title'],
        'seo_field_meta_description' => ['seo_meta_description', 'meta_description'],
        'seo_field_meta_keywords' => ['seo_meta_keywords',    'meta_keywords'],
        'seo_field_name_slug' => ['seo_name_slug',        'seo_name'],
        'seo_field_full_description' => ['seo_full_description', 'full_description'],
    ];
}

/**
 * Resolve the template pattern for one field in one language.
 *
 * Templates are per-language ONLY: the admin-saved language value
 * (seo_page_title__ro) wins, then the addon's built-in per-language default
 * (seo_page_title__ro in fn_<addon>_seo_defaults()), then the built-in base
 * default. Language-less STORED settings are deliberately not consulted —
 * the per-language keys are the single source of admin truth.
 *
 * @param array<mixed> $settings
 * @param array<mixed> $defaults
 */
function _travel_core_seo_template_for(array $settings, array $defaults, string $templateKey, string $langCode): string
{
    $stored = $settings[$templateKey . '__' . $langCode] ?? '';
    if (is_string($stored) && $stored !== '') {
        return $stored;
    }

    foreach ([$templateKey . '__' . $langCode, $templateKey] as $key) {
        $value = $defaults[$key] ?? '';
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Apply SEO template fields to a product, respecting overwrite mode and field toggles.
 *
 * Returns only the product_data keys that should be written — callers merge
 * this into their own product data array before calling fn_update_product()
 * FOR THE SAME LANGUAGE they rendered for.
 *
 * @param string $addonName 'novoton_holidays' or 'sphinx_holidays'
 * @param array<string, mixed> $placeholders Key => value map for template rendering
 * @param int $productId 0 = new product (all enabled fields applied), >0 = existing
 * @param string|null $hotelId For unique slug generation (SphinxProductFactory pattern)
 * @param string $langCode Storefront language to render for ('' = CART_LANGUAGE)
 * @return array<string, mixed> Product data keys to merge into fn_update_product()
 */
function fn_travel_core_apply_seo_fields(string $addonName, array $placeholders, int $productId = 0, ?string $hotelId = null, string $langCode = ''): array
{
    if ($langCode === '') {
        $langCode = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en';
    }
    $settings = fn_travel_core_seo_settings($addonName);

    // Built-in template defaults exposed by the provider addon's func.php
    // (fn_<addon>_seo_defaults). func.php is loaded in every AREA — including
    // the storefront cron context that creates products — so these defaults are
    // available even when the seo_* settings were never persisted to the DB.
    // Used only as a fallback for blank/absent settings; an admin-saved
    // template always takes precedence.
    $defaults = [];
    $defaultsFn = 'fn_' . $addonName . '_seo_defaults';
    if (function_exists($defaultsFn)) {
        $resolved = $defaultsFn();
        if (is_array($resolved)) {
            $defaults = $resolved;
        }
    }

    $overwriteMode = \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::tryFrom(
        TypeCoerce::toString(($settings['seo_overwrite_mode'] ?? '') ?: (($defaults['seo_overwrite_mode'] ?? '') ?: 'override_all')),
    ) ?? \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::OverrideAll;
    $fillIfEmpty = ($overwriteMode === \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::FillIfEmpty) && ($productId > 0);

    // Load current product values once (only when needed for fill_if_empty)
    $current = [];
    $currentSlug = '';
    if ($fillIfEmpty) {
        $current = TypeCoerce::toStringMap(db_get_row(
            'SELECT product, page_title, meta_description, meta_keywords, full_description
             FROM ?:product_descriptions
             WHERE product_id = ?i AND lang_code = ?s',
            $productId,
            $langCode,
        ));
        $currentSlug = TypeCoerce::toString(db_get_field(
            "SELECT name FROM ?:seo_names WHERE object_id = ?i AND type = 'p' LIMIT 1",
            $productId,
        ));
    }

    $result = [];
    $fieldMap = _travel_core_seo_field_map();

    foreach ($fieldMap as $toggleKey => [$templateKey, $productKey]) {
        // Check field toggle (default Y for backward compat)
        $enabled = ($settings[$toggleKey] ?? '') ?: (($defaults[$toggleKey] ?? '') ?: 'Y');
        if ($enabled !== 'Y') {
            continue;
        }

        // Fill-if-empty: skip if existing value is non-empty
        if ($fillIfEmpty) {
            $existingValue = ($productKey === 'seo_name')
                ? $currentSlug
                : TypeCoerce::toString($current[$productKey] ?? '');
            if (trim($existingValue) !== '') {
                continue;
            }
        }

        // Per-language pattern: stored language override → stored shared
        // setting → built-in language default → built-in base default.
        $template = _travel_core_seo_template_for($settings, $defaults, $templateKey, $langCode);

        // Render the field
        if ($productKey === 'seo_name') {
            $rendered = fn_travel_core_render_seo_slug($template, $placeholders);
            // Ensure uniqueness for existing or new products
            if ($hotelId !== null && function_exists('fn_generate_seo_name')) {
                // Check for duplicates (append hotel_id suffix if needed)
                $existing = db_get_field(
                    "SELECT object_id FROM ?:seo_names WHERE name = ?s AND type = 'p' AND object_id != ?i LIMIT 1",
                    $rendered,
                    $productId,
                );
                if ($existing) {
                    $rendered .= '-' . preg_replace('/[^a-z0-9]/', '', strtolower($hotelId));
                }
            }
            $result[$productKey] = $rendered;
        } elseif ($productKey === 'full_description') {
            // An empty template means "the API description as it is". With no
            // description at hand either (e.g. a bulk apply that doesn't fetch
            // it), write nothing: an empty string would wipe the product's.
            $rendered = $template !== ''
                ? fn_travel_core_render_seo_template($template, $placeholders)
                : TypeCoerce::toString($placeholders['description'] ?? '');
            if (trim($rendered) === '') {
                continue;
            }
            $result[$productKey] = $rendered;
        } else {
            // Skip empty templates — don't write blank strings that would erase
            // values an admin or a previous run already populated.
            if ($template === '') {
                continue;
            }
            $result[$productKey] = fn_travel_core_render_seo_template($template, $placeholders);
        }
    }

    return $result;
}

/**
 * The six template setting keys (without toggles/mode) — the per-language
 * dimension applies exactly to these.
 *
 * @return list<string>
 */
function _travel_core_seo_template_keys(): array
{
    return array_map(
        static fn (array $pair): string => $pair[0],
        array_values(_travel_core_seo_field_map()),
    );
}

/**
 * Per-language form data for a provider's SEO Templates admin page:
 * the installed storefront languages plus each language's EFFECTIVE template
 * per field (stored override → shared legacy value → built-in default), so
 * the admin edits exactly what would render.
 *
 * @param array<string, mixed> $defaults The addon's fn_*_seo_defaults() map
 * @return array{languages: array<string, string>, values: array<string, array<string, string>>}
 */
function fn_travel_core_seo_lang_form_data(string $addonName, array $defaults): array
{
    $languages = [];
    if (function_exists('fn_get_translation_languages')) {
        foreach ((array) fn_get_translation_languages() as $lc => $langRow) {
            $languages[TypeCoerce::toString($lc)] = TypeCoerce::toString(
                is_array($langRow) ? ($langRow['name'] ?? $lc) : $lc,
            );
        }
    }
    if ($languages === []) {
        $lc = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en';
        $languages = [$lc => strtoupper($lc)];
    }

    $settings = fn_travel_core_seo_settings($addonName);
    $values = [];
    foreach (array_keys($languages) as $langKey) {
        foreach (_travel_core_seo_template_keys() as $key) {
            $values[$langKey][$key] = _travel_core_seo_template_for($settings, $defaults, $key, $langKey);
        }
    }

    return ['languages' => $languages, 'values' => $values];
}

/**
 * Persist the per-language template fields posted as seo_lang[<lang>][<key>].
 *
 * Writes each value as "<key>__<lang>" into the provider's SEO store
 * (_travel_core_seo_store: ?:storage_data, mirrored into the Registry so the
 * same request renders the fresh values). Unknown languages and keys are
 * ignored.
 *
 * @param array<mixed, mixed> $seoLang
 */
function fn_travel_core_seo_save_lang_templates(string $addonName, array $seoLang): void
{
    $validLangs = [];
    if (function_exists('fn_get_translation_languages')) {
        $validLangs = array_map(strval(...), array_keys((array) fn_get_translation_languages()));
    }

    $merged = [];
    foreach ($seoLang as $lcRaw => $fields) {
        $lc = TypeCoerce::toString($lcRaw);
        if (!is_array($fields) || ($validLangs !== [] && !in_array($lc, $validLangs, true))) {
            continue;
        }
        foreach (_travel_core_seo_template_keys() as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $merged[$key . '__' . $lc] = TypeCoerce::toString($fields[$key]);
        }
    }

    if ($merged !== []) {
        _travel_core_seo_store($addonName, $merged);
    }
}

/**
 * Render + write the per-language SEO fields for ONE existing product.
 *
 * Each language resolves its own template set (see
 * _travel_core_seo_template_for) and fill-if-empty inspects that language's
 * own current values. $extraFields (e.g. a provider's language-less
 * short_description) are merged into every language's write.
 *
 * @param array<string, mixed> $placeholders
 * @param list<string>|null $languages null = every storefront language
 * @param array<string, mixed> $extraFields
 * @return bool Whether any language produced fields and was written
 */
function fn_travel_core_seo_localize(string $addonName, array $placeholders, int $productId, ?string $hotelId, ?array $languages = null, array $extraFields = []): bool
{
    if ($languages === null) {
        $languages = [defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en'];
        if (function_exists('fn_get_translation_languages')) {
            $allLangs = fn_get_translation_languages();
            if (is_array($allLangs) && !empty($allLangs)) {
                $languages = array_keys($allLangs);
            }
        }
    }

    $wroteAny = false;
    foreach ($languages as $lang) {
        $lang = TypeCoerce::toString($lang);
        $seoFields = fn_travel_core_apply_seo_fields($addonName, $placeholders, $productId, $hotelId, $lang);
        if ($seoFields === []) {
            continue;
        }
        fn_update_product(array_merge($extraFields, $seoFields), $productId, $lang);
        $wroteAny = true;
    }

    return $wroteAny;
}

/**
 * Bulk-apply SEO templates to all existing hotel products for an addon.
 *
 * Respects overwrite mode and field toggles. Uses fn_set_progress() for
 * CS-Cart's native progress bar in the admin panel.
 *
 * Provider addons supply their own data-fetching and placeholder-building
 * callables, keeping travel_core free of addon-specific SQL and class refs.
 *
 * @param string $addonName 'novoton_holidays' or 'sphinx_holidays'
 * @param callable $hotelFetcher fn(int $offset, int $batchSize): array — returns hotel rows
 * @param callable $placeholderBuilder fn(array $hotel): array — returns placeholder map
 * @return array{updated: int, skipped: int, total: int}
 */
function fn_travel_core_seo_bulk_apply(string $addonName, callable $hotelFetcher, callable $placeholderBuilder): array
{
    $updated = 0;
    $skipped = 0;
    $total = 0;
    $batchSize = 200;
    $offset = 0;

    // Apply to every installed storefront language so RO and EN (or whatever
    // is configured) both get the rendered SEO data — not just whatever the
    // admin happens to have selected in the top-right language dropdown.
    $languages = [TypeCoerce::toString(CART_LANGUAGE)];
    if (function_exists('fn_get_translation_languages')) {
        $allLangs = fn_get_translation_languages();
        if (is_array($allLangs) && !empty($allLangs)) {
            $languages = array_map(strval(...), array_keys($allLangs));
        }
    }

    while (true) {
        $hotels = TypeCoerce::toRowList($hotelFetcher($offset, $batchSize));

        if (empty($hotels)) {
            break;
        }

        foreach ($hotels as $hotel) {
            $total++;
            $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
            $placeholders = TypeCoerce::toStringMap($placeholderBuilder($hotel));
            $hotelId = isset($hotel['hotel_id']) ? TypeCoerce::toString($hotel['hotel_id']) : null;

            // Render PER LANGUAGE: each storefront language resolves its own
            // template set (and fill-if-empty checks ITS OWN current values),
            // so RO and EN get their own copy instead of one shared render.
            $wroteAny = fn_travel_core_seo_localize($addonName, $placeholders, $productId, $hotelId, $languages);
            $wroteAny ? $updated++ : $skipped++;

            if (function_exists('fn_set_progress')) {
                fn_set_progress('echo', TypeCoerce::toString($hotel['hotel_name'] ?? $hotel['name'] ?? $hotel['hotel_id'] ?? '') . ' — ' . ($wroteAny ? 'updated' : 'skipped'));
            }
        }

        $offset += $batchSize;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'total' => $total];
}

// ============================================================================
// SEO Templates admin page — shared by every provider add-on
// ============================================================================
//
// Each provider keeps its own page (and its own stored templates); this is
// the code they share. A provider supplies, in its func.php:
//   fn_<addon>_seo_defaults()      built-in templates (already required above)
//   fn_<addon>_seo_placeholders()  group => [placeholder, …] (or [placeholder => label key])
//   fn_<addon>_seo_page()          ['name' => 'Sphinx', 'dispatch' => '...']
// and a thin controller calling fn_travel_core_seo_page_save(),
// fn_travel_core_seo_page_bulk_apply() and fn_travel_core_seo_page_assign().
// The page itself is components/seo_templates_page.tpl + seo-templates.js.

/**
 * The modifiers fn_travel_core_apply_modifier() understands, in the order the
 * page lists them.
 *
 * @return list<string>
 */
function fn_travel_core_seo_modifiers(): array
{
    return ['lower', 'upper', 'title', 'capitalize', 'trim', 'slug', 'strip_tags', 'first', 'last', 'abs', 'round'];
}

/**
 * A provider's placeholders, grouped: group => [placeholder => label lang key].
 * Providers list bare keys (labelled travel_core.seo_ph_<key>) or key => label.
 *
 * @return array<string, array<string, string>>
 */
function fn_travel_core_seo_placeholder_groups(string $addonName): array
{
    $fn = 'fn_' . $addonName . '_seo_placeholders';
    if (!function_exists($fn)) {
        return [];
    }
    $groups = [];
    foreach ((array) $fn() as $group => $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $key => $label) {
            // A bare key uses the shared label travel_core.seo_ph_<key>.
            if (is_int($key)) {
                $key = TypeCoerce::toString($label);
                $label = 'travel_core.seo_ph_' . $key;
            }
            $groups[TypeCoerce::toString($group)][TypeCoerce::toString($key)] = TypeCoerce::toString($label);
        }
    }

    return $groups;
}

/**
 * Every placeholder key a provider offers.
 *
 * @return list<string>
 */
function fn_travel_core_seo_placeholder_keys(string $addonName): array
{
    $keys = [];
    foreach (fn_travel_core_seo_placeholder_groups($addonName) as $items) {
        foreach (array_keys($items) as $key) {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

/**
 * What in a template the engine would silently swallow: a placeholder the
 * provider doesn't offer (renders empty), an unknown modifier (ignored), a
 * second modifier or unbalanced braces (the token isn't recognised at all).
 * Each entry is [lang key, value]; seo-templates.js runs the same checks.
 *
 * @param list<string> $keys
 * @return list<array{0: string, 1: string}>
 */
function fn_travel_core_seo_template_problems(string $template, array $keys): array
{
    $problems = [];
    $modifiers = fn_travel_core_seo_modifiers();
    preg_match_all('/\{\{([a-z_][a-z0-9_]*)(?:\|([a-z_]+))?}}/', $template, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        if (!in_array($m[1], $keys, true)) {
            $problems['p' . $m[1]] = ['travel_core.seo_problem_unknown_placeholder', '{{' . $m[1] . '}}'];
        }
        if (isset($m[2]) && !in_array($m[2], $modifiers, true)) {
            $problems['m' . $m[2]] = ['travel_core.seo_problem_unknown_modifier', '|' . $m[2]];
        }
    }
    if (preg_match('/\{\{[^{}]*\|[^{}]*\|[^{}]*}}/', $template) === 1) {
        $problems['one'] = ['travel_core.seo_problem_one_modifier', ''];
    }
    if (substr_count($template, '{{') !== substr_count($template, '}}')) {
        $problems['braces'] = ['travel_core.seo_problem_unbalanced', ''];
    }

    return array_values($problems);
}

/**
 * The add-ons that have an SEO Templates page, for the tab row at the top of
 * each page. Only active add-ons load their func.php, so an add-on that is
 * off simply has no fn_<addon>_seo_page() and no tab.
 *
 * @return list<array{addon: string, name: string, url: string, current: bool}>
 */
function fn_travel_core_seo_page_providers(string $currentAddon): array
{
    $addons = \Tygh\Registry::get('addons');
    $providers = [];
    foreach (array_keys(is_array($addons) ? $addons : []) as $id) {
        $id = TypeCoerce::toString($id);
        $fn = 'fn_' . $id . '_seo_page';
        if ($id === '' || !function_exists($fn)) {
            continue;
        }
        $page = TypeCoerce::toStringMap($fn());
        $dispatch = TypeCoerce::toString($page['dispatch'] ?? '');
        if ($dispatch === '') {
            continue;
        }
        $providers[] = [
            'addon'   => $id,
            'name'    => TypeCoerce::toString($page['name'] ?? $id),
            'url'     => function_exists('fn_url') ? TypeCoerce::toString(fn_url($dispatch)) : $dispatch,
            'current' => $id === $currentAddon,
        ];
    }
    usort($providers, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $providers;
}

/**
 * The provider's built-in templates (fn_<addon>_seo_defaults()).
 *
 * @return array<string, string>
 */
function _travel_core_seo_defaults_of(string $addonName): array
{
    $fn = 'fn_' . $addonName . '_seo_defaults';

    $defaults = [];
    foreach (function_exists($fn) ? TypeCoerce::toStringMap($fn()) : [] as $key => $value) {
        $defaults[$key] = TypeCoerce::toString($value);
    }

    return $defaults;
}

/**
 * Save the page: the overwrite mode, the six "Apply" ticks and every
 * language's templates. Warns (without refusing) about placeholders the
 * engine would drop.
 *
 * @param array<mixed> $request $_REQUEST
 */
function fn_travel_core_seo_page_save(string $addonName, array $request, bool $notify = true): void
{
    $submitted = TypeCoerce::toStringMap($request['seo'] ?? []);
    $toSave = [];
    $mode = \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::tryFrom(TypeCoerce::toString($submitted['seo_overwrite_mode'] ?? ''));
    $toSave['seo_overwrite_mode'] = ($mode ?? \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::OverrideAll)->value;
    // The six templates are per language (seo_lang below); only the ticks are
    // global. A tick missing from the POST is an unticked box.
    foreach (array_keys(_travel_core_seo_field_map()) as $toggleKey) {
        $toSave[$toggleKey] = !empty($submitted[$toggleKey]) ? 'Y' : 'N';
    }

    _travel_core_seo_store($addonName, $toSave);

    $seoLang = is_array($request['seo_lang'] ?? null) ? $request['seo_lang'] : [];
    fn_travel_core_seo_save_lang_templates($addonName, $seoLang);

    if (!$notify || !function_exists('fn_set_notification')) {
        return;
    }
    fn_set_notification('N', __('notice'), __('travel_core.seo_templates_saved'));

    $keys = fn_travel_core_seo_placeholder_keys($addonName);
    if ($keys === []) {
        return;
    }
    foreach ($seoLang as $lc => $fields) {
        if (!is_array($fields)) {
            continue;
        }
        foreach ($fields as $key => $template) {
            foreach (fn_travel_core_seo_template_problems(TypeCoerce::toString($template), $keys) as [$langKey, $value]) {
                fn_set_notification('W', __('warning'), strtoupper(TypeCoerce::toString($lc)) . ' · '
                    . TypeCoerce::toString(__('travel_core.' . TypeCoerce::toString($key))) . ': '
                    . str_replace('[value]', $value, TypeCoerce::toString(__($langKey))));
            }
        }
    }
}

/**
 * "Apply templates now": saves what is on the page first (so what the admin
 * sees is what gets applied), then re-renders every linked product in every
 * language, with CS-Cart's progress bar.
 *
 * @param array<mixed> $request $_REQUEST
 * @param callable(int, int): array<mixed> $fetcher
 * @param callable(array<mixed>): array<mixed> $builder
 * @return array<mixed> the controller's return value
 */
function fn_travel_core_seo_page_bulk_apply(string $addonName, array $request, callable $fetcher, callable $builder, string $redirect): array
{
    fn_travel_core_seo_page_save($addonName, $request, false);

    return fn_travel_core_run_long_task(
        TypeCoerce::toString(__('travel_core.seo_bulk_apply_progress')),
        static fn (): array => fn_travel_core_seo_bulk_apply($addonName, $fetcher, $builder),
        $redirect,
        static function (array $result): void {
            fn_set_notification('N', __('notice'), str_replace(
                ['[updated]', '[total]'],
                [TypeCoerce::toString($result['updated'] ?? 0), TypeCoerce::toString($result['total'] ?? 0)],
                TypeCoerce::toString(__('travel_core.seo_bulk_apply_done')),
            ));
        },
    );
}

/**
 * Everything components/seo_templates_page.tpl shows, as one array.
 *
 * @param array<string, mixed>|null $sample Placeholders of one real hotel, for the preview
 * @param array{save: string, apply: string, title?: string} $dispatch
 * @return array<string, mixed>
 */
function fn_travel_core_seo_page_data(string $addonName, ?array $sample, array $dispatch): array
{
    $defaults = _travel_core_seo_defaults_of($addonName);
    $current = fn_travel_core_seo_settings($addonName);

    $mode = \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::tryFrom(
        TypeCoerce::toString(($current['seo_overwrite_mode'] ?? '') ?: ($defaults['seo_overwrite_mode'] ?? '')),
    ) ?? \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::OverrideAll;
    $values = ['seo_overwrite_mode' => $mode->value];
    foreach (array_keys(_travel_core_seo_field_map()) as $toggleKey) {
        $values[$toggleKey] = (($current[$toggleKey] ?? '') ?: (($defaults[$toggleKey] ?? '') ?: 'Y')) === 'N' ? 'N' : 'Y';
    }

    $langData = fn_travel_core_seo_lang_form_data($addonName, $defaults);
    $builtIn = [];
    foreach (array_keys($langData['languages']) as $lc) {
        foreach (_travel_core_seo_template_keys() as $key) {
            $builtIn[$lc][$key] = _travel_core_seo_template_for([], $defaults, $key, $lc);
        }
    }

    $groups = [];
    foreach (fn_travel_core_seo_placeholder_groups($addonName) as $group => $items) {
        $rows = [];
        foreach ($items as $key => $labelKey) {
            $rows[] = ['key' => $key, 'label' => TypeCoerce::toString(__($labelKey))];
        }
        $groups[] = ['label' => TypeCoerce::toString(__('travel_core.seo_group_' . $group)), 'items' => $rows];
    }

    $label = static fn (string $key): string => TypeCoerce::toString(__('travel_core.' . $key));
    $storeUrl = TypeCoerce::toString(\Tygh\Registry::get('config.http_host'));
    $config = [
        'keys'      => fn_travel_core_seo_placeholder_keys($addonName),
        'modifiers' => fn_travel_core_seo_modifiers(),
        'sample'    => $sample === null || $sample === [] ? null : $sample,
        'store_url' => $storeUrl,
        'labels'    => [
            'target_none'          => $label('seo_target_none'),
            'target_into'          => $label('seo_target_into'),
            'target_fallback'      => $label('seo_target_fallback'),
            'modifier_needs_field' => $label('seo_modifier_needs_field'),
            'modifier_needs_token' => $label('seo_modifier_needs_token'),
            'modifier_replaced'    => $label('seo_modifier_replaced'),
            'restored'             => $label('seo_restored'),
            'counter_title'        => $label('seo_counter_title'),
            'preview_kept'         => $label('seo_preview_kept'),
            'unknown_placeholder'  => $label('seo_problem_unknown_placeholder'),
            'unknown_modifier'     => $label('seo_problem_unknown_modifier'),
            'one_modifier'         => $label('seo_problem_one_modifier'),
            'unbalanced'           => $label('seo_problem_unbalanced'),
        ],
    ];

    $providers = fn_travel_core_seo_page_providers($addonName);

    return [
        'addon'          => $addonName,
        'save_dispatch'  => $dispatch['save'],
        'save_url'       => function_exists('fn_url') ? TypeCoerce::toString(fn_url($dispatch['save'])) : $dispatch['save'],
        'apply_url'      => function_exists('fn_url') ? TypeCoerce::toString(fn_url($dispatch['apply'])) : $dispatch['apply'],
        'title'          => $dispatch['title'] ?? '',
        'providers'      => $providers,
        'show_providers' => count($providers) > 1,
        'groups'         => $groups,
        'modifiers'      => fn_travel_core_seo_modifiers(),
        'defaults'       => $builtIn,
        'sample_name'    => $sample === null ? '' : TypeCoerce::toString($sample['name'] ?? ''),
        'config_json'    => (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'values'         => $values,
        'languages'      => $langData['languages'],
        'lang_values'    => $langData['values'],
    ];
}

/**
 * Assign the page data to the view (manage mode of a provider's controller).
 *
 * @param array<string, mixed>|null $sample
 * @param array{save: string, apply: string, title?: string} $dispatch
 */
function fn_travel_core_seo_page_assign(string $addonName, ?array $sample, array $dispatch): void
{
    $data = fn_travel_core_seo_page_data($addonName, $sample, $dispatch);
    $view = \Tygh\Tygh::$app['view'] ?? null;
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('seo_page', $data);
        $view->assign('seo_values', $data['values']);
        $view->assign('seo_languages', $data['languages']);
        $view->assign('seo_lang_values', $data['lang_values']);
    }
}
