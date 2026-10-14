<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Where a profile field is shown. Each area has a "show" checkbox per type;
 * the profile and the checkout also have a "required" one, and registration
 * uses the profile's: a field shown at registration is required there when
 * it is required in the profile.
 */
enum FieldArea: string
{
    /** The customer account and the customer or vendor card in the admin panel. */
    case Profile = 'profile';
    /** The registration form and the vendor application form. */
    case Registration = 'registration';
    /** The customer information blocks at checkout, and the order. */
    case Checkout = 'checkout';
    /** The vendor card on the storefront (vendor fields). */
    case Storefront = 'storefront';

    /** Column of ?:euph_profile_type_fields with the type's "show" checkbox. */
    public function showFlag(): string
    {
        return match ($this) {
            self::Profile => 'profile_show',
            self::Registration => 'registration_show',
            self::Checkout => 'checkout_show',
            self::Storefront => 'storefront_show',
        };
    }

    /** Column of ?:euph_profile_type_fields with the type's "required" checkbox. */
    public function requiredFlag(): ?string
    {
        return match ($this) {
            self::Profile, self::Registration => 'profile_required',
            self::Checkout => 'checkout_required',
            self::Storefront => null,
        };
    }

    /** Column of ?:profile_fields that decides for a field no type governs. */
    public function generalShowColumn(): string
    {
        return match ($this) {
            self::Profile, self::Registration => 'profile_show',
            self::Checkout => 'checkout_show',
            self::Storefront => 'storefront_show',
        };
    }

    /** Column of ?:profile_fields with the general "required" checkbox. */
    public function generalRequiredColumn(): ?string
    {
        return match ($this) {
            self::Profile, self::Registration => 'profile_required',
            self::Checkout => 'checkout_required',
            self::Storefront => null,
        };
    }
}
