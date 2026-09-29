<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the ban on Tygh's lossy `?d` placeholder for sphinx coordinates.
 *
 * `?d` reformats decimals to ~2 dp before they reach SQL: DECIMAL(10,8)
 * coordinates written through it were stored as e.g. 36.89 instead of
 * 36.887069, putting the storefront "show on map" pin ~1 km off the hotel.
 * The exact same failure corrupted 4-dp currency coefficients (see
 * travel_core functions/exchange_rates.php). Decimals that must persist
 * exactly are bound as `?s` with TypeCoerce::toDecimalString().
 *
 * If you legitimately need a new decimal column in these files, bind it as
 * a formatted string — do not weaken these pins back to `?d`.
 */
final class CoordinatePrecisionTest extends TestCase
{
    private static function src(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** A `?d` inside a placeholder tuple (docs/comments mentioning ?d are fine). */
    private static function assertNoDdBinding(string $src): void
    {
        foreach (['?d,', ', ?d', '?d)'] as $binding) {
            self::assertStringNotContainsString($binding, $src);
        }
    }

    public function testHotelUpsertTupleCarriesNoLossyDdPlaceholder(): void
    {
        $src = self::src('src/Repository/HotelRepository.php');

        self::assertNoDdBinding($src);
        self::assertStringContainsString('(?s, ?s, ?i, ?s, ?i, ?s, ?i, ?s, ?s, ?s, ?s, ?s,', $src);
        self::assertStringContainsString("toDecimalString(\$hotel['latitude'] ?? 0, 8)", $src);
        self::assertStringContainsString("toDecimalString(\$hotel['longitude'] ?? 0, 8)", $src);
        self::assertStringContainsString("toDecimalString(\$hotel['rating'] ?? 0, 1)", $src);
    }

    public function testDestinationUpsertTupleCarriesNoLossyDdPlaceholder(): void
    {
        $src = self::src('src/Repository/DestinationRepository.php');

        self::assertNoDdBinding($src);
        self::assertStringContainsString('(?i, ?s, ?s, ?i, ?s, ?i, ?s, ?s, ?i, ?s, ?s)', $src);
        self::assertStringContainsString("toDecimalString(\$dest['latitude'] ?? 0, 8)", $src);
        self::assertStringContainsString("toDecimalString(\$dest['longitude'] ?? 0, 8)", $src);
    }

    public function testProductFactoryDedupComparesFullPrecisionStrings(): void
    {
        $src = self::src('src/Helpers/SphinxProductFactory.php');

        // ?d would round the bound coordinate BEFORE MySQL's ROUND(,3) runs.
        self::assertStringNotContainsString('ROUND(?d, 3)', $src);
        self::assertStringContainsString('ROUND(?s, 3)', $src);
        self::assertStringContainsString('toDecimalString($lat, 8)', $src);
        self::assertStringContainsString('toDecimalString($lng, 8)', $src);
    }

    public function testAddonXmlStoresCoordinatesAtEightDecimals(): void
    {
        $xml = self::src('addon.xml');

        // Latitude DECIMAL(10,8), longitude DECIMAL(11,8) — the ~1 mm standard.
        self::assertStringContainsString('DECIMAL(10,8)', $xml);
        self::assertStringContainsString('DECIMAL(11,8)', $xml);
    }
}
