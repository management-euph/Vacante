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
use Tygh\Enum\ProfileTypes;
use Tygh\Enum\UserTypes;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    return;
}

if ($mode == 'update' || $mode == 'add') {
    $user_type = Tygh::$app['view']->getTemplateVars('user_type');
    if ($user_type == UserTypes::CUSTOMER) {
        $profile_type = '';
        if (isset($_REQUEST['cp_profile_type'])) {
            $profile_type = $_REQUEST['cp_profile_type'];
        } else {
            $user_data = Tygh::$app['view']->getTemplateVars('user_data');
            $profile_type = !empty($user_data['cp_profile_type'])
                ? $user_data['cp_profile_type']
                : fn_cp_get_default_profile_type($user_type);
        }
        if (!empty($profile_type)) {
            $params = array('profile_type' => $profile_type);
            $profile_fields = fn_get_profile_fields($user_type, array(), CART_LANGUAGE, $params);
            Tygh::$app['view']->assign('profile_fields', $profile_fields);
        }

        Tygh::$app['view']->assign('cp_profile_types', fn_cp_get_simple_profile_types($user_type));
    }
}

if ($mode == 'manage') {
    $user_type = !empty($_REQUEST['user_type']) ? $_REQUEST['user_type'] : '';
    if ($user_type == 'C') {
        $custom_types = fn_cp_get_simple_profile_types($user_type, CART_LANGUAGE, true);
        Tygh::$app['view']->assign('cp_profile_types', $custom_types);
    }
}
