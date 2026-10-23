<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;

/**
 * The availability tables reach every store the same way: fresh installs
 * and upgrades through addon.xml, stores updated by pulling code through the
 * runtime heal (fn_travel_core_ensure_schema). The three must create the
 * same table, so addon.xml's statements are pinned to the repository's.
 */
final class AvailabilityTablesTest extends TestCase
{
    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @return list<array{for: string, sql: string}> */
    private static function addonXmlItems(string $table): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);

        $items = [];
        foreach ($xml->xpath('//queries/item') ?: [] as $item) {
            $sql = (string) $item;
            if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `?:' . $table . '`')) {
                $items[] = ['for' => (string) ($item['for'] ?? ''), 'sql' => self::squash($sql)];
            }
        }

        return $items;
    }

    public function testAddonXmlCreatesTheSameTablesForInstallAndUpgrade(): void
    {
        foreach ([
            'travel_hotel_availability' => HotelAvailabilityRepository::CREATE_RECORDS_SQL,
            'travel_hotel_state_log' => HotelAvailabilityRepository::CREATE_LOG_SQL,
        ] as $table => $sql) {
            $expected = self::squash($sql);
            self::assertSame(
                [['for' => '', 'sql' => $expected], ['for' => 'upgrade', 'sql' => $expected]],
                self::addonXmlItems($table),
                $table,
            );
        }
    }

    public function testTheRuntimeHealCreatesBothTables(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        $heal = substr($func, (int) strpos($func, 'function fn_travel_core_ensure_schema('));

        self::assertStringContainsString('HotelAvailabilityRepository::ensureTables();', $heal);
    }

    public function testEveryRecordIsKeyedBySupplierAndHotel(): void
    {
        self::assertStringContainsString('PRIMARY KEY (`provider`, `hotel_id`)', HotelAvailabilityRepository::CREATE_RECORDS_SQL);
        self::assertStringContainsString('KEY `idx_product` (`product_id`)', HotelAvailabilityRepository::CREATE_RECORDS_SQL);
    }
}
