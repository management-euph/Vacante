<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldContext;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Addons\EuphProfileTypes\Service\TypeCatalog;
use Tygh\Addons\EuphProfileTypes\Service\TypeChangeGuard;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Which type a profile field list is read for.
 *
 * In order:
 *  1. the add-on's own reads say it in the params (euph_profile_type, euph_area);
 *  2. a controller of the add-on set a runtime context for the lists CS-Cart
 *     reads later in the request, in templates (an order, a vendor card);
 *  3. on the storefront, the page tells: registration, the customer account,
 *     the checkout, the vendor application.
 * Anything else, including every admin page the add-on did not set a
 * context for, is left to CS-Cart.
 */

/**
 * @param array{kind: string, code: string, area: string, choice?: string}|null $context
 *        choice "display": the type is shown as a field of the list (an order);
 *        "admin": every type the administrator may give is offered
 */
function fn_euph_profile_types_set_field_context(?array $context): void
{
    Registry::set('runtime.euph_profile_types.field_context', $context);
}

/**
 * @return array<string, mixed>|null
 */
function fn_euph_profile_types_get_field_context(): ?array
{
    $context = Registry::get('runtime.euph_profile_types.field_context');

    return is_array($context) ? Cast::stringMap($context) : null;
}

/**
 * @param array<array-key, mixed> $params fn_get_profile_fields() params
 */
function fn_euph_profile_types_resolve_field_context(string $location, array $params): ?FieldContext
{
    $kind = UserKind::fromFieldProfileType(Cast::string($params['profile_type'] ?? 'U'));
    if ($kind === null) {
        return null;
    }
    $container = fn_euph_profile_types_container();
    if (!$container->catalog()->isEnabled($kind)) {
        return null;
    }
    $langCode = fn_euph_profile_types_lang();

    $area = FieldArea::tryFrom(Cast::string($params['euph_area'] ?? ''));
    if ($area !== null) {
        $type = fn_euph_profile_types_type_or_default($kind, Cast::string($params['euph_profile_type'] ?? ''), $langCode);
        if ($type === null) {
            return null;
        }
        $choice = FieldContext::choiceFromParams(['choice' => $params['euph_choice'] ?? null]);

        return new FieldContext($kind, $type, $area, $choice);
    }

    $runtime = fn_euph_profile_types_get_field_context();
    if ($runtime !== null && Cast::string($runtime['kind'] ?? '') === $kind->value) {
        $area = FieldArea::tryFrom(Cast::string($runtime['area'] ?? '')) ?? FieldArea::Checkout;
        $type = fn_euph_profile_types_type_or_default($kind, Cast::string($runtime['code'] ?? ''), $langCode);
        if ($type === null) {
            return null;
        }
        $choice = match (Cast::string($runtime['choice'] ?? '')) {
            'display' => [$type->code => $type->displayName()],
            'admin' => fn_euph_profile_types_admin_options($kind, $type->code),
            default => null,
        };

        return new FieldContext($kind, $type, $area, $choice);
    }

    if (!fn_euph_profile_types_is_storefront() || $location === 'ALL' || $location === 'I') {
        return null;
    }

    return fn_euph_profile_types_storefront_context($kind, $location);
}

/**
 * The type of a code, any status (a disabled type still applies to whoever
 * has it), else the default type of the kind.
 */
function fn_euph_profile_types_type_or_default(UserKind $kind, string $code, string $langCode): ?ProfileType
{
    $catalog = fn_euph_profile_types_container()->catalog();
    $code = CodeValidator::normalize($code);
    if ($code !== '') {
        $type = $catalog->byCode($code, $langCode);
        if ($type !== null && $type->kind === $kind) {
            return $type;
        }
    }

    return $catalog->defaultType($kind, fn_euph_profile_types_storefront_id(), $langCode);
}

/**
 * The context of the storefront page being drawn. Remembered for the
 * request: the page reads the field list several times and the controller
 * of the add-on asks for it again to preselect the choice.
 */
function fn_euph_profile_types_storefront_context(UserKind $kind, string $location = 'C'): ?FieldContext
{
    static $memo;
    if (!is_array($memo)) {
        $memo = [];
    }
    $controller = Cast::string(Registry::get('runtime.controller'));
    $mode = Cast::string(Registry::get('runtime.mode'));
    $key = implode('|', [$kind->value, $location === 'O' ? 'O' : 'C', $controller, $mode]);
    if (!array_key_exists($key, $memo)) {
        $memo[$key] = $kind === UserKind::Customer
            ? fn_euph_profile_types_customer_storefront_context($location, $controller, $mode)
            : fn_euph_profile_types_vendor_storefront_context($controller, $mode);
    }

    return $memo[$key] instanceof FieldContext ? $memo[$key] : null;
}

function fn_euph_profile_types_customer_storefront_context(string $location, string $controller, string $mode): ?FieldContext
{
    $container = fn_euph_profile_types_container();
    $catalog = $container->catalog();
    $settings = $container->settings();
    $langCode = fn_euph_profile_types_lang();
    $storefrontId = fn_euph_profile_types_storefront_id();
    $signedIn = fn_euph_profile_types_signed_in_customer_id() > 0;

    if ($location === 'O' || $controller === 'checkout') {
        $type = fn_euph_profile_types_current_customer_type();
        if ($type === null) {
            return null;
        }
        $choice = $settings->checkoutChoice->offeredTo($signedIn)
            ? fn_euph_profile_types_choice_options($catalog->selectable(UserKind::Customer, $storefrontId, $langCode), $type)
            : null;

        return new FieldContext(UserKind::Customer, $type, FieldArea::Checkout, $choice);
    }

    if ($controller === 'profiles' && ($mode === 'add' || !$signedIn)) {
        return fn_euph_profile_types_registration_context(UserKind::Customer);
    }
    if (!$signedIn) {
        return null;
    }

    $user = fn_euph_profile_types_user_row(fn_euph_profile_types_signed_in_customer_id());
    $currentCode = Cast::string($user['euph_profile_type'] ?? '');
    $changes = Cast::int($user['euph_profile_type_changes'] ?? 0);
    $current = fn_euph_profile_types_type_or_default(UserKind::Customer, $currentCode, $langCode);
    if ($current === null) {
        return null;
    }
    $type = $current;
    $requested = fn_euph_profile_types_requested_code();
    if ($requested !== '' && TypeChangeGuard::allows($settings->typeChange, $currentCode, $requested, $changes)) {
        $type = $catalog->assignableOnStorefront(UserKind::Customer, $requested, $storefrontId, $langCode) ?? $current;
    }
    $choice = TypeChangeGuard::mayChange($settings->typeChange, $currentCode, $changes)
        ? fn_euph_profile_types_choice_options($catalog->selectable(UserKind::Customer, $storefrontId, $langCode), $type, $current)
        : [$current->code => $current->displayName()];

    return new FieldContext(UserKind::Customer, $type, FieldArea::Profile, $choice);
}

function fn_euph_profile_types_vendor_storefront_context(string $controller, string $mode): ?FieldContext
{
    if ($controller === 'companies' && $mode === 'apply_for_vendor') {
        return fn_euph_profile_types_registration_context(UserKind::Vendor);
    }

    return null;
}

/**
 * Registration and vendor application: the type of the link or of the
 * switch (?euph_profile_type=), else the one posted last time (the form came
 * back with an error), else the default. A link with
 * euph_profile_type_locked=1 fixes the type for the visit.
 */
function fn_euph_profile_types_registration_context(UserKind $kind): ?FieldContext
{
    $catalog = fn_euph_profile_types_container()->catalog();
    $langCode = fn_euph_profile_types_lang();
    $storefrontId = fn_euph_profile_types_storefront_id();

    $requested = fn_euph_profile_types_requested_code();
    $lock = fn_euph_profile_types_session_get('euph_profile_types_lock');
    if ($requested !== '' && Cast::flag($_REQUEST['euph_profile_type_locked'] ?? 'N')) {
        $lock[$kind->value] = $requested;
        fn_euph_profile_types_session_put('euph_profile_types_lock', $lock);
    }
    $locked = $catalog->assignableOnStorefront($kind, Cast::string($lock[$kind->value] ?? ''), $storefrontId, $langCode);
    if ($locked !== null) {
        return new FieldContext($kind, $locked, FieldArea::Registration, [$locked->code => $locked->displayName()]);
    }

    $posted = fn_euph_profile_types_session_get('euph_profile_types_posted');
    $type = null;
    foreach ([$requested, Cast::string($posted[$kind->value] ?? '')] as $code) {
        if ($code !== '') {
            $type = $catalog->assignableOnStorefront($kind, $code, $storefrontId, $langCode);
            if ($type !== null) {
                break;
            }
        }
    }
    $type ??= $catalog->defaultType($kind, $storefrontId, $langCode);
    if ($type === null) {
        return null;
    }

    return new FieldContext(
        $kind,
        $type,
        FieldArea::Registration,
        fn_euph_profile_types_choice_options($catalog->selectable($kind, $storefrontId, $langCode), $type),
    );
}

/**
 * The options of the choice: the types offered here plus the ones in use
 * (a hidden type of a link, a type the account already has). A choice of
 * one is not drawn.
 *
 * @param list<ProfileType> $selectable
 *
 * @return array<string, string>|null
 */
function fn_euph_profile_types_choice_options(array $selectable, ProfileType ...$inUse): ?array
{
    $options = TypeCatalog::options($selectable);
    foreach ($inUse as $type) {
        $options[$type->code] ??= $type->displayName();
    }

    return count($options) > 1 ? $options : null;
}

/** The signed-in customer of the storefront, 0 for a guest, an admin or a vendor. */
function fn_euph_profile_types_signed_in_customer_id(): int
{
    $auth = fn_euph_profile_types_auth();
    $userId = Cast::int($auth['user_id'] ?? 0);
    $userType = Cast::string($auth['user_type'] ?? '');

    return $userId > 0 && ($userType === '' || $userType === UserKind::Customer->value) ? $userId : 0;
}

/**
 * @return array<string, mixed>
 */
function fn_euph_profile_types_user_row(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    return fn_euph_profile_types_container()->db()->row(
        'SELECT user_id, user_type, company_id, euph_profile_type, euph_profile_type_changes FROM ?:users WHERE user_id = ?i',
        $userId,
    ) ?? [];
}

/**
 * The type of the current customer, a guest at checkout included: the type
 * chosen for the cart, else the type of the account, else the default. In
 * the admin panel (order editing) the customer is the cart's.
 */
function fn_euph_profile_types_current_customer_type(): ?ProfileType
{
    $container = fn_euph_profile_types_container();
    $catalog = $container->catalog();
    if (!$catalog->isEnabled(UserKind::Customer)) {
        return null;
    }
    $langCode = fn_euph_profile_types_lang();
    $cart = fn_euph_profile_types_session_get('cart');
    $userData = Cast::array($cart['user_data'] ?? []);

    foreach ([$cart['euph_profile_type'] ?? '', $userData['euph_profile_type'] ?? ''] as $code) {
        $type = $catalog->byCode(CodeValidator::normalize(Cast::string($code)), $langCode);
        if ($type !== null && $type->kind === UserKind::Customer && $type->isAssignable()) {
            return $type;
        }
    }

    $userId = fn_euph_profile_types_is_storefront()
        ? fn_euph_profile_types_signed_in_customer_id()
        : Cast::int($cart['user_id'] ?? 0);
    $user = fn_euph_profile_types_user_row($userId);
    if (Cast::string($user['user_type'] ?? '') === UserKind::Customer->value) {
        $type = $catalog->byCode(Cast::string($user['euph_profile_type'] ?? ''), $langCode);
        if ($type !== null && $type->kind === UserKind::Customer) {
            return $type;
        }
    }

    return $catalog->defaultType(UserKind::Customer, fn_euph_profile_types_storefront_id(), $langCode);
}
