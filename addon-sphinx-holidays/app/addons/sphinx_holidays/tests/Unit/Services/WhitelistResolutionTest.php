<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * What the whitelist rows sell, as every Sphinx sync reads them
 * (ConfigProvider::getAllowedDestinationIds()): a whole country, a whole
 * region with the cities added to it later, a city with its resorts.
 */
final class WhitelistResolutionTest extends TestCase
{
    /**
     * GR (1) › Crete (10) › Heraklion (11) › Hersonissos (111); Crete › Chania (12)
     * GR › Rhodes (20) › Lindos (21) › Pefkos (211); Rhodes › Faliraki (22)
     * TR (2) › Antalya (40) › Belek (41)
     */
    private const array TREE = [
        1 => ['type' => 'country', 'parent' => 0, 'cc' => 'GR'],
        10 => ['type' => 'region', 'parent' => 1, 'cc' => 'GR'],
        11 => ['type' => 'city', 'parent' => 10, 'cc' => 'GR'],
        111 => ['type' => 'destination', 'parent' => 11, 'cc' => 'GR'],
        12 => ['type' => 'city', 'parent' => 10, 'cc' => 'GR'],
        20 => ['type' => 'region', 'parent' => 1, 'cc' => 'GR'],
        21 => ['type' => 'city', 'parent' => 20, 'cc' => 'GR'],
        211 => ['type' => 'destination', 'parent' => 21, 'cc' => 'GR'],
        22 => ['type' => 'city', 'parent' => 20, 'cc' => 'GR'],
        2 => ['type' => 'country', 'parent' => 0, 'cc' => 'TR'],
        40 => ['type' => 'region', 'parent' => 2, 'cc' => 'TR'],
        41 => ['type' => 'city', 'parent' => 40, 'cc' => 'TR'],
    ];

    protected function setUp(): void
    {
        DbStub::reset();
        DbStub::$getArray = static function (string $q, mixed ...$p): array {
            $out = [];
            foreach (is_array($p[0] ?? null) ? $p[0] : [] as $id) {
                if (isset(self::TREE[$id])) {
                    $out[] = ['destination_id' => $id, 'type' => self::TREE[$id]['type']];
                }
            }

            return $out;
        };
        DbStub::$getHashSingleArray = static function (string $q, array $fields, mixed ...$p): array {
            $out = [];
            foreach (is_array($p[0] ?? null) ? $p[0] : [] as $id) {
                $out[$id] = self::TREE[$id]['cc'] ?? '';
            }

            return $out;
        };
        DbStub::$getFields = static function (string $q, mixed ...$p): array {
            $wanted = is_array($p[0] ?? null) ? $p[0] : [];
            $out = [];
            foreach (self::TREE as $id => $node) {
                if (str_contains($q, 'parent_id IN') && in_array($node['parent'], $wanted, true)) {
                    $out[] = $id;
                } elseif (str_contains($q, 'country_code IN') && in_array($node['cc'], $wanted, true)) {
                    $out[] = $id;
                }
            }

            return $out;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param list<array{destination_id: int, selection_type: string}> $rows
     * @return list<int>
     */
    private static function resolve(array $rows): array
    {
        $m = new \ReflectionMethod(ConfigProvider::class, 'resolveWhitelistEntries');
        $ids = $m->invoke(null, $rows);
        self::assertIsArray($ids);
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    public function testAWholeCountryIsEveryDestinationOfIt(): void
    {
        self::assertSame([2, 40, 41], self::resolve([['destination_id' => 2, 'selection_type' => 'all']]));
    }

    /** A whole region takes the cities Sphinx adds to it later: they are read, not saved. */
    public function testAWholeRegionIsItselfAndEverythingUnderIt(): void
    {
        self::assertSame([1, 10, 11, 12, 111], self::resolve([
            ['destination_id' => 1, 'selection_type' => 'specific'],
            ['destination_id' => 10, 'selection_type' => 'all'],
        ]));
    }

    public function testACityBringsItsResorts(): void
    {
        self::assertSame([1, 21, 211], self::resolve([
            ['destination_id' => 1, 'selection_type' => 'specific'],
            ['destination_id' => 21, 'selection_type' => 'specific'],
        ]));
    }

    /** Saved before whole regions: the region row is itself; its cities were saved next to it. */
    public function testARegionSavedSpecificIsOnlyItself(): void
    {
        self::assertSame([1, 20, 22], self::resolve([
            ['destination_id' => 1, 'selection_type' => 'specific'],
            ['destination_id' => 20, 'selection_type' => 'specific'],
            ['destination_id' => 22, 'selection_type' => 'specific'],
        ]));
    }

    /** The dashboard's Sync hotels button used to send the country codes alone: the whole country. */
    public function testTheSyncHotelsButtonSendsTheWhitelistedDestinations(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php');

        self::assertStringContainsString('$service->sync(ConfigProvider::getSelectedCountryCodes(), ConfigProvider::getAllowedDestinationIds());', $src);
    }
}
