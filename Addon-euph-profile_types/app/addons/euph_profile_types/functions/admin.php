<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Model\FieldRuleSet;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * The two places the administrator ticks the per-type checkboxes:
 *  - the "Profile types" block of a profile field card (a row per type);
 *  - the "Fields" tab of a type card (a row per field).
 * Both show what each type sees now: a field no type governs shows its
 * general checkboxes. Every row carries the checkboxes it was drawn with
 * (its baseline), so an untouched row changes nothing and a field stays on
 * its general checkboxes until somebody ticks differently for a type.
 */

/**
 * What a type sees of a field now.
 *
 * @param array<array-key, mixed> $field a ?:profile_fields row
 */
function fn_euph_profile_types_effective_rule(FieldRuleSet $rules, int $typeId, int $fieldId, array $field): FieldRule
{
    if (!$rules->isGoverned($fieldId)) {
        return FieldRule::fromGeneralFlags($typeId, $fieldId, $field);
    }

    return $rules->ruleFor($fieldId, $typeId) ?? new FieldRule($typeId, $fieldId);
}

/**
 * @param list<int> $fieldIds
 *
 * @return array<int, array<string, mixed>> field id => ?:profile_fields row
 */
function fn_euph_profile_types_field_rows(array $fieldIds): array
{
    $fieldIds = Cast::idList($fieldIds);
    if ($fieldIds === []) {
        return [];
    }
    $rows = [];
    foreach (fn_euph_profile_types_container()->db()->rows('SELECT * FROM ?:profile_fields WHERE field_id IN (?n)', $fieldIds) as $row) {
        $rows[Cast::int($row['field_id'] ?? 0)] = $row;
    }

    return $rows;
}

/**
 * A field and its billing/shipping twin: they always work alike.
 *
 * @param list<int> $fieldIds
 *
 * @return list<int>
 */
function fn_euph_profile_types_with_twins(array $fieldIds): array
{
    if ($fieldIds === []) {
        return [];
    }
    $twins = fn_euph_profile_types_container()->db()->column(
        'SELECT field_id FROM ?:profile_fields WHERE matching_id IN (?n)'
        . ' UNION SELECT matching_id FROM ?:profile_fields WHERE field_id IN (?n) AND matching_id > 0',
        $fieldIds,
        $fieldIds,
    );

    return Cast::idList(array_merge($fieldIds, $twins));
}

/**
 * The "Profile types" block of a profile field card.
 *
 * @param array<array-key, mixed> $field the field being edited ([] for a new one)
 *
 * @return array{kind: string, governed: bool, types: list<array<string, mixed>>}|null
 */
function fn_euph_profile_types_field_card(array $field, string $profileType): ?array
{
    $kind = UserKind::fromFieldProfileType($profileType);
    $container = fn_euph_profile_types_container();
    if ($kind === null || !$container->catalog()->isEnabled($kind)) {
        return null;
    }
    $fieldId = Cast::int($field['field_id'] ?? 0);
    $rules = $container->rules()->all();
    $types = [];
    foreach ($container->types()->ofKind($kind, fn_euph_profile_types_descr_lang()) as $type) {
        if ($fieldId > 0) {
            $rule = fn_euph_profile_types_effective_rule($rules, $type->id, $fieldId, $field);
            $row = $rule->toRow();
            $baseline = $rule->signature();
        } else {
            // A new field: nothing ticked, the general checkboxes will apply.
            $row = array_fill_keys(FieldRule::FLAGS, 'N');
            $baseline = str_repeat('N', count(FieldRule::FLAGS));
        }
        $types[] = $type->toArray() + ['rule' => $row, 'baseline' => $baseline];
    }

    return [
        'kind' => $kind->value,
        'governed' => $fieldId > 0 && $rules->isGoverned($fieldId),
        'types' => $types,
    ];
}

/**
 * Saves the "Profile types" block of a field card, after CS-Cart saved the
 * field: $fieldId for an existing field, else the fields created by this
 * request (ids above $maxIdBefore: a billing/shipping field is two fields).
 *
 * @param array<array-key, mixed> $posted type id => checkboxes + baseline
 */
function fn_euph_profile_types_save_field_card(array $posted, int $fieldId, int $maxIdBefore): void
{
    $container = fn_euph_profile_types_container();
    $fieldIds = $fieldId > 0
        ? [$fieldId]
        : Cast::idList($container->db()->column('SELECT field_id FROM ?:profile_fields WHERE field_id > ?i', $maxIdBefore));
    $fieldIds = fn_euph_profile_types_with_twins($fieldIds);
    if ($fieldIds === []) {
        return;
    }
    $fields = fn_euph_profile_types_field_rows($fieldIds);
    $first = $fields[$fieldIds[0]] ?? [];
    $kind = UserKind::fromFieldProfileType(Cast::string($first['profile_type'] ?? ''));
    if ($kind === null) {
        return;
    }
    $changed = false;
    $rules = [];
    foreach ($container->types()->ofKind($kind, fn_euph_profile_types_descr_lang()) as $type) {
        $row = Cast::array($posted[$type->id] ?? []);
        $rule = FieldRule::fromFlags($type->id, $fieldIds[0], $row);
        $changed = $changed || $rule->signature() !== Cast::string($row['baseline'] ?? '');
        $rules[$type->id] = $rule;
    }
    if ($changed) {
        $container->rules()->replaceForFields($fieldIds, $rules);
    }
}

/**
 * The "Fields" tab of a type card: every field of the kind, by section; a
 * shipping field that has a billing twin rides on the twin's row.
 *
 * @return list<array{section: string, fields: list<array<string, mixed>>}>
 */
function fn_euph_profile_types_type_field_table(ProfileType $type): array
{
    $container = fn_euph_profile_types_container();
    $rules = $container->rules()->all();
    $all = Cast::array(fn_get_profile_fields(
        'ALL',
        [],
        fn_euph_profile_types_descr_lang(),
        ['profile_type' => $type->kind->fieldProfileType(), 'euph_skip' => true],
    ));
    $billingIds = array_map(static fn (mixed $id): int => Cast::int($id), array_keys(Cast::array($all['B'] ?? [])));

    $table = [];
    foreach (['C', 'B', 'S'] as $section) {
        $rows = [];
        foreach (Cast::array($all[$section] ?? []) as $field) {
            $field = Cast::array($field);
            $fieldId = Cast::int($field['field_id'] ?? 0);
            if ($fieldId <= 0 || ($section === 'S' && in_array(Cast::int($field['matching_id'] ?? 0), $billingIds, true))) {
                continue;
            }
            $rule = fn_euph_profile_types_effective_rule($rules, $type->id, $fieldId, $field);
            $rows[] = [
                'field_id' => $fieldId,
                'description' => Cast::string($field['description'] ?? ''),
                'field_name' => Cast::string($field['field_name'] ?? ''),
                'twin' => $section === 'B' && Cast::int($field['matching_id'] ?? 0) > 0,
                'governed' => $rules->isGoverned($fieldId),
                'rule' => $rule->toRow(),
                'baseline' => $rule->signature(),
            ];
        }
        if ($rows !== []) {
            $table[] = ['section' => $section, 'fields' => $rows];
        }
    }

    return $table;
}

/**
 * Saves the "Fields" tab of a type card. Returns how many fields changed.
 *
 * @param array<array-key, mixed> $posted field id => checkboxes + baseline
 */
function fn_euph_profile_types_save_type_fields(int $typeId, array $posted): int
{
    $container = fn_euph_profile_types_container();
    $type = $container->types()->find($typeId, fn_euph_profile_types_descr_lang());
    if ($type === null) {
        return 0;
    }
    $fieldIds = Cast::idList(array_keys($posted));
    $fields = fn_euph_profile_types_field_rows(fn_euph_profile_types_with_twins($fieldIds));
    $changed = [];
    foreach ($fieldIds as $fieldId) {
        $row = Cast::array($posted[$fieldId] ?? []);
        $field = $fields[$fieldId] ?? null;
        if ($field === null || Cast::string($field['profile_type'] ?? '') !== $type->kind->fieldProfileType()) {
            continue;
        }
        $rule = FieldRule::fromFlags($typeId, $fieldId, $row);
        if ($rule->signature() === Cast::string($row['baseline'] ?? '')) {
            continue;
        }
        $changed[$fieldId] = $rule;
        $twinId = Cast::int($field['matching_id'] ?? 0);
        if ($twinId > 0 && isset($fields[$twinId])) {
            $changed[$twinId] = $rule->withFieldId($twinId);
        }
    }
    if ($changed === []) {
        return 0;
    }
    $others = array_map(
        static fn (ProfileType $other): int => $other->id,
        array_filter($container->types()->ofKind($type->kind, fn_euph_profile_types_descr_lang()), static fn (ProfileType $other): bool => $other->id !== $typeId),
    );
    $container->rules()->replaceForType($typeId, $changed, array_values($others), $fields);

    return count($changed);
}

/**
 * User groups a type of the kind can grant.
 *
 * @return array<int, string> usergroup id => name
 */
function fn_euph_profile_types_usergroups(UserKind $kind): array
{
    if (!function_exists('fn_get_usergroups')) {
        return [];
    }
    $groups = [];
    $found = Cast::array(fn_get_usergroups(['type' => $kind->value, 'status' => ['A', 'H']], fn_euph_profile_types_descr_lang()));
    foreach ($found as $id => $group) {
        $group = Cast::array($group);
        $usergroupId = Cast::int($group['usergroup_id'] ?? $id);
        if ($usergroupId > 0) {
            $groups[$usergroupId] = Cast::string($group['usergroup'] ?? ('#' . $usergroupId));
        }
    }

    return $groups;
}

/**
 * Registration links of a type ("Become a dealer" buttons, mailings).
 *
 * @return array{open: string, locked: string}
 */
function fn_euph_profile_types_registration_links(ProfileType $type): array
{
    $dispatch = $type->kind === UserKind::Vendor ? 'companies.apply_for_vendor' : 'profiles.add';
    $url = 'index.php?dispatch=' . $dispatch . '&euph_profile_type=' . rawurlencode($type->code);

    return [
        'open' => $url,
        'locked' => $url . '&euph_profile_type_locked=1',
    ];
}

/**
 * The add-on's items of the admin menu (schemas/menu/menu.post.php).
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_menu(mixed $central, bool $multiVendor): array
{
    $central = Cast::array($central);
    $sections = ['customers' => ['euph_customer_profile_types', 'C']];
    if ($multiVendor) {
        $sections['vendors'] = ['euph_vendor_profile_types', 'V'];
    }
    foreach ($sections as $section => [$item, $userType]) {
        $menu = Cast::array($central[$section] ?? null);
        if (!is_array($menu['items'] ?? null)) {
            continue;
        }
        $items = $menu['items'];
        $items[$item] = [
            'attrs' => ['class' => 'is-addon'],
            'href' => 'euph_profile_types.manage?user_type=' . $userType,
            'position' => 850,
        ];
        $menu['items'] = $items;
        $central[$section] = $menu;
    }

    return $central;
}

/**
 * The status popup of the types list goes through CS-Cart's
 * tools.update_status: changing a type's status needs the add-on's
 * "manage" privilege (schemas/permissions/admin.post.php).
 *
 * @param array<array-key, mixed> $schema
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_status_permission(array $schema): array
{
    $tools = Cast::array($schema['tools'] ?? []);
    $modes = Cast::array($tools['modes'] ?? []);
    $updateStatus = Cast::array($modes['update_status'] ?? []);
    $paramPermissions = Cast::array($updateStatus['param_permissions'] ?? []);
    $tables = Cast::array($paramPermissions['table'] ?? []);
    $tables['euph_profile_types'] = 'manage_euph_profile_types';
    $paramPermissions['table'] = $tables;
    $updateStatus['param_permissions'] = $paramPermissions;
    $modes['update_status'] = $updateStatus;
    $tools['modes'] = $modes;
    $schema['tools'] = $tools;

    return $schema;
}
