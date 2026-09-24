<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

use Tygh\Addons\TravelCore\Helpers\CategoryOptions;

/**
 * Builds the category selectbox options for the addon's *_category_id
 * settings (body of the fn_settings_variants_* shells in func.php, which
 * CS-Cart's settings machinery calls by name). The list itself lives in
 * Travel Core, shared with Eurosite's hotels category.
 */
final class CategoryOptionsBuilder
{
    /**
     * @return array<int, string> [category_id => "Parent / Child / …"] with 0 => "None" first.
     */
    public static function build(): array
    {
        return CategoryOptions::build();
    }
}
