<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\Activation;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Addons\EuphProfileTypes\Service\TypeChangeGuard;
use Tygh\Addons\EuphProfileTypes\Service\UsergroupDiff;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Moves a user from the user groups of one type to those of another.
 * A group both types grant is left as it is; the groups the user leaves
 * become "available" again, the new ones get the status of the
 * "Group status after changing profile type" setting.
 *
 * @param list<int> $from
 * @param list<int> $to
 */
function fn_euph_profile_types_sync_usergroups(int $userId, array $from, array $to, string $status): void
{
    if ($userId <= 0 || !function_exists('fn_change_usergroup_status')) {
        return;
    }
    $diff = UsergroupDiff::between($from, $to);
    foreach ($diff['remove'] as $usergroupId) {
        fn_change_usergroup_status('F', $userId, $usergroupId, []);
    }
    foreach ($diff['add'] as $usergroupId) {
        fn_change_usergroup_status($status, $userId, $usergroupId, []);
    }
}

/**
 * A new storefront account is activated at once or waits for approval as
 * its type says: the store setting "Administrator must activate new user
 * accounts" is overridden for this request only.
 */
function fn_euph_profile_types_apply_activation(Activation $activation): void
{
    if ($activation === Activation::StoreSetting) {
        return;
    }
    $value = $activation === Activation::Approval ? 'Y' : 'N';
    if (Registry::get('settings.General.approve_user_profiles') !== null) {
        Registry::set('settings.General.approve_user_profiles', $value);

        return;
    }
    foreach (Cast::array(Registry::get('settings')) as $section => $values) {
        if (is_array($values) && array_key_exists('approve_user_profiles', $values)) {
            Registry::set('settings.' . $section . '.approve_user_profiles', $value);

            return;
        }
    }
}

/**
 * Keeps the values of the fields the form did not send. A customer sees only
 * the fields of their type: saving the profile must not wipe what they typed
 * into the fields of another type, so the value survives a change of type.
 *
 * @param array<array-key, mixed> $userData
 */
function fn_euph_profile_types_keep_unsent_values(int $userId, array &$userData): void
{
    if ($userId <= 0 || !is_array($userData['fields'] ?? null) || !function_exists('fn_get_user_info')) {
        return;
    }
    $profileId = Cast::int($userData['profile_id'] ?? 0);
    $stored = Cast::array(Cast::array(fn_get_user_info($userId, true, $profileId))['fields'] ?? []);
    $sent = $userData['fields'];
    foreach ($stored as $fieldId => $value) {
        if (!array_key_exists($fieldId, $sent)) {
            $sent[$fieldId] = $value;
        }
    }
    $userData['fields'] = $sent;
}

/**
 * "Let customers sign in only on the storefronts of their profile type".
 * Administrators and vendors sign in as usual.
 *
 * @param array<string, mixed> $user a ?:users row
 */
function fn_euph_profile_types_may_sign_in_here(array $user): bool
{
    $container = fn_euph_profile_types_container();
    if (!$container->settings()->restrictLogin
        || !$container->catalog()->isEnabled(UserKind::Customer)
        || Cast::string($user['user_type'] ?? '') !== UserKind::Customer->value
    ) {
        return true;
    }
    $storefrontId = fn_euph_profile_types_storefront_id();
    $type = $container->catalog()->byCode(Cast::string($user['euph_profile_type'] ?? ''), fn_euph_profile_types_lang());

    return $storefrontId <= 0 || $type === null || $type->worksOn($storefrontId);
}

/**
 * Whether the account a login form names may sign in here. An e-mail shared
 * by several accounts (stores that do not share customers) passes when one
 * of them may.
 */
function fn_euph_profile_types_login_allowed(string $login): bool
{
    $login = trim($login);
    if ($login === '') {
        return true;
    }
    $users = fn_euph_profile_types_container()->db()->rows(
        'SELECT user_id, user_type, euph_profile_type FROM ?:users WHERE email = ?s OR user_login = ?s',
        $login,
        $login,
    );
    if ($users === []) {
        return true;
    }
    foreach ($users as $user) {
        if (fn_euph_profile_types_may_sign_in_here($user)) {
            return true;
        }
    }

    return false;
}

function fn_euph_profile_types_login_denied_message(): string
{
    return fn_euph_profile_types_text(
        'euph_profile_types.login_denied_on_storefront',
        'Your account cannot sign in to this store. Please sign in on the store where you registered.',
    );
}

/**
 * The type a user had when the save in progress began: update_user_pre keeps
 * it ($code given), update_profile takes it back. CS-Cart hands update_profile
 * the user as read back from the database, not the array update_user_pre
 * changed, so it cannot travel in that array.
 */
function fn_euph_profile_types_previous_type(int $userId, ?string $code = null): ?string
{
    /** @var array<int, string> $previous */
    static $previous = [];
    if ($code !== null) {
        $previous[$userId] = $code;

        return $code;
    }
    $kept = $previous[$userId] ?? null;
    unset($previous[$userId]);

    return $kept;
}

/**
 * Before a user is saved (hook update_user_pre): the type the form sent is
 * checked and kept only when the user may have it; a new account without
 * one gets the type of the cart or the default; a new storefront account is
 * activated as its type says; values of fields the form did not show are kept.
 *
 * @param array<array-key, mixed> $userData
 */
function fn_euph_profile_types_prepare_user_data(int $userId, array &$userData): void
{
    $container = fn_euph_profile_types_container();
    $catalog = $container->catalog();
    $storefront = fn_euph_profile_types_is_storefront();
    $current = fn_euph_profile_types_user_row($userId);
    $userType = Cast::string($userData['user_type'] ?? ($current['user_type'] ?? UserKind::Customer->value));
    $posted = array_key_exists('euph_profile_type', $userData)
        ? CodeValidator::normalize(Cast::string($userData['euph_profile_type']))
        : '';
    unset($userData['euph_profile_type'], $userData['euph_profile_type_changes']);
    if ($userType !== UserKind::Customer->value || !$catalog->isEnabled(UserKind::Customer)) {
        // Vendor administrators get the type of their company (update_profile).
        return;
    }

    $langCode = fn_euph_profile_types_lang();
    $currentCode = Cast::string($current['euph_profile_type'] ?? '');
    $changes = Cast::int($current['euph_profile_type_changes'] ?? 0);
    if ($userId > 0) {
        fn_euph_profile_types_previous_type($userId, $currentCode);
    }

    $type = null;
    if ($posted !== '') {
        $candidate = $storefront
            ? $catalog->assignableOnStorefront(UserKind::Customer, $posted, fn_euph_profile_types_storefront_id(), $langCode, fn_euph_profile_types_unlocked_codes())
            : $catalog->assignableByAdmin(UserKind::Customer, $posted, $langCode);
        $allowed = $candidate !== null
            && (!$storefront || $userId <= 0 || TypeChangeGuard::allows($container->settings()->typeChange, $currentCode, $candidate->code, $changes));
        $type = $allowed ? $candidate : null;
    }

    if ($type !== null) {
        $userData['euph_profile_type'] = $type->code;
        if ($storefront && $userId > 0 && $currentCode !== '' && strcasecmp($currentCode, $type->code) !== 0) {
            $userData['euph_profile_type_changes'] = $changes + 1;
        }
    } elseif ($userId <= 0) {
        $type = $storefront
            ? fn_euph_profile_types_current_customer_type()
            : $catalog->defaultType(UserKind::Customer, 0, $langCode);
        if ($type !== null) {
            $userData['euph_profile_type'] = $type->code;
        }
    }

    if ($userId <= 0 && $storefront && $type !== null) {
        fn_euph_profile_types_apply_activation($type->activation);
    }
    fn_euph_profile_types_keep_unsent_values($userId, $userData);
}

/**
 * After a user is saved (hook update_profile): a customer without a type gets
 * the default one, a customer whose type changed moves to the groups of the
 * new type, a vendor administrator gets the type of the company.
 *
 * @param array<array-key, mixed> $userData
 */
function fn_euph_profile_types_after_profile_saved(string $action, array $userData): void
{
    $userId = Cast::int($userData['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    $container = fn_euph_profile_types_container();
    $catalog = $container->catalog();
    $db = $container->db();
    $langCode = fn_euph_profile_types_lang();
    $user = fn_euph_profile_types_user_row($userId);
    $userType = Cast::string($user['user_type'] ?? '');

    if ($userType === UserKind::Customer->value && $catalog->isEnabled(UserKind::Customer)) {
        $code = Cast::string($user['euph_profile_type'] ?? '');
        if ($code === '') {
            $default = $catalog->defaultType(UserKind::Customer, fn_euph_profile_types_storefront_id(), $langCode);
            if ($default === null) {
                return;
            }
            $code = $default->code;
            $db->execute('UPDATE ?:users SET euph_profile_type = ?s WHERE user_id = ?i', $code, $userId);
        }
        $previous = $action === 'add'
            ? ''
            : CodeValidator::normalize(fn_euph_profile_types_previous_type($userId) ?? $code);
        if (strcasecmp($previous, $code) !== 0) {
            $old = $previous !== '' ? $catalog->byCode($previous, $langCode) : null;
            $new = $catalog->byCode($code, $langCode);
            fn_euph_profile_types_sync_usergroups(
                $userId,
                $old !== null ? $old->usergroupIds : [],
                $new !== null ? $new->usergroupIds : [],
                $container->settings()->groupStatus,
            );
        }
        if ($action === 'add' && fn_euph_profile_types_is_storefront()) {
            fn_euph_profile_types_forget_form_type(UserKind::Customer);
        }
        $user_data = $userData;

        /**
         * Saving a profile with a type.
         *
         * @param string               $action    add or update
         * @param array<string, mixed> $user_data the saved data
         * @param string               $code      the type the customer has now
         * @param string               $previous  the type before ('' for a new account)
         */
        fn_set_hook('euph_profile_types_update_profile', $action, $user_data, $code, $previous);

        return;
    }

    if ($userType === UserKind::Vendor->value && $catalog->isEnabled(UserKind::Vendor)) {
        $companyCode = fn_euph_profile_types_company_code(Cast::int($user['company_id'] ?? 0));
        if ($companyCode === '' || $companyCode === Cast::string($user['euph_profile_type'] ?? '')) {
            return;
        }
        $db->execute('UPDATE ?:users SET euph_profile_type = ?s WHERE user_id = ?i', $companyCode, $userId);
        if ($action === 'add') {
            $new = $catalog->byCode($companyCode, $langCode);
            fn_euph_profile_types_sync_usergroups($userId, [], $new !== null ? $new->usergroupIds : [], $container->settings()->groupStatus);
        }
    }
}

/**
 * The registration form remembers the type posted last (it comes back after
 * an error) and the type a locked link fixed; a successful registration
 * forgets both.
 */
function fn_euph_profile_types_remember_form_type(UserKind $kind, string $code): void
{
    $posted = fn_euph_profile_types_session_get('euph_profile_types_posted');
    $posted[$kind->value] = CodeValidator::normalize($code);
    fn_euph_profile_types_session_put('euph_profile_types_posted', $posted);
}

function fn_euph_profile_types_forget_form_type(UserKind $kind): void
{
    foreach (['euph_profile_types_posted', 'euph_profile_types_lock'] as $key) {
        $stored = fn_euph_profile_types_session_get($key);
        unset($stored[$kind->value]);
        fn_euph_profile_types_session_put($key, $stored);
    }
}

/**
 * Signs out a customer whose type does not work on this storefront, whatever
 * way they signed in. Returns whether the customer was signed out.
 */
function fn_euph_profile_types_enforce_storefront_login(): bool
{
    if (!fn_euph_profile_types_container()->settings()->restrictLogin || !function_exists('fn_user_logout')) {
        return false;
    }
    $userId = fn_euph_profile_types_signed_in_customer_id();
    if ($userId <= 0) {
        return false;
    }
    $checked = $userId . ':' . fn_euph_profile_types_storefront_id();
    if (Cast::string(fn_euph_profile_types_session_value('euph_profile_types_login_ok')) === $checked) {
        return false;
    }
    if (fn_euph_profile_types_may_sign_in_here(fn_euph_profile_types_user_row($userId))) {
        fn_euph_profile_types_session_put('euph_profile_types_login_ok', $checked);

        return false;
    }
    fn_user_logout(fn_euph_profile_types_auth());
    fn_euph_profile_types_notify('E', fn_euph_profile_types_login_denied_message());

    return true;
}
