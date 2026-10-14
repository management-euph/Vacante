<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Enum\ChoiceView;

/**
 * The type choice, drawn as a profile field.
 *
 * It is not stored in ?:profile_fields: the add-on puts it first in the
 * contact information of the field lists it serves. CS-Cart's own field
 * templates then draw it like any radio group or drop-down, in the
 * registration form, the customer account, the checkout, the vendor
 * application and the admin panel, and post it as user_data[euph_profile_type]
 * (company_data[...] for vendors), which is the column the type is kept in.
 * In an order or a vendor card the same field shows the type's name.
 */
final class TypeChoiceField
{
    public const FIELD_NAME = 'euph_profile_type';
    public const SECTION = 'C';

    /**
     * @param array<string, string> $options code => name, in display order
     *
     * @return array<string, mixed>
     */
    public static function build(array $options, ChoiceView $view, string $label, string $profileType): array
    {
        return [
            'field_id' => self::FIELD_NAME,
            'field_name' => self::FIELD_NAME,
            'field_type' => $view->fieldType(),
            'section' => self::SECTION,
            'profile_type' => $profileType,
            'is_default' => 'Y',
            'description' => $label,
            'values' => $options,
            'required' => 'Y',
            'profile_show' => 'Y',
            'profile_required' => 'Y',
            'checkout_show' => 'Y',
            'checkout_required' => 'Y',
            'partner_show' => 'N',
            'partner_required' => 'N',
            'storefront_show' => 'Y',
            'position' => -1,
            'matching_id' => 0,
            'class' => 'cm-euph-profile-type',
            'wrapper_class' => 'euph-pt-choice',
            'euph_profile_type_choice' => 'Y',
        ];
    }

    /**
     * Puts the field first in the contact information.
     *
     * @param array<array-key, mixed> $fields section => field id => field
     * @param array<string, mixed> $field
     *
     * @return array<array-key, mixed>
     */
    public static function inject(array $fields, array $field): array
    {
        $contact = $fields[self::SECTION] ?? [];
        $contact = is_array($contact) ? $contact : [];
        unset($contact[self::FIELD_NAME]);
        $fields[self::SECTION] = [self::FIELD_NAME => $field] + $contact;

        return $fields;
    }
}
