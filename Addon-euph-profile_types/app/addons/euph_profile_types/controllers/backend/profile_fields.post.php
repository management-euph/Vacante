<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Customers → Profile fields: the "Profile types" block of a field card
 * (hooks/profile_fields/profile_data.post.tpl). The list of fields stays the
 * stock one.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'update' && is_array($_REQUEST['euph_field_rules'] ?? null)) {
        fn_euph_profile_types_save_field_card(
            $_REQUEST['euph_field_rules'],
            Cast::int($_REQUEST['field_id'] ?? 0),
            Cast::int(Registry::get('runtime.euph_profile_types.max_field_id')),
        );
    }
    if ($mode === 'delete' || $mode === 'm_delete') {
        fn_euph_profile_types_container()->rules()->deleteOrphans();
    }

    return;
}

if ($mode === 'update' || $mode === 'add') {
    $field = Cast::array(fn_euph_profile_types_template_var('field'));
    $profileType = Cast::string($field['profile_type'] ?? '') ?: (Cast::string($_REQUEST['profile_type'] ?? '') ?: 'U');
    fn_euph_profile_types_assign('euph_pt_field_card', fn_euph_profile_types_field_card($field, $profileType));
}
