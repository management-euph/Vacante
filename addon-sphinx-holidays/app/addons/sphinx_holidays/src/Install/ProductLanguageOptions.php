<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Builds the options of the "Product languages" checkboxes setting: every
 * active CS-Cart language (body of the fn_settings_variants_* shell in
 * func.php, which CS-Cart's settings machinery calls by name).
 */
final class ProductLanguageOptions
{
    /**
     * @return array<string, string> [lang_code => "Name (CODE)"]
     */
    public static function build(): array
    {
        $languages = TypeCoerce::toRowList(
            db_get_array("SELECT lang_code, name FROM ?:languages WHERE status = 'A' ORDER BY name"),
        );
        $result = [];
        foreach ($languages as $lang) {
            $code = TypeCoerce::toString($lang['lang_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $result[$code] = TypeCoerce::toString($lang['name'] ?? '') . ' (' . strtoupper($code) . ')';
        }
        return $result;
    }
}
