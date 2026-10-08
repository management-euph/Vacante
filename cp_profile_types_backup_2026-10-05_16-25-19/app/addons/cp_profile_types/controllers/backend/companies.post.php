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

if ($mode == 'update' || $mode == 'add') {
    $user_type = UserTypes::VENDOR;
    $default_profile_type = fn_cp_get_default_profile_type($user_type);
    Tygh::$app['view']->assign('cp_default_profile_type', $default_profile_type);
    Tygh::$app['view']->assign('cp_profile_types', fn_cp_get_simple_profile_types($user_type));
    
    $company = Tygh::$app['view']->getTemplateVars('company_data');
    if (!empty($_REQUEST['cp_profile_type'])) {
        $profile_type = $_REQUEST['cp_profile_type'];
    } else {
        $profile_type = !empty($company['cp_profile_type'])
            ? $company['cp_profile_type']
            : $default_profile_type;
    }
    if ($profile_type != $default_profile_type) {
        $params = array('profile_type' => $profile_type, 'skip_email_field' => false);
        $profile_fields = fn_get_profile_fields($user_type, array(), CART_LANGUAGE, $params);
        Tygh::$app['view']->assign('profile_fields', $profile_fields);
    }
} 

if ($mode == 'manage') {
    $custom_types = fn_cp_get_simple_profile_types(UserTypes::VENDOR, CART_LANGUAGE, true);
    Tygh::$app['view']->assign('cp_profile_types', $custom_types);
}
