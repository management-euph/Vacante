<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Hook: update_user_pre — see fn_euph_profile_types_prepare_user_data().
 *
 * @param mixed $user_id
 * @param mixed $user_data
 */
function fn_euph_profile_types_update_user_pre($user_id = 0, &$user_data = []): void
{
    if (!is_array($user_data)) {
        return;
    }
    try {
        fn_euph_profile_types_prepare_user_data(Cast::int($user_id), $user_data);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: ' . $e->getMessage());
    }
}

/**
 * Hook: update_profile — see fn_euph_profile_types_after_profile_saved().
 *
 * @param mixed $action
 * @param mixed $user_data
 */
function fn_euph_profile_types_update_profile($action = '', $user_data = []): void
{
    if (!is_array($user_data)) {
        return;
    }
    try {
        fn_euph_profile_types_after_profile_saved(Cast::string($action), $user_data);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: ' . $e->getMessage());
    }
}

/**
 * Hook: get_users — the customer list filters by type and shows it.
 *
 * @param mixed $params
 * @param mixed $fields
 * @param mixed $sortings
 * @param mixed $condition
 */
function fn_euph_profile_types_get_users($params = [], &$fields = [], $sortings = [], &$condition = []): void
{
    if (!is_array($params)) {
        return;
    }
    if (is_array($fields) && !in_array('?:users.euph_profile_type', $fields, true)) {
        $fields[] = '?:users.euph_profile_type';
    }
    $code = CodeValidator::normalize(Cast::string($params['euph_profile_type'] ?? ''));
    if ($code === '') {
        return;
    }
    // Accounts created while the add-on was off have no type: the standard one.
    $sql = in_array($code, [UserKind::Customer->standardCode(), UserKind::Vendor->standardCode()], true)
        ? db_quote(' AND (?:users.euph_profile_type = ?s OR ?:users.euph_profile_type = ?s)', $code, '')
        : db_quote(' AND ?:users.euph_profile_type = ?s', $code);
    if (is_array($condition)) {
        $condition['euph_profile_type'] = $sql;
    } elseif (is_string($condition)) {
        $condition .= $sql;
    }
}
