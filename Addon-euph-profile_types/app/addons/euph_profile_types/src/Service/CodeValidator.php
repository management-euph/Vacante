<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

/**
 * Type codes: what the customer and order columns keep, and what a
 * registration link carries (?euph_profile_type=B). Upper case letters,
 * digits and underscores, up to 32 characters.
 */
final class CodeValidator
{
    public const MAX_LENGTH = 32;

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^[A-Z0-9_]{1,' . self::MAX_LENGTH . '}$/', $code) === 1;
    }

    /**
     * The first free single letter, as the 1.x add-on gave, then T1, T2...
     *
     * @param list<string> $taken
     */
    public static function suggest(array $taken): string
    {
        $taken = array_map(self::normalize(...), $taken);
        foreach (range('A', 'Z') as $letter) {
            if (!in_array($letter, $taken, true)) {
                return $letter;
            }
        }
        for ($n = 1; ; ++$n) {
            if (!in_array('T' . $n, $taken, true)) {
                return 'T' . $n;
            }
        }
    }
}
