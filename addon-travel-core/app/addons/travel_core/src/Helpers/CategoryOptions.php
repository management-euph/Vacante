<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Every CS-Cart category as options for a *_category_id selectbox setting,
 * labelled with its full path ("Hotels / Romania / Mamaia").
 *
 * Shared by the provider add-ons (Sphinx's hotels / packages / circuits
 * categories, Eurosite's hotels category), so each settings dropdown lists
 * the categories the same way. Called from their fn_settings_variants_*
 * shells, which CS-Cart's settings page calls by name.
 */
final class CategoryOptions
{
    /**
     * Flat associative array of all CS-Cart categories for selectbox
     * settings. Option labels show the full category path
     * (e.g. "Hotels / Albania / Durres"); tree order is preserved by
     * sorting on id_path.
     *
     * @return array<int, string> [category_id => "Parent / Child / …"] with 0 => "None" first.
     */
    public static function build(): array
    {
        $lang = defined('DESCR_SL') ? DESCR_SL : 'en';

        $categories = db_get_array(
            'SELECT c.category_id, c.id_path
             FROM ?:categories c
             ORDER BY c.id_path ASC',
        );

        if (!is_array($categories) || $categories === []) {
            return [0 => TypeCoerce::toString(__('none'))];
        }

        // Name lookup for the current backend language; fall back to EN.
        $names = db_get_hash_single_array(
            'SELECT category_id, category FROM ?:category_descriptions WHERE lang_code = ?s',
            ['category_id', 'category'],
            $lang,
        );

        if (empty($names)) {
            $names = db_get_hash_single_array(
                "SELECT category_id, category FROM ?:category_descriptions WHERE lang_code = 'en'",
                ['category_id', 'category'],
            );
        }
        $names = is_array($names) ? $names : [];

        $options = [0 => TypeCoerce::toString(__('none'))];

        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $cat_id = TypeCoerce::toInt($cat['category_id'] ?? 0);
            $parts = array_filter(explode('/', trim(TypeCoerce::toString($cat['id_path'] ?? ''), '/')));
            $path_labels = [];

            foreach ($parts as $part_id) {
                $pid = (int) $part_id;
                if (isset($names[$pid])) {
                    $path_labels[] = TypeCoerce::toString($names[$pid]);
                }
            }

            if ($path_labels !== []) {
                $options[$cat_id] = implode(' / ', $path_labels);
            } elseif (isset($names[$cat_id])) {
                $options[$cat_id] = TypeCoerce::toString($names[$cat_id]);
            }
        }

        return $options;
    }
}
