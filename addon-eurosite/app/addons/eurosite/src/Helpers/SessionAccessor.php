<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Helpers;

/**
 * The single designated boundary for CS-Cart session state (the
 * disallowed-calls policy bans Tygh::$app[...] everywhere else in src/).
 * Keys are namespaced by the caller; values go through json-ish array
 * shapes only — no objects in the session.
 */
final class SessionAccessor
{
    /**
     * @return array<string, mixed>
     */
    public static function getArray(string $key): array
    {
        $session = \Tygh\Tygh::$app['session'] ?? null;
        if (!is_array($session) && !$session instanceof \ArrayAccess) {
            return [];
        }
        $value = $session[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function setArray(string $key, array $value): void
    {
        // CS-Cart's session is an ArrayAccess object — offset-set mutates it
        // in place; never reassign the container slot itself.
        $session = \Tygh\Tygh::$app['session'] ?? null;
        if ($session instanceof \ArrayAccess) {
            $session[$key] = $value;
        }
    }
}
