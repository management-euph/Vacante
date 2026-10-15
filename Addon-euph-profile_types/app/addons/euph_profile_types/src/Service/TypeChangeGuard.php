<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Enum\TypeChangePolicy;

/**
 * Whether a customer may change the type of their own account on the
 * storefront. The administrator changes it in the panel in any case.
 */
final class TypeChangeGuard
{
    public static function allows(TypeChangePolicy $policy, string $currentCode, string $newCode, int $changesSoFar): bool
    {
        if ($newCode === '' || strcasecmp($currentCode, $newCode) === 0) {
            return true;
        }
        if ($currentCode === '') {
            // An account without a type yet (created before the add-on, or
            // by another add-on) chooses one: that is not a change.
            return true;
        }

        return self::mayChange($policy, $currentCode, $changesSoFar);
    }

    /**
     * Whether the type a locked registration link fixed still holds on the
     * registration form (or the vendor application) being opened: on the
     * page the link opens, and when the form comes back after being sent
     * with that type (an error). Opened again any other way, a new visit, the
     * form offers every type.
     */
    public static function lockHolds(string $lockedCode, string $postedCode, bool $lockedLinkOpened): bool
    {
        if ($lockedCode === '') {
            return false;
        }

        return $lockedLinkOpened || strcasecmp($lockedCode, $postedCode) === 0;
    }

    /** Whether the customer may change the type at all: the choice is offered in the account. */
    public static function mayChange(TypeChangePolicy $policy, string $currentCode, int $changesSoFar): bool
    {
        if ($currentCode === '') {
            return true;
        }

        return match ($policy) {
            TypeChangePolicy::Always => true,
            TypeChangePolicy::Once => $changesSoFar < 1,
            TypeChangePolicy::Never => false,
        };
    }
}
