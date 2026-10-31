<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * The name a Eurosite room shows in the store's language.
 *
 * Every offer room carries its hotel's own name ("Double Room") and its
 * general code (GCode="DB") from the getRoomRequest catalog (22 codes,
 * 31 Oct 2026). The room reads by its code's language variable,
 * eurosite.room_type_<code> ("Cameră dublă"), which admins can reword under
 * Languages → Translations; a hotel name that says more than the catalog
 * name ("Double Room Sea View") keeps that detail in brackets. A code
 * without a label yet keeps Eurosite's own name.
 */
final class RoomTypeNames
{
    /**
     * GCode => Eurosite's catalog name, to tell a hotel's wording of the
     * same room ("Double Room") from one that says more. Not a translation:
     * those are the language variables.
     */
    private const array CATALOG = [
        'AP' => 'apartament',
        'D2X' => 'double with 2 children',
        'D3C' => 'double 3 adults 1 child under 2 years',
        'D3X' => 'double 3 adults 1 child over 2 years',
        'DB' => 'double',
        'DB3' => 'double 3 adults',
        'DBC' => 'double with child ( age under 2 years )',
        'DBX' => 'double with child ( age over 2 years )',
        'Q' => 'quad',
        'S2X' => 'single as double with 2 children',
        'SB' => 'single',
        'SBC' => 'single with child ( age under 2 years )',
        'SD' => 'studio',
        'TB' => 'twin',
        'TBC' => 'twin with child ( age under 2 years )',
        'TBX' => 'twin with child ( age over 2 years )',
        'TR' => 'triple',
        'TRC' => 'triple with child ( age under 2 years )',
        'TS' => 'twin for sole use',
        'TSC' => 'twin for sole use with child ( age under 2 years )',
        'TSX' => 'twin for sole use with child over 2 year2',
        'VL' => 'vila',
    ];

    /** The language variable of a room code: eurosite.room_type_db. */
    public static function langKey(string $code): string
    {
        return 'eurosite.room_type_' . strtolower($code);
    }

    /** @return list<string> every catalog code */
    public static function codes(): array
    {
        return array_map('strval', array_keys(self::CATALOG));
    }

    /**
     * @param string $gcode the room's general code (GCode); '' when the offer has none
     * @param string $name the hotel's own name for the room
     * @param string $lang the page's language ('ro', 'en', …); '' outside the store: Eurosite's name
     * @param (\Closure(string, string): string)|null $translate key, language => label (default: CS-Cart's __())
     */
    public static function label(string $gcode, string $name, string $lang, ?\Closure $translate = null): string
    {
        $name = trim($name);
        if ($lang === '') {
            return $name;
        }
        $code = strtoupper(trim($gcode));
        if (!isset(self::CATALOG[$code])) {
            $code = self::codeOfName($name);
        }
        if ($code === '') {
            return $name;
        }
        $key = self::langKey($code);
        $label = trim(($translate ?? self::translated(...))($key, $lang));
        if ($label === '' || str_contains($label, $key)) {
            // Not seeded yet: CS-Cart answers "_eurosite.room_type_db".
            return $name;
        }

        return $name === '' || self::plain($name) === self::plain(self::CATALOG[$code]) ? $label : "{$label} ({$name})";
    }

    private static function translated(string $key, string $lang): string
    {
        if (!function_exists('__')) {
            return '';
        }
        $label = __($key, [], $lang);

        return is_string($label) ? $label : '';
    }

    /** The catalog code whose name the hotel's name repeats; '' when none. */
    private static function codeOfName(string $name): string
    {
        foreach (self::CATALOG as $code => $catalog) {
            if ($name !== '' && self::plain($name) === self::plain($catalog)) {
                return (string) $code;
            }
        }

        return '';
    }

    /** "Double Room", "double ( room )" → "double": case, punctuation and the word room left out. */
    private static function plain(string $name): string
    {
        $words = preg_split('/[^a-z0-9ăâîșşțţ]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_diff(is_array($words) ? $words : [], ['room', 'camera', 'cameră']));
    }
}
