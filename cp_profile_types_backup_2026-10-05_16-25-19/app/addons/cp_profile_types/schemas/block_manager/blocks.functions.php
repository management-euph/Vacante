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
use Tygh\Enum\ProfileTypes;

function fn_blocks_cp_get_lite_checkout_profile_fields($params)
{
    if (!empty($params['by_profile_type'])) {
        $profile_type = !empty(Tygh::$app['session']['cart']['cp_profile_type'])
            ? Tygh::$app['session']['cart']['cp_profile_type']
            : ProfileTypes::CODE_USER;
        
        if (empty($params['profile_section'])) {
            return array();
        }
        $section = $params['profile_section'];
        $pr_params = array(
            'profile_type' => $profile_type,
            'section' => $section
        );
        $profile_fields = fn_get_profile_fields('ALL', [], DESCR_SL, $pr_params);
        unset($profile_fields[ProfileFieldSections::ESSENTIALS]);
        
        if (!empty($profile_fields[$section])) {
            $profile_fields[$section] = array_filter($profile_fields[$section], function ($field) {
                return (!empty($field['checkout_show']) && $field['checkout_show'] == 'Y') ? true : false;
            });
        }
        
        return [$profile_fields];
    } else {

        $item_ids = isset($params['item_ids']) ? explode(',', $params['item_ids']) : '';
        if (empty($item_ids)) {
            return [];
        }

        $profile_fields = fn_get_profile_fields('ALL', [], DESCR_SL, ['include_ids' => $item_ids]);
        unset($profile_fields[ProfileFieldSections::ESSENTIALS]);

        $section = key($profile_fields);
        $sorted_fields = fn_sort_by_ids($profile_fields[$section], $item_ids, 'field_id');

        $position = 0;
        foreach ($sorted_fields as $field_id => $field) {
            $sorted_fields[$field_id]['position'] = $position;
            $position += 10;
        }

        $prepared_profile_fields = [$section => $sorted_fields];

        return [$prepared_profile_fields];
    }
}

return $schema;
