<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Setting "Profile type choice at checkout": who sees the choice.
 */
enum CheckoutChoice: string
{
    case Everybody = 'all';
    case GuestsOnly = 'guests';
    case Nobody = 'none';

    public function offeredTo(bool $signedIn): bool
    {
        return match ($this) {
            self::Everybody => true,
            self::GuestsOnly => !$signedIn,
            self::Nobody => false,
        };
    }
}
