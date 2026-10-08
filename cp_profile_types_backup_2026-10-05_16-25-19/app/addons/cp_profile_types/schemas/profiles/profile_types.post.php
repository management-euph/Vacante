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

use Tygh\Enum\ProfileTypes;
use Tygh\Enum\UserTypes;
use Tygh\Enum\ProfileFieldAreas;
use Tygh\Enum\ProfileFieldSections;

defined('BOOTSTRAP') or die('Access denied');

$cp_custom_types = fn_cp_get_simple_profile_types(UserTypes::CUSTOMER, CART_LANGUAGE, false);
foreach ($cp_custom_types as $type_code => $profile_type) {

        $schema[$type_code] = array(
            'name' => 'cp_type_' . $type_code,
            'allowed_sections' => array(
                ProfileFieldSections::CONTACT_INFORMATION,
                ProfileFieldSections::BILLING_AND_SHIPPING_ADDRESS,
            ),
            'allowed_areas' => array(
                ProfileFieldAreas::PROFILE,
                ProfileFieldAreas::CHECKOUT
            )
        );
    }
    
return $schema;
