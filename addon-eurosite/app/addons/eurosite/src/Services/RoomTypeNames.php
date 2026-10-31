<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * The name a Eurosite room shows in the store's language.
 *
 * Every offer room carries its hotel's own name ("Double Room") and its
 * general code (GCode="DB") from the getRoomRequest catalog (22 codes,
 * 31 Oct 2026). On a Romanian page the room reads by its code ("Cameră
 * dublă"); a hotel name that says more than the catalog name ("Double Room
 * Sea View") keeps that detail in brackets. Other languages, and a code the
 * catalog does not have yet, keep Eurosite's own name.
 */
final class RoomTypeNames
{
    /** GCode => [Eurosite's catalog name, Romanian]. */
    private const array CATALOG = [
        'AP' => ['apartament', 'Apartament'],
        'D2X' => ['double with 2 children', 'Cameră dublă cu 2 copii'],
        'D3C' => ['double 3 adults 1 child under 2 years', 'Cameră dublă, 3 adulți, 1 copil sub 2 ani'],
        'D3X' => ['double 3 adults 1 child over 2 years', 'Cameră dublă, 3 adulți, 1 copil peste 2 ani'],
        'DB' => ['double', 'Cameră dublă'],
        'DB3' => ['double 3 adults', 'Cameră dublă, 3 adulți'],
        'DBC' => ['double with child ( age under 2 years )', 'Cameră dublă cu copil sub 2 ani'],
        'DBX' => ['double with child ( age over 2 years )', 'Cameră dublă cu copil peste 2 ani'],
        'Q' => ['quad', 'Cameră cvadruplă'],
        'S2X' => ['single as double with 2 children', 'Cameră single folosită ca dublă, cu 2 copii'],
        'SB' => ['single', 'Cameră single'],
        'SBC' => ['single with child ( age under 2 years )', 'Cameră single cu copil sub 2 ani'],
        'SD' => ['studio', 'Studio'],
        'TB' => ['twin', 'Cameră twin (paturi separate)'],
        'TBC' => ['twin with child ( age under 2 years )', 'Cameră twin cu copil sub 2 ani'],
        'TBX' => ['twin with child ( age over 2 years )', 'Cameră twin cu copil peste 2 ani'],
        'TR' => ['triple', 'Cameră triplă'],
        'TRC' => ['triple with child ( age under 2 years )', 'Cameră triplă cu copil sub 2 ani'],
        'TS' => ['twin for sole use', 'Cameră twin în regim single'],
        'TSC' => ['twin for sole use with child ( age under 2 years )', 'Cameră twin în regim single, cu copil sub 2 ani'],
        'TSX' => ['twin for sole use with child over 2 year2', 'Cameră twin în regim single, cu copil peste 2 ani'],
        'VL' => ['vila', 'Vilă'],
    ];

    /**
     * @param string $gcode the room's general code (GCode); '' when the offer has none
     * @param string $name the hotel's own name for the room
     * @param string $lang the page's language ('ro', 'en', …)
     */
    public static function label(string $gcode, string $name, string $lang): string
    {
        $name = trim($name);
        if (strtolower($lang) !== 'ro') {
            return $name;
        }
        $code = strtoupper(trim($gcode));
        if (!isset(self::CATALOG[$code])) {
            $code = self::codeOfName($name);
        }
        if ($code === '') {
            return $name;
        }
        [$catalog, $ro] = self::CATALOG[$code];

        return $name === '' || self::plain($name) === self::plain($catalog) ? $ro : "{$ro} ({$name})";
    }

    /** The catalog code whose name the hotel's name repeats; '' when none. */
    private static function codeOfName(string $name): string
    {
        foreach (self::CATALOG as $code => [$catalog]) {
            if ($name !== '' && self::plain($name) === self::plain($catalog)) {
                return $code;
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
