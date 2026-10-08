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

if ($mode == 'apply_for_vendor') {

    $user_type = UserTypes::VENDOR;
    $cp_type_code = fn_cp_get_simple_profile_types_default($user_type);
    if (empty($_REQUEST['cp_profile_type'])){
        $profile_type = $cp_type_code;
    } else {
        $profile_type = !empty($_REQUEST['cp_profile_type'])
        ? $_REQUEST['cp_profile_type']
        : fn_cp_get_default_profile_type($user_type);
    }
    
    if (!empty($profile_type)) {
        $params = array(
            'profile_type' => $profile_type,
            'skip_email_field' => false
        );
        $all_profile_fields = fn_get_profile_fields($user_type, array(), CART_LANGUAGE, $params);
        $vendor_plans = explode(',', db_get_field("SELECT id_plans FROM ?:cp_profile_types WHERE code = ?s", $profile_type));
        
        if (!empty($vendor_plans['0'])){
            foreach ($all_profile_fields as $group_id => $profile_group){
                foreach ($profile_group as $field_id => $field_data){
                    if(isset($field_data['plans'])){
                        foreach ($field_data['plans'] as $plan_key => $plan){
                            if (!in_array($plan->plan_id, $vendor_plans)) {
                                unset($all_profile_fields[$group_id][$field_id]['plans'][$plan_key]); 
                            }
                        }
                    }
                }
            }
        }
        Tygh::$app['view']->assign('profile_fields', $all_profile_fields);
    }
    Tygh::$app['view']->assign('cp_type_code', $cp_type_code);
    Tygh::$app['view']->assign('cp_profile_types', fn_cp_get_simple_profile_types($user_type));
}

if ($mode === 'view') {
    if (Registry::get('addons.cp_profile_types.add_profile_types_name') == "Y") {
        $company_data = Tygh::$app['view']->getTemplateVars('company_data'); 
        $profile_fields = Tygh::$app['view']->getTemplateVars('profile_fields'); 
        foreach ($profile_fields as &$profile_field) {
            foreach ($profile_field as &$profile) {
                if ($profile['field_name'] == 'company') {
                    $profile['description'] = fn_cp_profile_types_name_description ($company_data['cp_profile_type']);
                }
            }
        }
        Tygh::$app['view']->assign('profile_fields', $profile_fields);
    }
    }