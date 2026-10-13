<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Hook: get_companies — the vendor list filters by type and shows it.
 *
 * @param mixed $params
 * @param mixed $fields
 * @param mixed $sortings
 * @param mixed $condition
 */
function fn_euph_profile_types_get_companies($params = [], &$fields = [], $sortings = [], &$condition = ''): void
{
    if (!is_array($params) || !fn_euph_profile_types_vendor_types_on()) {
        return;
    }
    if (is_array($fields) && !in_array('?:companies.euph_profile_type', $fields, true)) {
        $fields[] = '?:companies.euph_profile_type';
    }
    $code = CodeValidator::normalize(Cast::string($params['euph_profile_type'] ?? ''));
    if ($code === '') {
        return;
    }
    $sql = $code === UserKind::Vendor->standardCode()
        ? db_quote(' AND (?:companies.euph_profile_type = ?s OR ?:companies.euph_profile_type = ?s)', $code, '')
        : db_quote(' AND ?:companies.euph_profile_type = ?s', $code);
    if (is_string($condition)) {
        $condition .= $sql;
    } elseif (is_array($condition)) {
        $condition['euph_profile_type'] = $sql;
    }
}

/**
 * Hook: update_company — a vendor without a type gets the default one; a
 * vendor whose type changed passes it to its administrators, who move to
 * the groups of the new type.
 *
 * @param mixed $company_data
 * @param mixed $company_id
 * @param mixed $lang_code
 * @param mixed $action
 */
function fn_euph_profile_types_update_company($company_data = [], $company_id = 0, $lang_code = '', $action = ''): void
{
    $companyId = Cast::int($company_id);
    if ($companyId <= 0 || !fn_euph_profile_types_vendor_types_on()) {
        return;
    }
    try {
        $container = fn_euph_profile_types_container();
        $catalog = $container->catalog();
        $langCode = fn_euph_profile_types_lang();
        $code = fn_euph_profile_types_company_code($companyId);
        $type = $catalog->byCode($code, $langCode);
        if ($type === null || $type->kind !== UserKind::Vendor) {
            $type = $catalog->defaultType(UserKind::Vendor, 0, $langCode);
            if ($type === null) {
                return;
            }
            $code = $type->code;
            $container->db()->execute('UPDATE ?:companies SET euph_profile_type = ?s WHERE company_id = ?i', $code, $companyId);
        }
        $previous = Cast::string($action) === 'add'
            ? ''
            : Cast::string(Registry::get('runtime.euph_profile_types.company_previous.' . $companyId) ?? $code);
        if (strcasecmp($previous, $code) !== 0) {
            fn_euph_profile_types_apply_company_type($companyId, $previous, $code);
        }
        if (Cast::string($action) === 'add' && fn_euph_profile_types_is_storefront()) {
            fn_euph_profile_types_forget_form_type(UserKind::Vendor);
        }
    } catch (\Throwable $e) {
        error_log('euph_profile_types: ' . $e->getMessage());
    }
}
