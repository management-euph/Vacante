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
    if ($mode == 'customer_info') {
        fn_trusted_vars('user_data');

        $cart = & Tygh::$app['session']['cart'];
        if (!empty($_REQUEST['user_data']['cp_profile_type']) && !empty($cart['user_data'])) {
            $post_data = $_REQUEST['user_data'];
            $cp_profile_type = $_REQUEST['user_data']['cp_profile_type'];
            $cart_user_data = array_filter($cart['user_data']);
            $extra_data = fn_cp_pt_get_profile_filtered_data($cp_profile_type, $post_data, array());
            if (!empty($extra_data)) {
                $cart['cp_pt_saved_data'] = $extra_data;
            }
        }
    }
    return;
}

if ($mode == 'update' || $mode == 'add') {
    $cart = & Tygh::$app['session']['cart'];
    $user_type = UserTypes::CUSTOMER;
    $profile_type = '';
    if (isset($_REQUEST['cp_profile_type'])) {
        $profile_type = $_REQUEST['cp_profile_type'];
    } else {
        $user_data = !empty($cart['user_data']) ? $cart['user_data'] : array();
        $profile_type = !empty($user_data['cp_profile_type'])
            ? $user_data['cp_profile_type']
            : fn_cp_get_default_profile_type($user_type);
    }
    if (!empty($profile_type)) {
        $cart['cp_profile_type'] = $profile_type;
        $params = array('profile_type' => $profile_type);
        $profile_fields = fn_get_profile_fields('O', array(), CART_LANGUAGE, $params);
        Tygh::$app['view']->assign('profile_fields', $profile_fields);

        if (!empty($cart['order_id']) || !empty($cart['cp_pt_saved_data'])) {
            $saved_data = !empty($cart['cp_pt_saved_data']) ? $cart['cp_pt_saved_data'] : fn_get_order_info($cart['order_id']);
            $extra_data = fn_cp_pt_get_profile_filtered_data($profile_type, $saved_data, $cart['user_data']);
            if (!empty($extra_data)) {
                $cart['user_data'] = array_merge($cart['user_data'], $extra_data);
                Tygh::$app['view']->assign('cart', $cart);
                Tygh::$app['view']->assign('user_data', $cart['user_data']);
            }
        }
    }
    Tygh::$app['view']->assign('cp_profile_types', fn_cp_get_simple_profile_types($user_type));
}