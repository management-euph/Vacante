<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Whether a storefront page may show its debug output: the supplier's raw
 * requests and answers (net prices), errors with their stack traces, the
 * cart's internal fields.
 *
 * Debug mode alone showed them to every visitor. Now the visitor must also
 * be signed in with an administrator account; customers never see them,
 * whatever the setting. What goes to the store's log is unchanged.
 */
final class StorefrontDebug
{
    /**
     * @param mixed $auth the session's auth array
     */
    public static function allowed(bool $debugMode, mixed $auth): bool
    {
        if (!$debugMode) {
            return false;
        }
        $auth = TypeCoerce::toStringMap($auth);

        return TypeCoerce::toInt($auth['user_id'] ?? 0) > 0
            && TypeCoerce::toString($auth['user_type'] ?? '') === 'A';
    }

    /** For the current request's visitor. */
    public static function forThisVisitor(): bool
    {
        return self::allowed(ConfigProvider::isDebugMode(), $_SESSION['auth'] ?? null);
    }
}
