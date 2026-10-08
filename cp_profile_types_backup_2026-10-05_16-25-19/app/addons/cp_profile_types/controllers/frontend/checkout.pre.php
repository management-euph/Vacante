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

if ($mode == 'checkout') {
    $cart = &Tygh::$app['session']['cart'];
    if (!empty($_REQUEST['cp_profile_type'])) {
        $cart['cp_profile_type'] = $_REQUEST['cp_profile_type'];
        $user_id = !empty($auth['user_id']) ? $auth['user_id'] : 0;
        fn_save_cart_content($cart, $user_id);

    } elseif (!empty($auth['user_id'])) {
        if (!empty($cart['cp_profile_type'])) {
            return;
        }
        $cart['cp_profile_type'] = fn_cp_get_user_profile_type($auth['user_id']);
    } else {
        $cart['cp_profile_type'] = fn_cp_get_default_profile_type(UserTypes::CUSTOMER);
    }
}