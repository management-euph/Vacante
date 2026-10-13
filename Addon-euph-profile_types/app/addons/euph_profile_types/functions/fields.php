<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldContext;
use Tygh\Addons\EuphProfileTypes\Service\TypeChoiceField;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * The profile fields of a type for an area: the add-on's own read.
 *
 * @param array<string, string>|null $choice      the type choice drawn first in the contact information
 * @param array<string, mixed>       $extraParams more fn_get_profile_fields() params
 *
 * @return array<array-key, mixed> section => field id => field
 */
function fn_euph_profile_types_get_fields(
    string $location,
    UserKind $kind,
    string $code,
    FieldArea $area,
    ?array $choice = null,
    string $langCode = '',
    array $extraParams = [],
): array {
    $params = array_merge($extraParams, [
        'profile_type' => $kind->fieldProfileType(),
        'euph_profile_type' => $code,
        'euph_area' => $area->value,
        'euph_choice' => $choice,
    ]);
    Registry::set('runtime.euph_profile_types.post_hook_ran', false);
    $fields = Cast::array(fn_get_profile_fields($location, [], $langCode !== '' ? $langCode : fn_euph_profile_types_lang(), $params));

    if (Registry::get('runtime.euph_profile_types.post_hook_ran') !== true) {
        // A core without the get_profile_fields_post hook: apply the type
        // here. Applying the general checkboxes again to a list CS-Cart
        // already limited by them removes nothing, so filter_all is safe.
        $context = fn_euph_profile_types_resolve_field_context($location, $params);
        if ($context !== null) {
            $fields = fn_euph_profile_types_apply_context($fields, $context->toParams() + ['filter_all' => true]);
        }
    }

    return $fields;
}

/**
 * Applies a context (FieldContext::toParams() + filter_all) to a field list:
 * the type's checkboxes, the vendor plans of the type, the type choice.
 *
 * @param array<array-key, mixed> $fields
 * @param array<array-key, mixed> $context
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_apply_context(array $fields, array $context): array
{
    $area = FieldArea::tryFrom(Cast::string($context['area'] ?? ''));
    $kind = UserKind::tryFrom(Cast::string($context['kind'] ?? ''));
    $typeId = Cast::int($context['type_id'] ?? 0);
    if ($area === null || $kind === null || $typeId <= 0) {
        return $fields;
    }
    $container = fn_euph_profile_types_container();
    $fields = $container->visibility()->apply($fields, $typeId, $area, Cast::flag($context['filter_all'] ?? false));

    if ($kind === UserKind::Vendor && $area === FieldArea::Registration) {
        $fields = fn_euph_profile_types_filter_vendor_plans($fields, Cast::idList($context['vendor_plan_ids'] ?? []));
    }

    $choice = FieldContext::choiceFromParams($context);
    if ($choice !== null) {
        $label = $kind === UserKind::Vendor
            ? fn_euph_profile_types_text('euph_profile_types.vendor_type', 'Vendor type')
            : fn_euph_profile_types_text('euph_profile_types.profile_type', 'Profile type');
        $fields = TypeChoiceField::inject(
            $fields,
            TypeChoiceField::build($choice, $container->settings()->choiceView, $label, $kind->fieldProfileType()),
        );
    }

    return $fields;
}

/**
 * The vendor plans a type offers: the "vendor plan" field of the vendor
 * application lists only them. A type without plans offers every plan.
 *
 * @param array<array-key, mixed> $fields
 * @param list<int>               $planIds
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_filter_vendor_plans(array $fields, array $planIds): array
{
    if ($planIds === []) {
        return $fields;
    }
    foreach ($fields as $section => $sectionFields) {
        if (!is_array($sectionFields)) {
            continue;
        }
        foreach ($sectionFields as $key => $field) {
            if (!is_array($field) || !is_array($field['plans'] ?? null)) {
                continue;
            }
            $field['plans'] = array_filter(
                $field['plans'],
                static fn (mixed $plan): bool => in_array(fn_euph_profile_types_plan_id($plan), $planIds, true),
            );
            $sectionFields[$key] = $field;
        }
        $fields[$section] = $sectionFields;
    }

    return $fields;
}

function fn_euph_profile_types_plan_id(mixed $plan): int
{
    if (is_array($plan)) {
        return Cast::int($plan['plan_id'] ?? 0);
    }

    return is_object($plan) ? Cast::int(fn_euph_profile_types_object_property($plan, 'plan_id')) : 0;
}

/**
 * Items of the checkout customer blocks (contact information, shipping and
 * billing address), see schemas/block_manager/blocks.post.php. A block on
 * the "By profile type" filling lists the fields of the customer's type, the
 * type choice first; a block on another filling is served by the core.
 *
 * @param array<array-key, mixed> $params the filling's params with the block's content
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_checkout_block_fields($params = []): array
{
    $params = Cast::array($params);
    if (!Cast::flag($params['euph_by_profile_type'] ?? false)) {
        $original = Cast::string($params['euph_original_items_function'] ?? '');

        return $original !== '' && is_callable($original) ? Cast::array(call_user_func($original, $params)) : [[]];
    }
    $section = Cast::string($params['euph_section'] ?? 'C');
    $langCode = fn_euph_profile_types_lang();
    $type = fn_euph_profile_types_current_customer_type();

    if ($type === null) {
        // Customer types are off: the general checkout checkboxes decide.
        $fields = Cast::array(fn_get_profile_fields('O', [], $langCode, ['section' => $section, 'euph_skip' => true]));
    } else {
        $context = fn_euph_profile_types_storefront_context(UserKind::Customer, 'O');
        $fields = fn_euph_profile_types_get_fields(
            'ALL',
            UserKind::Customer,
            $type->code,
            FieldArea::Checkout,
            $section === TypeChoiceField::SECTION && $context !== null ? $context->choice : null,
            $langCode,
            ['section' => $section],
        );
    }

    return [[$section => Cast::array($fields[$section] ?? [])]];
}

/**
 * The block manager schema with the "By profile type" filling on the
 * checkout customer blocks (schemas/block_manager/blocks.post.php).
 *
 * @return array<array-key, mixed>
 */
function fn_euph_profile_types_block_manager_schema(mixed $schema): array
{
    $schema = Cast::array($schema);
    foreach (\Tygh\Addons\EuphProfileTypes\Service\CheckoutBlockFilling::BLOCKS as $blockType => $section) {
        $block = fn_euph_profile_types_checkout_block_schema($schema[$blockType] ?? null, $section);
        if ($block !== null) {
            $schema[$blockType] = $block;
        }
    }

    return $schema;
}

/**
 * Adds the "By profile type" filling to a checkout block of the block
 * manager schema.
 *
 * @return array<array-key, mixed>|null the block's schema, null when the core has no such block
 */
function fn_euph_profile_types_checkout_block_schema(mixed $block, string $section): ?array
{
    if (!is_array($block) || !is_array($block['content'] ?? null) || !is_array($block['content']['items'] ?? null)) {
        return is_array($block) ? $block : null;
    }
    $content = $block['content'];
    $items = Cast::array($content['items']);
    $original = Cast::string($items['items_function'] ?? '');
    $items['items_function'] = 'fn_euph_profile_types_checkout_block_fields';

    $fillings = Cast::array($items['fillings'] ?? []);
    foreach ($fillings as $filling => $config) {
        $config = Cast::array($config);
        $params = Cast::array($config['params'] ?? []);
        $params['euph_original_items_function'] = $original;
        $config['params'] = $params;
        $fillings[$filling] = $config;
    }
    $fillings[\Tygh\Addons\EuphProfileTypes\Service\CheckoutBlockFilling::FILLING] = [
        'params' => [
            'euph_by_profile_type' => true,
            'euph_section' => $section,
            'euph_original_items_function' => $original,
        ],
    ];
    $items['fillings'] = $fillings;
    $content['items'] = $items;
    $block['content'] = $content;

    return $block;
}
