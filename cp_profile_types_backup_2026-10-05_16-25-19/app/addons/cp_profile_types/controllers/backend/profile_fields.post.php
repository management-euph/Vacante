<?php
/*****************************************************************************
*                                                        © 2013 Cart-Power   *
*           __   ______           __        ____                             *
*          / /  / ____/___ ______/ /_      / __ \____ _      _____  _____    *
*      __ / /  / /   / __ `/ ___/ __/_____/ /_/ / __ \ | /| / / _ \/ ___/    *
*     / // /  / /___/ /_/ / /  / /_/_____/ ____/ /_/ / |/ |/ /  __/ /        *
*    /_//_/   \____/\__,_/_/   \__/     /_/    \____/|__/|__/\___/_/         *
*                                                                            *
*                                                                            *
* -------------------------------------------------------------------------- *
* This is commercial software, only users who have purchased a valid license *
* and  accept to the terms of the License Agreement can install and use this *
* program.                                                                   *
* -------------------------------------------------------------------------- *
* website: https://store.cart-power.com                                      *
* email:   sales@cart-power.com                                              *
******************************************************************************/

use Tygh\Registry;
use Tygh\Enum\UserTypes;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    return;
}

if ($mode == 'manage') {
    $sections = Registry::get('navigation.dynamic.sections');
    $cp_custom_types = fn_cp_get_simple_profile_types('', CART_LANGUAGE, false);
    $cp_custom_types_code = fn_cp_get_profile_types_code();
    // DO WE NEED IT?
    foreach ($cp_custom_types as $type_code => $t_name) {
        $sections[$type_code]['title'] = $t_name;
    }

    $_sections = [];
    foreach ($cp_custom_types_code as $type_code) {
        if (array_key_exists($type_code['code'], $sections)) {
            $_sections[$type_code['user_type']][$type_code['code']] = $sections[$type_code['code']];
        }
    }
    if (!empty($_sections)) {
        if (!fn_allowed_for('MULTIVENDOR')) {
            unset($_sections[UserTypes::VENDOR]);
        }
        $sections = $_sections;
    }

    ksort($sections);
    Registry::set('navigation.dynamic.sections', $sections);
    Registry::set('navigation.dynamic.sections_type', $cp_custom_types_code);

    if (!empty($_REQUEST['profile_type'])) {
        $cp_profile_type_data = fn_cp_get_profile_type_by_code($_REQUEST['profile_type']);
        $vendor_profile_type_codes = [];
        if (
            !empty($cp_profile_type_data['user_type'])
            && $cp_profile_type_data['user_type'] == UserTypes::VENDOR
        ) {
            $vendor_profile_type_codes = fn_cp_get_simple_profile_types($cp_profile_type_data['user_type']);
        }
        Tygh::$app['view']->assign([
            'vendor_profile_type_codes' => array_keys($vendor_profile_type_codes)
        ]);
    }
}

if ($mode == 'update' || $mode == 'add') {
    if (!empty($_REQUEST['profile_type'])) {
        $cp_profile_type_data = fn_cp_get_profile_type_by_code($_REQUEST['profile_type']);
        $vendor_profile_type_codes = [];
        if (
            !empty($cp_profile_type_data['user_type'])
            && $cp_profile_type_data['user_type'] == UserTypes::VENDOR
        ) {
            $vendor_profile_type_codes = fn_cp_get_simple_profile_types($cp_profile_type_data['user_type']);
        }
        Tygh::$app['view']->assign([
            'vendor_profile_type_codes' => array_keys($vendor_profile_type_codes)
        ]);
    }

    $field = Tygh::$app['view']->getTemplateVars('field');
    if (!empty($field['profile_type'])) {
        $profile_type = fn_cp_get_profile_type_by_code($field['profile_type']);
        if (!empty($profile_type['user_type']) && $profile_type['user_type'] == UserTypes::VENDOR) {
            $default_type = fn_cp_get_default_profile_type($profile_type['user_type']);
            $field['profile_type'] = $default_type;
            Tygh::$app['view']->assign('field', $field);
        }
    }

    if (!empty(Registry::get('addons.vendor_plans')) &&  Registry::get('addons.vendor_plans.status') == "A") {
        $vendors_type_profiles = fn_cp_get_profile_type_vendor_type_profiles();
        Tygh::$app['view']->assign('vendors_type_profiles', $vendors_type_profiles);
    }
}
