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
use Tygh\Models\VendorPlan;
use Tygh\Enum\UserTypes;
use Tygh\Enum\ProfileTypes;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($mode == 'delete') {
        if (!empty($_REQUEST['type_id'])) {
            fn_cp_delete_profile_type($_REQUEST['type_id']);
        }
    }
    if ($mode == 'm_delete') {
        if (!empty($_REQUEST['type_ids'])) {
            foreach ($_REQUEST['type_ids'] as $type_id) {
                $code = fn_cp_code_profile_type($type_id);
                if (in_array($code, array(ProfileTypes::CODE_SELLER, ProfileTypes::CODE_USER))) {
                    fn_set_notification('E', __('error'), __('cp_profile_types_delete'));
                } else {
                    fn_cp_delete_profile_type($type_id);
                }
            }
        }
    }
    if ($mode == 'update') {
        $cpv1 = ___cp('dHlwZV9pZA');
        $cpv2 = ___cp('dHlwZV9kYPRh');
        if (!empty($_REQUEST[$cpv2])) {
            $type_id = !empty($_REQUEST[$cpv1]) ? $_REQUEST[$cpv1] : 0;
            $type_id = call_user_func(___cp('Zm5fY3BfdPBkYPRlP3Byb2ZpbGVfdHlwZQ'), $type_id, $_REQUEST[$cpv2]);
            return array(CONTROLLER_STATUS_OK, 'cp_profile_types.update?type_id=' . $type_id);
        }
    }
    if ($mode == 'm_update') {
        if (!empty($_REQUEST['types_data'])) {
            foreach ($_REQUEST['types_data'] as $type_id => $type_data) {
                db_query('UPDATE ?:cp_profile_types SET ?u WHERE type_id = ?i', $type_data, $type_id);
            }
        }
        if (!empty($_REQUEST['is_default'])) {
            db_query('UPDATE ?:cp_profile_types SET is_default = ?i WHERE user_type = ?s', '0', $_REQUEST['user_type']);
            db_query('UPDATE ?:cp_profile_types SET is_default = ?i WHERE type_id = ?i', $_REQUEST['is_default'], $_REQUEST['is_default']);
        }
    }
    $suffix = '';
    if (!empty($_REQUEST['user_type'])) {
        $suffix .= '?user_type=' . $_REQUEST['user_type'];
    }
    
    return array(CONTROLLER_STATUS_OK, 'cp_profile_types.manage' . $suffix);
}

if ($mode == 'manage') {
    if (fn_allowed_for('MULTIVENDOR')) {
        $user_type = !empty($_REQUEST['user_type']) ? $_REQUEST['user_type'] : UserTypes::CUSTOMER;
        $_REQUEST['user_type'] = $_GET['user_type'] = $_POST['user_type'] = $user_type;
        
        Registry::set('navigation.dynamic.sections', array (
            UserTypes::CUSTOMER => array (
                'title' => __('cp_user_profile_types'),
                'href' => 'cp_profile_types.manage?user_type=C',
            ),
            UserTypes::VENDOR => array (
                'title' => __('cp_vendor_profile_types'),
                'href' => 'cp_profile_types.manage?user_type=V',
            )
        ));
        Registry::set('navigation.dynamic.active_section', $_REQUEST['user_type']);
    }

    $cpv1 = ___cp('cHJvZmlsZV90ePBlcw');
    list($types, $search) = call_user_func(___cp('Zm5fY3BfZ2V0P3Byb2ZpbGVfdHlwZPM'), $_REQUEST, Registry::get('settings.Appearance.admin_elements_per_page'));
    Tygh::$app['view']->assign($cpv1, $types);
    Tygh::$app['view']->assign('search', $search);
    Tygh::$app['view']->assign('cp_profile_type', $_REQUEST['user_type']);

} elseif ($mode == 'update' || $mode == 'add') {
    $tabs = array(
        'general' => array(
            'title' => __('general'),
            'js' => true
        ),
        'addons' => array(
            'title' => __('addons'),
            'js' => true
        )
    );
    Registry::set('navigation.tabs', $tabs);

    if (!empty($_REQUEST['type_id'])) {
        $type = fn_cp_get_profile_type_data($_REQUEST['type_id']);
        Tygh::$app['view']->assign('type', $type);
        if ($type['user_type'] == UserTypes::VENDOR){
            $params = array_merge(
                $_REQUEST,
                array(
                    'return_params'       => true,
                    'get_companies_count' => true,
                    'lang_code'           => DESCR_SL,
                )
            );
            if (!empty(Registry::get('addons.vendor_plans')) && Registry::get('addons.vendor_plans.status') == "A") {
                list($plans, $search) = VendorPlan::model()->findMany($params);
                Tygh::$app['view']->assign('plans', $plans);
            }
        }
    
        if (!empty($type['user_type']) && in_array($type['user_type'], array(UserTypes::CUSTOMER, UserTypes::VENDOR))) {
            $ug_params = array(
                'type' => $type['user_type'],
                'status' => array('A', 'H')
            );

            Tygh::$app['view']->assign('usergroups', fn_get_usergroups($ug_params, DESCR_SL));

            $default_usergroups = array();
            if ($type['user_type'] == UserTypes::VENDOR && Registry::get('addons.vendor_privileges.status') == 'A') {
                $default_usergroup_id = Registry::get('addons.vendor_privileges.default_vendor_usesrgroup');
                if (!empty($default_usergroup_id)) {
                    $default_usergroups[] = $default_usergroup_id;
                }
            }
            Tygh::$app['view']->assign('default_usergroups', $default_usergroups);
        }
    }
} 
