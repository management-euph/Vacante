<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

/**
 * Edits the WHERE fragment fn_get_profile_fields() hands to its
 * "get_profile_fields" hook.
 *
 * CS-Cart limits the query to the fields whose general checkbox of the
 * location is ticked (" AND ?:profile_fields.profile_show = 'Y'"). A field a
 * type shows may have that checkbox off, so for a type-aware fetch the
 * restriction is dropped and FieldVisibility applies both the type's
 * checkboxes and, for the other fields, the general ones.
 */
final class ProfileFieldsSql
{
    private const SHOW_RESTRICTION = '/\s+AND\s+[\w?:`]*profile_fields`?\.`?(?:profile|checkout|partner|storefront)_show`?\s*=\s*([\'"])Y\1/i';

    /**
     * @return array{0: string, 1: bool} the condition, and whether a restriction was dropped
     */
    public static function dropShowRestriction(string $condition): array
    {
        $count = 0;
        $result = preg_replace(self::SHOW_RESTRICTION, '', $condition, -1, $count);
        if (!is_string($result)) {
            return [$condition, false];
        }

        return [$result, $count > 0];
    }
}
