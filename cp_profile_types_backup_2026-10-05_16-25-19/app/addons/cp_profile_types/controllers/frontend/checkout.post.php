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

use Tygh\Enum\ObjectStatuses;
use Tygh\Registry;
use Tygh\Enum\UserTypes;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    return;
}

if ($mode == 'checkout') {
    $user_type = !empty($auth['user_type']) ? $auth['user_type'] : 'C';
    if ($user_type == UserTypes::CUSTOMER) {
        $cp_type_code = fn_cp_get_simple_profile_types_default($user_type);

        if (empty($_REQUEST['cp_profile_type'])){
            $params['profile_type'] = $cp_type_code;
        } else {
            $params['profile_type'] = !empty($_REQUEST['cp_profile_type'])
            ? $_REQUEST['cp_profile_type']
            : fn_cp_get_default_profile_type($user_type);
        }

        Tygh::$app['view']->assign('cp_type_code', $cp_type_code);
        Tygh::$app['view']->assign('cp_profile_types', fn_cp_get_simple_profile_types($user_type));
    }

    if (
        !empty(Registry::get('addons.rus_payments'))
        && Registry::get('addons.rus_payments.status') == "A"
    ) {
        $cart = &Tygh::$app['session']['cart'];
        $phone = '';

        if (!empty($cart['user_data']['phone'])) {
            $phone = $cart['user_data']['phone'];
    
        } elseif (!empty($cart['user_data']['b_phone'])) {
            $phone = $cart['user_data']['b_phone'];

        } elseif (!empty($cart['user_data']['s_phone'])) {
            $phone = $cart['user_data']['s_phone'];
        }

        $phone_normalize =  fn_rus_payments_normalize_phone($phone);
        $payment_id = (!empty($cart['payment_method_data']['payment_id'])) ? $cart['payment_method_data']['payment_id'] : 0;
        $processor_script = db_get_field(
            "SELECT processor_script FROM ?:payments 
            INNER JOIN ?:payment_processors USING (processor_id) 
            WHERE payment_id = ?i", $payment_id
        );

        if (!empty($cart['cp_profile_type']) && empty($cart['user_data']['cp_profile_type'])) {
            $cart['user_data']['cp_profile_type'] = $cart['cp_profile_type'];
        }

        if (($processor_script == 'account.php') && !empty($cart['payment_method_data']['processor_params']['fields_account'])) {
            $account_params = fn_cp_profile_types_account_fields(
                $cart['payment_method_data']['processor_params']['fields_account'],
                $cart['user_data']
            );
            if (!empty($account_params)) {
                Tygh::$app['view']->assign('account_params', $account_params);
            }
        }
        Tygh::$app['view']->assign('phone_normalize', $phone_normalize);
    }
}
