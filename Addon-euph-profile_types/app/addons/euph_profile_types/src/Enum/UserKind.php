<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Who a profile type is for. The value is CS-Cart's user type code.
 */
enum UserKind: string
{
    case Customer = 'C';
    case Vendor = 'V';

    /**
     * CS-Cart profile type (?:profile_fields.profile_type) holding the common
     * field list of this kind: ProfileTypes::CODE_USER for customers,
     * ProfileTypes::CODE_SELLER for vendors.
     */
    public function fieldProfileType(): string
    {
        return match ($this) {
            self::Customer => 'U',
            self::Vendor => 'S',
        };
    }

    /**
     * Code of the standard type created at install. It equals the CS-Cart
     * profile type code, as in the 1.x add-on, so 1.x data keeps its meaning.
     */
    public function standardCode(): string
    {
        return $this->fieldProfileType();
    }

    public static function fromFieldProfileType(string $profileType): ?self
    {
        return match ($profileType) {
            'U' => self::Customer,
            'S' => self::Vendor,
            default => null,
        };
    }
}
