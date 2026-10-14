<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Profile fields: CS-Cart's own list with the per-type checkboxes in its
 * Show / Required cells (hooks/profile_fields/field_settings.post.tpl, all
 * types or one type at a time), and the "Profile types" block of a field
 * card (hooks/profile_fields/profile_data.post.tpl). No template of the list
 * or of the card is replaced.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'update' && is_array($_REQUEST['euph_field_rules'] ?? null)) {
        fn_euph_profile_types_save_field_card(
            $_REQUEST['euph_field_rules'],
            Cast::int($_REQUEST['field_id'] ?? 0),
            Cast::int(Registry::get('runtime.euph_profile_types.max_field_id')),
        );
    }
    if ($mode === 'm_update' && is_array($_REQUEST['euph_pt_list'] ?? null)) {
        fn_euph_profile_types_save_fields_list($_REQUEST['euph_pt_list'], Cast::string($_REQUEST['profile_type'] ?? '') ?: 'U');
    }
    if ($mode === 'delete' || $mode === 'm_delete') {
        fn_euph_profile_types_container()->rules()->deleteOrphans();
    }

    return;
}

if ($mode === 'manage') {
    $profileType = Cast::string(fn_euph_profile_types_template_var('profile_type'))
        ?: (Cast::string($_REQUEST['profile_type'] ?? '') ?: 'U');
    $list = fn_euph_profile_types_fields_list($profileType);
    if ($list !== null) {
        fn_euph_profile_types_assign('euph_pt_list', $list);
        fn_euph_profile_types_assign('euph_pt_list_config', fn_euph_profile_types_js_value(
            fn_euph_profile_types_fields_list_config($list, Cast::string($_REQUEST['euph_view'] ?? '')),
        ));
    }

    return;
}

if ($mode === 'update' || $mode === 'add') {
    $field = Cast::array(fn_euph_profile_types_template_var('field'));
    $profileType = Cast::string($field['profile_type'] ?? '') ?: (Cast::string($_REQUEST['profile_type'] ?? '') ?: 'U');
    fn_euph_profile_types_assign('euph_pt_field_card', fn_euph_profile_types_field_card($field, $profileType));
}
