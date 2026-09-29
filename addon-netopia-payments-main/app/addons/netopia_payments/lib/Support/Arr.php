<?php

declare(strict_types=1);

namespace Netopia\CsCart\Support;

/**
 * Type-safe extraction from mixed-value arrays (CS-Cart data boundary).
 *
 * PHPStan level 9 forbids casting `mixed` directly. These helpers
 * narrow the type before casting, satisfying the analyser while
 * keeping call-sites concise.
 */
final class Arr
{
    /**
     * @param array<array-key, mixed> $arr
     */
    public static function string(array $arr, string $key, string $default = ''): string
    {
        $val = $arr[$key] ?? $default;
        return is_scalar($val) ? (string) $val : $default;
    }

    /**
     * @param array<array-key, mixed> $arr
     */
    public static function int(array $arr, string $key, int $default = 0): int
    {
        $val = $arr[$key] ?? $default;
        return is_numeric($val) ? (int) $val : $default;
    }

    /**
     * @param array<array-key, mixed> $arr
     */
    public static function float(array $arr, string $key, float $default = 0.0): float
    {
        $val = $arr[$key] ?? $default;
        return is_numeric($val) ? (float) $val : $default;
    }

    /**
     * @param array<array-key, mixed> $arr
     * @return array<string, mixed>
     */
    public static function array(array $arr, string $key): array
    {
        $val = $arr[$key] ?? null;
        if (!is_array($val)) {
            return [];
        }
        $result = [];
        foreach ($val as $k => $v) {
            $result[is_string($k) ? $k : (string) $k] = $v;
        }
        return $result;
    }

    /**
     * Narrow an array with potentially mixed keys to string-keyed.
     *
     * Useful for superglobals ($_SERVER, $_POST) which PHPStan level 10
     * types as array<mixed> inside included files.
     *
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    public static function stringKeys(array $source): array
    {
        $result = [];
        foreach ($source as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    /**
     * Extract a string from the first key found, with fallback keys.
     *
     * @param array<array-key, mixed> $arr
     */
    public static function firstString(array $arr, string $key, string ...$fallbackKeys): string
    {
        $val = $arr[$key] ?? null;
        if (is_scalar($val) && (string) $val !== '') {
            return (string) $val;
        }
        foreach ($fallbackKeys as $fb) {
            $val = $arr[$fb] ?? null;
            if (is_scalar($val) && (string) $val !== '') {
                return (string) $val;
            }
        }
        return '';
    }
}
