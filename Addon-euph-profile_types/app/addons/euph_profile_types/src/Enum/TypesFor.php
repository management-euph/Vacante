<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Setting "Use profile types for".
 */
enum TypesFor: string
{
    case Customers = 'customers';
    case Vendors = 'vendors';
    case Both = 'both';

    public function covers(UserKind $kind): bool
    {
        return match ($this) {
            self::Customers => $kind === UserKind::Customer,
            self::Vendors => $kind === UserKind::Vendor,
            self::Both => true,
        };
    }
}
