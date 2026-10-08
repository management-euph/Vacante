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

use Tygh\Enum\UserTypes;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_trusted_vars("processor_params", "payment_data");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    return [CONTROLLER_STATUS_OK];
}

if ($mode == 'processor') {
    if (!empty($_REQUEST['payment_id'])) {
        $processor_script = db_get_field("SELECT processor_script FROM ?:payments INNER JOIN ?:payment_processors USING (processor_id) WHERE payment_id = ?i", $_REQUEST['payment_id']);
    }

    if (!empty($_REQUEST['processor_id'])) {
        $processor_script = db_get_field("SELECT processor_script FROM ?:payment_processors WHERE processor_id = ?i", $_REQUEST['processor_id']);
    }

    if (!empty($processor_script) && ($processor_script == 'account.php')) {
        $profile_types = fn_cp_get_simple_profile_types(UserTypes::CUSTOMER);
        if (!empty($profile_types) && count($profile_types) > 1) {
            $account_fields = fn_get_schema('rus_payments', 'account_fields');
            $cp_account_fields = array_fill_keys(array_keys($profile_types), $account_fields);
            foreach ($profile_types as $code => $name) {
                $profile_fields[$code] = fn_get_profile_fields('ALL', [], CART_LANGUAGE, ['profile_type' => $code]);
            }
            Tygh::$app['view']->assign('cp_account_fields', $cp_account_fields);
            Tygh::$app['view']->assign('profile_types', $profile_types);
            Tygh::$app['view']->assign('profile_fields', $profile_fields);
        }
    }
}
