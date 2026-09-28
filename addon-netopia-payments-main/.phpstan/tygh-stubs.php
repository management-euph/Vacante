<?php
/**
 * CS-Cart Tygh namespace stubs for PHPStan static analysis.
 */

namespace Tygh;

class Tygh
{
    /** @var \ArrayAccess<string, mixed> */
    public static \ArrayAccess $app;
}

class Registry
{
    public static function get(string $key): mixed { return null; }
    public static function set(string $key, mixed $value): void {}
}
