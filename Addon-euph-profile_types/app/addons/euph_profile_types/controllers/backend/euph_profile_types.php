<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Customers → Profile types (and, in Multi-Vendor, Vendors → Vendor types).
 *
 * GET  euph_profile_types.manage?user_type=C|V   the list of types
 * GET  euph_profile_types.add?user_type=C|V      a new type
 * GET  euph_profile_types.update?type_id=N       the type card: General, Rules, Fields
 * POST euph_profile_types.update                 type_id, type_data[...], type_fields[field id][...]
 * POST euph_profile_types.m_update               positions (types_data) and the default type
 * POST euph_profile_types.delete                 type_id
 * POST euph_profile_types.m_delete               type_ids[]
 * POST euph_profile_types.import_legacy          carries types and fields over from 1.x
 * POST euph_profile_types.set_order_type         order_id, euph_profile_type (order sidebar)
 */

$container = fn_euph_profile_types_container();
fn_euph_profile_types_ensure_link_keys();
$kind = UserKind::tryFrom(Cast::string($_REQUEST['user_type'] ?? '')) ?? UserKind::Customer;
if ($kind === UserKind::Vendor && !$container->isMultiVendor()) {
    $kind = UserKind::Customer;
}
$langCode = fn_euph_profile_types_descr_lang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $suffix = '.manage?user_type=' . $kind->value;

    if ($mode === 'update') {
        $typeId = fn_euph_update_profile_type($_REQUEST['type_id'] ?? 0, $_REQUEST['type_data'] ?? [], $langCode);
        if ($typeId > 0) {
            fn_euph_profile_types_save_type_fields($typeId, Cast::array($_REQUEST['type_fields'] ?? []));
            fn_euph_profile_types_notify('N', Cast::string(__('text_changes_saved')));
            $section = preg_replace('/[^a-z_]/', '', Cast::string($_REQUEST['selected_section'] ?? '')) ?? '';
            $suffix = '.update?type_id=' . $typeId . ($section !== '' ? '&selected_section=' . $section : '');
        } else {
            $requestedId = Cast::int($_REQUEST['type_id'] ?? 0);
            $suffix = $requestedId > 0 ? '.update?type_id=' . $requestedId : '.add?user_type=' . $kind->value;
        }
    }

    if ($mode === 'm_update') {
        $container->admin()->saveList(
            Cast::array($_REQUEST['types_data'] ?? []),
            Cast::int($_REQUEST['default_type_id'] ?? 0),
            $kind,
        );
        fn_euph_profile_types_notify('N', Cast::string(__('text_changes_saved')));
    }

    if ($mode === 'delete') {
        fn_euph_delete_profile_type($_REQUEST['type_id'] ?? 0);
    }

    if ($mode === 'm_delete') {
        foreach (Cast::idList($_REQUEST['type_ids'] ?? []) as $typeId) {
            fn_euph_delete_profile_type($typeId);
        }
    }

    if ($mode === 'import_legacy') {
        $importer = $container->importer();
        if ($importer->isAvailable()) {
            $stats = $importer->import(fn_euph_profile_types_lang(), fn_euph_profile_types_lang_codes());
            fn_euph_profile_types_fill_empty_types();
            fn_euph_profile_types_notify('N', fn_euph_profile_types_text(
                'euph_profile_types.legacy_imported',
                'Carried over from version 1.x: [types] types, [rules] field settings, [adopted] fields joined the common list, [values] values copied.',
                [
                    '[types]' => (string) $stats['types'],
                    '[rules]' => (string) $stats['rules'],
                    '[adopted]' => (string) $stats['adopted_fields'],
                    '[values]' => (string) $stats['values'],
                ],
            ));
        }
    }

    if ($mode === 'set_order_type') {
        $orderId = Cast::int($_REQUEST['order_id'] ?? 0);
        $type = $container->catalog()->assignableByAdmin(
            UserKind::Customer,
            CodeValidator::normalize(Cast::string($_REQUEST['euph_profile_type'] ?? '')),
            fn_euph_profile_types_lang(),
        );
        if ($orderId > 0 && $type !== null && Cast::int(Registry::get('runtime.company_id')) === 0) {
            $container->db()->execute('UPDATE ?:orders SET euph_profile_type = ?s WHERE order_id = ?i', $type->code, $orderId);
            fn_euph_profile_types_notify('N', Cast::string(__('text_changes_saved')));
        }

        return [CONTROLLER_STATUS_OK, 'orders.details?order_id=' . $orderId];
    }

    return [CONTROLLER_STATUS_OK, 'euph_profile_types' . $suffix];
}

if ($mode === 'manage') {
    if ($container->isMultiVendor() && $container->catalog()->isEnabled(UserKind::Vendor)) {
        Registry::set('navigation.dynamic.sections', [
            UserKind::Customer->value => [
                'title' => __('euph_profile_types.customer_types'),
                'href' => 'euph_profile_types.manage?user_type=' . UserKind::Customer->value,
            ],
            UserKind::Vendor->value => [
                'title' => __('euph_profile_types.vendor_types'),
                'href' => 'euph_profile_types.manage?user_type=' . UserKind::Vendor->value,
            ],
        ]);
        Registry::set('navigation.dynamic.active_section', $kind->value);
    }

    fn_euph_profile_types_assign('euph_pt_kind', $kind->value);
    fn_euph_profile_types_assign('euph_pt_kind_enabled', $container->catalog()->isEnabled($kind));
    fn_euph_profile_types_assign('euph_pt_types', fn_euph_profile_types_to_arrays($container->types()->ofKind($kind, $langCode)));
    fn_euph_profile_types_assign('euph_pt_usage', $container->types()->usage($kind));
    fn_euph_profile_types_assign('euph_pt_usergroups', fn_euph_profile_types_usergroups($kind));
    fn_euph_profile_types_assign('euph_pt_storefronts', fn_euph_profile_types_get_storefronts());
    fn_euph_profile_types_assign('euph_pt_legacy_available', $container->importer()->isAvailable());
}

if ($mode === 'update' || $mode === 'add') {
    $typeId = Cast::int($_REQUEST['type_id'] ?? 0);
    $type = $mode === 'update' ? $container->types()->find($typeId, $langCode) : null;
    if ($mode === 'update' && $type === null) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
    $kind = $type !== null ? $type->kind : $kind;

    $tabs = [
        'general' => ['title' => __('general'), 'js' => true],
        'rules' => ['title' => __('euph_profile_types.tab_rules'), 'js' => true],
    ];
    if ($type !== null) {
        $tabs['fields'] = ['title' => __('euph_profile_types.tab_fields'), 'js' => true];
    }
    Registry::set('navigation.tabs', $tabs);

    fn_euph_profile_types_assign('euph_pt_kind', $kind->value);
    fn_euph_profile_types_assign('euph_pt_type', $type !== null ? $type->toArray() : [
        'type_id' => 0,
        'code' => $container->admin()->suggestCode(),
        'user_type' => $kind->value,
        'status' => 'A',
        'position' => 0,
        'is_default' => 'N',
        'is_standard' => 'N',
        'activation' => 'S',
        'name' => '',
        'description' => '',
        'usergroup_ids' => [],
        'storefront_ids' => [],
        'vendor_plan_ids' => [],
    ]);
    fn_euph_profile_types_assign('euph_pt_usergroups', fn_euph_profile_types_usergroups($kind));
    fn_euph_profile_types_assign('euph_pt_storefronts', fn_euph_profile_types_get_storefronts());
    fn_euph_profile_types_assign('euph_pt_vendor_plans', $kind === UserKind::Vendor ? fn_euph_profile_types_get_vendor_plans() : []);
    if ($type !== null) {
        fn_euph_profile_types_assign('euph_pt_field_table', fn_euph_profile_types_type_field_table($type));
        fn_euph_profile_types_assign('euph_pt_links', fn_euph_profile_types_registration_links($type));
        fn_euph_profile_types_assign('euph_pt_usage', $container->types()->usage($kind)[$type->code] ?? 0);
    }
}
