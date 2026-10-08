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

use Tygh\Enum\ProfileFieldSections;

require_once (__DIR__ . '/blocks.functions.php');

$schema['lite_checkout_customer_address']['content']['items']['items_function'] = 'fn_blocks_cp_get_lite_checkout_profile_fields';
$schema['lite_checkout_customer_address']['content']['items']['fillings']['cp_profile_type'] = array(
    'params' => array(
        'by_profile_type' => true,
        'profile_section'  => ProfileFieldSections::SHIPPING_ADDRESS
    )
);

$schema['lite_checkout_customer_information']['content']['items']['items_function'] = 'fn_blocks_cp_get_lite_checkout_profile_fields';
$schema['lite_checkout_customer_information']['content']['items']['fillings']['cp_profile_type'] = array(
    'params' => array(
        'by_profile_type' => true,
        'profile_section'  => ProfileFieldSections::CONTACT_INFORMATION
    )
);

$schema['lite_checkout_customer_billing']['content']['items']['items_function'] = 'fn_blocks_cp_get_lite_checkout_profile_fields';
$schema['lite_checkout_customer_billing']['content']['items']['fillings']['cp_profile_type'] = array(
    'params' => array(
        'by_profile_type' => true,
        'profile_section'  => ProfileFieldSections::BILLING_ADDRESS
    )
);

return $schema;