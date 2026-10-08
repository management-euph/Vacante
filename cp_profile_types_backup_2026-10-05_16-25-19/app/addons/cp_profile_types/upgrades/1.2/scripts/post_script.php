<?php
use Tygh\Languages\Languages;

$addon_id = 'cp_profile_types';
$addon_scheme = Tygh\Addons\SchemesManager::clearInternalCache($addon_id);
$addon_scheme = Tygh\Addons\SchemesManager::getScheme($addon_id);

if (function_exists('fn_get_addon_settings_values')
    && function_exists('fn_get_addon_settings_vendor_values')
) {
    $setting_values = $settings_vendor_values = array();
    $settings_values = fn_get_addon_settings_values($addon_id);
    $settings_vendor_values = fn_get_addon_settings_vendor_values($addon_id);

    fn_update_addon_settings($addon_scheme, true, $settings_values, $settings_vendor_values);
} else {
    fn_update_addon_settings($addon_scheme, true);
}

$vendor_profiles = array(
    'code'        => 'S',
    'user_type'   => 'V',
    'status'      => 'A',
    'is_default'  => '1',
);
$user_profiles = array(
    'code'        => 'U',
    'user_type'   => 'C',
    'status'      => 'A',
    'is_default'  => '1'
);

$vendor_profiles_type_id = db_query('INSERT INTO ?:cp_profile_types ?e', $vendor_profiles);
$user_profiles_type_id = db_query('INSERT INTO ?:cp_profile_types ?e', $user_profiles);

$languages = Languages::getAll();
$array_profiles_type_descriptions = [];

foreach ($languages as $lang_code => $lang_data) {
    
    $array_profiles_type_descriptions[] = array(
        'lang_code' => $lang_code,
        'name' => __("cp_profile_common_vendor", [], $lang_code),
        'type_id' => $vendor_profiles_type_id,
    );

    $array_profiles_type_descriptions[] = array(
        'lang_code' => $lang_code,
        'name' => __("cp_profile_common_user", [], $lang_code),
        'type_id' => $user_profiles_type_id,
    );
}

db_query('INSERT INTO ?:cp_profile_type_descriptions ?m', $array_profiles_type_descriptions);

db_query('UPDATE ?:users SET cp_profile_type = ?s WHERE cp_profile_type = ?s', 'U','');
if (fn_allowed_for('MULTIVENDOR')) {
    db_query('UPDATE ?:companies SET cp_profile_type = ?s WHERE cp_profile_type = ?s', 'S','');
}
