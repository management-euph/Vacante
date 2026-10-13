<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\TypeStatus;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Integration points for other add-ons and custom code. Hooks fired here:
 *   euph_update_profile_type_pre ($type_data, $type_id, $lang_code)
 *   euph_update_profile_type_post ($type_data, $type_id, $lang_code)
 *   euph_delete_profile_type ($type_id, $type_code)
 *   euph_get_profile_type_data_post ($type_id, $lang_code, $type_data)
 *   euph_profile_types_update_profile ($action, $user_data, $type_code, $previous_type_code), see hooks/user_hooks.php
 */

/**
 * The type of the current customer, a guest at checkout included. '' when
 * customer types are off.
 */
function fn_euph_profile_types_get_current_customer_type(): string
{
    $type = fn_euph_profile_types_current_customer_type();

    return $type !== null ? $type->code : '';
}

/**
 * Validates a type code: an existing type of the user type (C customers,
 * V vendors) that can be given to a user.
 */
function fn_euph_profile_types_is_valid_type_code(mixed $code, mixed $user_type = 'C'): bool
{
    $kind = UserKind::tryFrom(Cast::string($user_type));
    if ($kind === null) {
        return false;
    }
    $code = CodeValidator::normalize(Cast::string($code));

    return CodeValidator::isWellFormed($code)
        && fn_euph_profile_types_container()->catalog()->assignableByAdmin($kind, $code, fn_euph_profile_types_lang()) !== null;
}

/**
 * The types available to a user on this storefront: the ones offered in the
 * choice, plus $current_code when given (the type the user already has).
 *
 * @return array<string, string> code => name
 */
function fn_euph_profile_types_get_selectable_types(mixed $user_type = 'C', mixed $current_code = ''): array
{
    $kind = UserKind::tryFrom(Cast::string($user_type)) ?? UserKind::Customer;
    $catalog = fn_euph_profile_types_container()->catalog();
    if (!$catalog->isEnabled($kind)) {
        return [];
    }
    $langCode = fn_euph_profile_types_lang();
    $types = $catalog->selectable($kind, fn_euph_profile_types_storefront_id(), $langCode);
    $current = $catalog->byCode(CodeValidator::normalize(Cast::string($current_code)), $langCode);
    if ($current !== null && $current->kind === $kind) {
        $types[] = $current;
    }

    return \Tygh\Addons\EuphProfileTypes\Service\TypeCatalog::options($types);
}

/**
 * Whether a type works on a storefront (0: the storefront of the request).
 */
function fn_euph_profile_types_is_code_on_storefront(mixed $code, mixed $storefront_id = 0): bool
{
    $storefrontId = Cast::int($storefront_id);

    return fn_euph_profile_types_container()->catalog()->isCodeOnStorefront(
        CodeValidator::normalize(Cast::string($code)),
        $storefrontId > 0 ? $storefrontId : fn_euph_profile_types_storefront_id(),
        fn_euph_profile_types_lang(),
    );
}

/**
 * The list of types with their descriptions.
 *
 * Params: user_type (C|V), status (A|H|D or a list), code, storefront_id
 * (only the types that work there), lang_code.
 *
 * @param array<array-key, mixed>|mixed $params
 *
 * @return array<int, array<string, mixed>> type id => type
 */
function fn_euph_get_profile_types(mixed $params = []): array
{
    $params = Cast::array($params);
    $langCode = Cast::string($params['lang_code'] ?? '') ?: fn_euph_profile_types_lang();
    $kind = UserKind::tryFrom(Cast::string($params['user_type'] ?? ''));
    $statuses = array_map(static fn (mixed $status): string => Cast::string($status), (array) ($params['status'] ?? []));
    $code = CodeValidator::normalize(Cast::string($params['code'] ?? ''));
    $storefrontId = Cast::int($params['storefront_id'] ?? 0);

    $types = [];
    foreach (fn_euph_profile_types_container()->types()->all($langCode) as $type) {
        if (($kind !== null && $type->kind !== $kind)
            || ($statuses !== [] && !in_array($type->status->value, $statuses, true))
            || ($code !== '' && $type->code !== $code)
            || ($storefrontId > 0 && !$type->worksOn($storefrontId))
        ) {
            continue;
        }
        $types[$type->id] = $type->toArray();
    }

    return $types;
}

/**
 * The data of a type, with its links. [] when there is no such type.
 *
 * @return array<string, mixed>
 */
function fn_euph_get_profile_type_data(mixed $type_id, mixed $lang_code = ''): array
{
    $typeId = Cast::int($type_id);
    $langCode = Cast::string($lang_code) ?: fn_euph_profile_types_descr_lang();
    $type = fn_euph_profile_types_container()->types()->find($typeId, $langCode);
    $type_data = $type !== null ? $type->toArray() : [];

    /**
     * Reading the data of a profile type.
     *
     * @param int                  $typeId    type id
     * @param string               $langCode  language of the name and description
     * @param array<string, mixed> $type_data the type ([] when there is none)
     */
    fn_set_hook('euph_get_profile_type_data_post', $typeId, $langCode, $type_data);

    return Cast::stringMap($type_data);
}

/**
 * Creates (type_id 0) or updates a type from type card data.
 *
 * @param array<array-key, mixed>|mixed $type_data name, description, code, status, position,
 *                                                 is_default, activation, user_type (new types),
 *                                                 usergroup_ids, storefront_ids, vendor_plan_ids
 *
 * @return int the type id, 0 when it was not saved (the reasons are notified)
 */
function fn_euph_update_profile_type(mixed $type_id, mixed $type_data, mixed $lang_code = ''): int
{
    $typeId = Cast::int($type_id);
    $type_data = Cast::array($type_data);
    $langCode = Cast::string($lang_code) ?: fn_euph_profile_types_descr_lang();

    /**
     * Saving a profile type: before.
     *
     * @param array<array-key, mixed> $type_data the posted data
     * @param int                     $typeId    0 for a new type
     * @param string                  $langCode  language of the name and description
     */
    fn_set_hook('euph_update_profile_type_pre', $type_data, $typeId, $langCode);

    $result = fn_euph_profile_types_container()->admin()->save($typeId, $type_data, $langCode, fn_euph_profile_types_lang_codes());
    foreach ($result['errors'] as $error) {
        fn_euph_profile_types_notify('E', fn_euph_profile_types_text($error, $error));
    }
    if ($result['type_id'] <= 0) {
        return 0;
    }
    $typeId = $result['type_id'];

    /**
     * Saving a profile type: after.
     *
     * @param array<array-key, mixed> $type_data the posted data
     * @param int                     $typeId    the saved type
     * @param string                  $langCode  language of the name and description
     */
    fn_set_hook('euph_update_profile_type_post', $type_data, $typeId, $langCode);

    return $typeId;
}

/**
 * Deletes a type: its customers or vendors move to the standard type and
 * the groups of the deleted type, to the standard type's. The standard
 * types cannot be deleted.
 */
function fn_euph_delete_profile_type(mixed $type_id): bool
{
    $typeId = Cast::int($type_id);
    $container = fn_euph_profile_types_container();
    $result = $container->admin()->delete($typeId, fn_euph_profile_types_descr_lang());
    foreach ($result['errors'] as $error) {
        fn_euph_profile_types_notify('E', fn_euph_profile_types_text($error, $error));
    }
    $type = $result['type'];
    if ($result['errors'] !== [] || $type === null) {
        return false;
    }
    $to = $result['standard'] !== null ? $result['standard']->usergroupIds : [];
    foreach ($result['moved_user_ids'] as $userId) {
        fn_euph_profile_types_sync_usergroups($userId, $type->usergroupIds, $to, $container->settings()->groupStatus);
    }
    $typeCode = $type->code;

    /**
     * Deleting a profile type (after it is gone).
     *
     * @param int    $typeId   the deleted type
     * @param string $typeCode its code
     */
    fn_set_hook('euph_delete_profile_type', $typeId, $typeCode);

    return true;
}

/** The name of a type, for templates; the code when the type is gone. */
function fn_euph_profile_types_get_type_name(mixed $code, mixed $lang_code = ''): string
{
    $code = CodeValidator::normalize(Cast::string($code));
    if ($code === '') {
        return '';
    }
    $type = fn_euph_profile_types_container()->types()->findByCode($code, Cast::string($lang_code) ?: fn_euph_profile_types_lang());

    return $type !== null ? $type->displayName() : $code;
}

/**
 * Types of a kind in the admin panel's choice (all but the disabled ones,
 * plus $current_code).
 *
 * @return array<string, string> code => name
 */
function fn_euph_profile_types_admin_options(UserKind $kind, string $currentCode = ''): array
{
    $options = [];
    foreach (fn_euph_profile_types_container()->types()->ofKind($kind, fn_euph_profile_types_lang()) as $type) {
        if ($type->status !== TypeStatus::Disabled || strcasecmp($type->code, $currentCode) === 0) {
            $options[$type->code] = $type->displayName();
        }
    }

    return $options;
}

/**
 * @param list<ProfileType> $types
 *
 * @return list<array<string, mixed>>
 */
function fn_euph_profile_types_to_arrays(array $types): array
{
    return array_map(static fn (ProfileType $type): array => $type->toArray(), $types);
}
