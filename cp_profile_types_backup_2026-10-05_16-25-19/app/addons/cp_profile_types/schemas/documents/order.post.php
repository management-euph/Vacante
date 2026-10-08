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
use Tygh\Enum\YesNo;
use Tygh\Enum\UserTypes;

$schema['user']['data'] = function (\Tygh\Template\Document\Order\Context $context) {
    $order = $context->getOrder();
    return !empty($order->data['cp_profile_type']) ? fn_cp_get_template_order_user_data($order->data) : $order->getUser();
};

$schema['user']['attributes'] = function () {
        $attributes = ['email', 'firstname', 'lastname', 'phone'];
        $code_cp_user = db_get_fields("SELECT code FROM ?:cp_profile_types WHERE status = ?s AND user_type = ?s", 'A', UserTypes::CUSTOMER);
        foreach ($code_cp_user as $key_cp){
            $params['profile_type'] = $key_cp; 
            $group_fields = fn_get_profile_fields('ALL', [], CART_LANGUAGE, $params);
            $sections = ['C','B','S'];
            foreach ($sections as $section) {
                if (isset($group_fields[$section])) {
                    foreach ($group_fields[$section] as $field) {
                        if (!empty($field['field_name'])) {
                            $attributes[] = $field['field_name'];

                            if (in_array($field['field_type'], ['A', 'O'])) {
                                $attributes[] = $field['field_name'] . '_descr';
                            }
                        }
                    }
                }

                $attributes[strtolower($section) . '_fields']['[0..N]'] = [
                    'name',
                    'value',
                ];
            }
        }
        return $attributes;
    };

$schema['company']['attributes'] = function () {
        $attributes = ['email', 'firstname', 'lastname', 'phone'];
        $code_cp_user = db_get_fields("SELECT code FROM ?:cp_profile_types WHERE status = ?s AND user_type = ?s", 'A', UserTypes::VENDOR);
        foreach ($code_cp_user as $key_cp){
            $params['profile_type'] = $key_cp; 
            $group_fields = fn_get_profile_fields('ALL', [], CART_LANGUAGE, $params);
            $sections = ['C','B','S'];
            foreach ($sections as $section) {
                if (isset($group_fields[$section])) {
                    foreach ($group_fields[$section] as $field) {
                        if (!empty($field['field_name'])) {
                            $attributes[] = $field['field_name'];

                            if (in_array($field['field_type'], ['A', 'O'])) {
                                $attributes[] = $field['field_name'] . '_descr';
                            }
                        }
                    }
                }

                $attributes[strtolower($section) . '_fields']['[0..N]'] = [
                    'name',
                    'value',
                ];
            }
        }
        return $attributes;
    };
return $schema;
