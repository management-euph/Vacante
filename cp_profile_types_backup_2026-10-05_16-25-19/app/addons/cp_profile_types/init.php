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

if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_register_hooks(
    'update_user_pre',
    'update_profile',
    'get_users',
    'get_companies',
    'create_order',
    'update_order',
    'update_company',
    'get_profile_fields',
    'get_product_filter_fields',
    'get_products',
    'generate_filter_field_params',
    'get_filters_products_count_post',
    'get_product_data_post',
    'update_addon_status_pre'
);

if (Registry::get('addons.rus_payments.status') == "A") {
    fn_register_hooks('dispatch_assign_template');
}
