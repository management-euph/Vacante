<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Support;

/**
 * Closure-backed stand-ins for CS-Cart's db_* helpers (the Travel Core and
 * Novoton suites have the same). The bootstrap routes each db_* call here;
 * a test sets the closures it needs and calls reset() in setUp(). Without a
 * closure the stand-ins answer null / [] / 0, as before they existed.
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

    public static function reset(): void
    {
        self::$getField = null;
        self::$getRow = null;
        self::$getArray = null;
        self::$getFields = null;
        self::$query = null;
    }
}
