<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * What happens to a new account of a type registered on the storefront.
 */
enum Activation: string
{
    /** Follow the store setting "Administrator must activate new user accounts". */
    case StoreSetting = 'S';
    /** Active at once, whatever the store setting says. */
    case Instant = 'A';
    /** Waits for the administrator's approval, whatever the store setting says. */
    case Approval = 'P';

    public static function fromValue(string $value): self
    {
        return self::tryFrom($value) ?? self::StoreSetting;
    }
}
