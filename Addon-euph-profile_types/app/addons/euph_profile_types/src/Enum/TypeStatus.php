<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

enum TypeStatus: string
{
    /** Offered in the type choice. */
    case Active = 'A';
    /** Not offered in the choice; assigned by a registration link or by the administrator. */
    case Hidden = 'H';
    /** Not assignable at all. Users who already have it keep it. */
    case Disabled = 'D';

    public static function fromValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Disabled;
    }
}
