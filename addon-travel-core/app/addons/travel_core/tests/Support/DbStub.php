<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Support;

/**
 * Closure-backed stand-ins for CS-Cart's db_* procedural helpers.
 *
 * The bootstrap routes db_get_field / db_query / db_get_array / db_get_fields
 * through this class. Each test configures the closure it needs (usually a
 * query → result dispatcher on the $query string) and calls reset() in
 * setUp() to prevent cross-test pollution.
 *
 * Default behaviour (no closure set) matches the bare stubs: null / 0 / [] —
 * tests that don't touch the DB continue to work unchanged.
 */
final class DbStub
{
    /** @var (callable(string, mixed...): mixed)|null */
    public static $getField = null;

    /** @var (callable(string, mixed...): array<string, mixed>)|null */
    public static $getRow = null;

    /** @var (callable(string, mixed...): list<array<string, mixed>>)|null */
    public static $getArray = null;

    /** @var (callable(string, mixed...): list<mixed>)|null */
    public static $getFields = null;

    /** @var (callable(string, mixed...): int)|null */
    public static $query = null;

    /** @var (callable(string, array<int, string>, mixed...): array<array-key, mixed>)|null */
    public static $getHashSingleArray = null;

    public static function reset(): void
    {
        self::$getField = null;
        self::$getRow = null;
        self::$getArray = null;
        self::$getFields = null;
        self::$query = null;
        self::$getHashSingleArray = null;
    }
}
