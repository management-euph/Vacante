<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** Whether vendor types are in use: Multi-Vendor, and "Use profile types for" covers vendors. */
function fn_euph_profile_types_vendor_types_on(): bool
{
    return fn_euph_profile_types_container()->catalog()->isEnabled(UserKind::Vendor);
}

function fn_euph_profile_types_company_code(int $companyId): string
{
    if ($companyId <= 0 || !fn_euph_profile_types_container()->isMultiVendor()) {
        return '';
    }

    return Cast::string(fn_euph_profile_types_container()->db()->value(
        'SELECT euph_profile_type FROM ?:companies WHERE company_id = ?i',
        $companyId,
    ));
}

/** The type of a vendor, by name: the product page and the vendor card. */
function fn_euph_profile_types_get_company_type_name(mixed $company_id): string
{
    static $names;
    if (!is_array($names)) {
        $names = [];
    }
    $companyId = Cast::int($company_id);
    if ($companyId <= 0 || !fn_euph_profile_types_vendor_types_on()) {
        return '';
    }
    if (!isset($names[$companyId])) {
        $code = fn_euph_profile_types_company_code($companyId);
        $names[$companyId] = $code !== '' ? fn_euph_profile_types_get_type_name($code) : '';
    }

    return Cast::string($names[$companyId]);
}

/**
 * A vendor moves to another type: the type goes to its administrators too
 * (so the export of users shows it) and they move to the groups of the new type.
 */
function fn_euph_profile_types_apply_company_type(int $companyId, string $oldCode, string $newCode): void
{
    $container = fn_euph_profile_types_container();
    $catalog = $container->catalog();
    $langCode = fn_euph_profile_types_lang();
    $old = $oldCode !== '' ? $catalog->byCode($oldCode, $langCode) : null;
    $new = $catalog->byCode($newCode, $langCode);
    $userIds = Cast::idList($container->db()->column(
        'SELECT user_id FROM ?:users WHERE company_id = ?i AND user_type = ?s',
        $companyId,
        UserKind::Vendor->value,
    ));
    $container->db()->execute(
        'UPDATE ?:users SET euph_profile_type = ?s WHERE company_id = ?i AND user_type = ?s',
        $newCode,
        $companyId,
        UserKind::Vendor->value,
    );
    foreach ($userIds as $userId) {
        fn_euph_profile_types_sync_usergroups(
            $userId,
            $old !== null ? $old->usergroupIds : [],
            $new !== null ? $new->usergroupIds : [],
            $container->settings()->groupStatus,
        );
    }
}

/**
 * Plans of the Vendor plans add-on, for the type card.
 *
 * @return array<int, string> plan id => name
 */
function fn_euph_profile_types_get_vendor_plans(): array
{
    $db = fn_euph_profile_types_container()->db();
    if (Registry::get('addons.vendor_plans.status') !== 'A' || !$db->tableExists('?:vendor_plans')) {
        return [];
    }
    $hasDescriptions = $db->tableExists('?:vendor_plan_descriptions');
    $rows = $hasDescriptions
        ? $db->rows(
            'SELECT p.plan_id, d.plan FROM ?:vendor_plans AS p'
            . ' LEFT JOIN ?:vendor_plan_descriptions AS d ON d.plan_id = p.plan_id AND d.lang_code = ?s'
            . ' ORDER BY p.position ASC, p.plan_id ASC',
            fn_euph_profile_types_descr_lang(),
        )
        : $db->rows('SELECT plan_id FROM ?:vendor_plans ORDER BY plan_id ASC');
    $plans = [];
    foreach ($rows as $row) {
        $planId = Cast::int($row['plan_id'] ?? 0);
        if ($planId > 0) {
            $name = Cast::string($row['plan'] ?? '');
            $plans[$planId] = $name !== '' ? $name : '#' . $planId;
        }
    }

    return $plans;
}
