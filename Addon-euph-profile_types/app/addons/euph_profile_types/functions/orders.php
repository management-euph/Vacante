<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Addons\EuphProfileTypes\Service\TypeChoiceField;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * The type an order is placed with: the order's own, the cart's (a type
 * chosen at checkout, or in the order editor), the customer's, the default.
 *
 * @param array<array-key, mixed> $order
 */
function fn_euph_profile_types_order_type(array $order): ?ProfileType
{
    $catalog = fn_euph_profile_types_container()->catalog();
    if (!$catalog->isEnabled(UserKind::Customer)) {
        return null;
    }
    $langCode = fn_euph_profile_types_lang();
    $cart = fn_euph_profile_types_session_get('cart');
    $cartUserData = Cast::array($cart['user_data'] ?? []);
    $candidates = [
        $order['euph_profile_type'] ?? '',
        Cast::array($order['user_data'] ?? [])['euph_profile_type'] ?? '',
        $cart['euph_profile_type'] ?? '',
        $cartUserData['euph_profile_type'] ?? '',
    ];
    foreach ($candidates as $code) {
        $type = $catalog->assignableByAdmin(UserKind::Customer, CodeValidator::normalize(Cast::string($code)), $langCode);
        if ($type !== null) {
            return $type;
        }
    }
    $user = fn_euph_profile_types_user_row(Cast::int($order['user_id'] ?? 0));
    if (Cast::string($user['user_type'] ?? '') === UserKind::Customer->value) {
        $type = $catalog->byCode(Cast::string($user['euph_profile_type'] ?? ''), $langCode);
        if ($type !== null && $type->kind === UserKind::Customer) {
            return $type;
        }
    }

    return $catalog->defaultType(UserKind::Customer, fn_euph_profile_types_storefront_id(), $langCode);
}

/**
 * Order details (admin panel and customer account): the fields of the
 * order's type, and the type itself first in the customer information.
 */
function fn_euph_profile_types_set_order_context(string $code): void
{
    if ($code === '' || !fn_euph_profile_types_container()->catalog()->isEnabled(UserKind::Customer)) {
        return;
    }
    fn_euph_profile_types_set_field_context([
        'kind' => UserKind::Customer->value,
        'code' => $code,
        'area' => FieldArea::Checkout->value,
        'choice' => 'display',
    ]);
}

/**
 * The "user" variable of the order documents and e-mails (Design →
 * Documents): CS-Cart's own data, plus every field of the order's type under
 * its code (user.company_inn), and the type (user.profile_type,
 * user.profile_type_code).
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_document_user_data(mixed $context, mixed $original): array
{
    $order = is_object($context) && method_exists($context, 'getOrder') ? $context->getOrder() : null;
    $data = is_object($order) ? Cast::array(fn_euph_profile_types_object_property($order, 'data')) : [];
    $code = Cast::string($data['euph_profile_type'] ?? '');
    $previous = fn_euph_profile_types_get_field_context();
    fn_euph_profile_types_set_order_context($code);

    try {
        if (is_callable($original)) {
            $user = Cast::array($original($context));
        } else {
            $user = is_object($order) && method_exists($order, 'getUser') ? Cast::array($order->getUser()) : [];
        }
        if ($code === '' || !fn_euph_profile_types_container()->catalog()->isEnabled(UserKind::Customer)) {
            return $user;
        }

        $langCode = Cast::string($data['lang_code'] ?? '') ?: fn_euph_profile_types_lang();
        $fields = fn_euph_profile_types_get_fields('I', UserKind::Customer, $code, FieldArea::Checkout, null, $langCode);
        foreach (['C', 'B', 'S'] as $section) {
            foreach (Cast::array($fields[$section] ?? []) as $field) {
                $field = Cast::array($field);
                $name = Cast::string($field['field_name'] ?? '');
                if ($name === '' || array_key_exists($name, $user) || !function_exists('fn_get_profile_field_value')) {
                    continue;
                }
                $value = fn_get_profile_field_value($data, $field);
                if (in_array(Cast::string($field['field_type'] ?? ''), ['A', 'O'], true)) {
                    // State and country: the code, and the name under _descr.
                    $user[$name . '_descr'] = $value;
                    $value = $data[$name] ?? $value;
                }
                $user[$name] = $value;
            }
        }
        $user['profile_type_code'] = $code;
        $user['profile_type'] = fn_euph_profile_types_get_type_name($code, $langCode);

        return $user;
    } finally {
        fn_euph_profile_types_set_field_context(
            $previous !== null
                ? [
                    'kind' => Cast::string($previous['kind'] ?? ''),
                    'code' => Cast::string($previous['code'] ?? ''),
                    'area' => Cast::string($previous['area'] ?? ''),
                    'choice' => Cast::string($previous['choice'] ?? ''),
                ]
                : null,
        );
    }
}

/**
 * The variables of the "user" variable the document editor lists: CS-Cart's,
 * the codes of every customer field, and the type.
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_document_user_attributes(mixed $original): array
{
    $attributes = is_callable($original) ? Cast::array($original()) : Cast::array($original);
    if ($attributes === []) {
        $attributes = ['email', 'firstname', 'lastname', 'phone'];
    }
    $names = ['profile_type', 'profile_type_code'];
    $fields = Cast::array(fn_get_profile_fields('ALL', [], fn_euph_profile_types_lang(), ['profile_type' => 'U', 'euph_skip' => true]));
    foreach ($fields as $section => $sectionFields) {
        if ($section === 'E') {
            continue;
        }
        foreach (Cast::array($sectionFields) as $field) {
            $field = Cast::array($field);
            $name = Cast::string($field['field_name'] ?? '');
            if ($name === '' || $name === TypeChoiceField::FIELD_NAME) {
                continue;
            }
            $names[] = $name;
            if (in_array(Cast::string($field['field_type'] ?? ''), ['A', 'O'], true)) {
                $names[] = $name . '_descr';
            }
        }
    }
    foreach ($names as $name) {
        if (!in_array($name, $attributes, true)) {
            $attributes[] = $name;
        }
    }

    return $attributes;
}

/**
 * The invoice details of the "Issue an invoice" payment (Russian Payments
 * add-on) that the customer's profile already holds: the payment's settings
 * map each detail to a profile field. One common list of fields means one
 * mapping for every type.
 *
 * @param array<string, mixed> $cart
 *
 * @return array<string, string> detail => value
 */
function fn_euph_profile_types_invoice_details(array $cart): array
{
    $db = fn_euph_profile_types_container()->db();
    $method = Cast::array($cart['payment_method_data'] ?? []);
    $paymentId = Cast::int($cart['payment_id'] ?? ($method['payment_id'] ?? 0));
    $script = $paymentId > 0 ? Cast::string($db->value(
        'SELECT p.processor_script FROM ?:payments AS m INNER JOIN ?:payment_processors AS p ON p.processor_id = m.processor_id WHERE m.payment_id = ?i',
        $paymentId,
    )) : '';
    $mapping = $script === 'account.php'
        ? Cast::array(Cast::array($method['processor_params'] ?? [])['fields_account'] ?? [])
        : [];
    if ($mapping === []) {
        // The customer may pick the invoice payment later on the page: its
        // settings are read now, the inputs are hidden when they show up.
        $raw = Cast::string($db->value(
            'SELECT m.processor_params FROM ?:payments AS m INNER JOIN ?:payment_processors AS p ON p.processor_id = m.processor_id'
            . ' WHERE p.processor_script = ?s AND m.status = ?s ORDER BY m.position ASC, m.payment_id ASC',
            'account.php',
            'A',
        ));
        $params = $raw !== '' ? @unserialize($raw, ['allowed_classes' => false]) : [];
        $mapping = Cast::array(Cast::array($params)['fields_account'] ?? []);
    }
    if ($mapping === []) {
        return [];
    }
    $userData = Cast::array($cart['user_data'] ?? []);
    $custom = Cast::array($userData['fields'] ?? []);
    $fields = fn_euph_profile_types_field_rows(Cast::idList(array_filter($mapping, 'is_scalar')));

    $details = [];
    foreach ($mapping as $key => $fieldId) {
        $field = $fields[Cast::int($fieldId)] ?? null;
        if ($field === null) {
            continue;
        }
        $name = Cast::string($field['field_name'] ?? '');
        $value = Cast::flag($field['is_default'] ?? 'N') && $name !== ''
            ? Cast::string($userData[$name] ?? '')
            : Cast::string($custom[Cast::int($fieldId)] ?? '');
        if (trim($value) !== '') {
            $details[(string) $key] = trim($value);
        }
    }

    return $details;
}
