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

if ($mode == 'details') {
    Tygh::$app['view']->assign('cp_profile_types', fn_cp_get_simple_profile_types());

    $order_info = Tygh::$app['view']->getTemplateVars('order_info');
    if (!empty($order_info['cp_profile_type'])
        && $order_info['cp_profile_type'] != fn_cp_get_default_profile_type(UserTypes::CUSTOMER)
    ) {
        $params = array('profile_type' => $order_info['cp_profile_type']);
        $cp_profile_fields = fn_get_profile_fields('I', array(), CART_LANGUAGE, $params);
        Tygh::$app['view']->assign('cp_profile_fields', $cp_profile_fields);
    }
} 