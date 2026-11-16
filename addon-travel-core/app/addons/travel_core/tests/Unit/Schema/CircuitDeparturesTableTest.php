<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;

/**
 * The circuits' departures table (docs/circuit-page-plan.md §3) reaches every
 * store the same way the availability tables do: fresh installs and upgrades
 * through addon.xml, stores updated by pulling code through the runtime heal
 * (fn_travel_core_ensure_schema). All three must create the same table, so
 * addon.xml's statements are pinned to the repository's.
 */
final class CircuitDeparturesTableTest extends TestCase
{
    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testAddonXmlCreatesTheSameTableForInstallAndUpgrade(): void
    {
        $xml = simplexml_load_file(self::root() . '/addon.xml');
        self::assertNotFalse($xml);

        $items = [];
        foreach ($xml->xpath('//queries/item') ?: [] as $item) {
            $sql = (string) $item;
            if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `?:travel_circuit_departures`')) {
                $items[] = ['for' => (string) ($item['for'] ?? ''), 'sql' => self::squash($sql)];
            }
        }

        $expected = self::squash(CircuitDepartureRepository::CREATE_SQL);
        self::assertSame([['for' => '', 'sql' => $expected], ['for' => 'upgrade', 'sql' => $expected]], $items);
    }

    public function testTheRuntimeHealCreatesTheTable(): void
    {
        $func = (string) file_get_contents(self::root() . '/func.php');
        $heal = substr($func, (int) strpos($func, 'function fn_travel_core_ensure_schema('));

        self::assertStringContainsString("require_once __DIR__ . '/src/Repository/CircuitDepartureRepository.php';", $heal, 'by path: post_install runs before the autoloader');
        self::assertStringContainsString('CircuitDepartureRepository::ensureTable();', $heal);
    }

    public function testUninstallDropsTheTable(): void
    {
        $func = (string) file_get_contents(self::root() . '/func.php');
        $uninstall = substr($func, (int) strpos($func, 'function fn_travel_core_uninstall('), 3000);

        self::assertStringContainsString("db_query('DROP TABLE IF EXISTS ?:travel_circuit_departures');", $uninstall);
        self::assertStringContainsString(
            '<item for="uninstall">DROP TABLE IF EXISTS `?:travel_circuit_departures`</item>',
            (string) file_get_contents(self::root() . '/addon.xml'),
        );
    }

    /** Every supplier's ids fit: Sphinx's are numbers, Eurosite's text ("196|EU", "ROBCH1"). */
    public function testADepartureIsKeyedBySupplierCircuitCityAndDate(): void
    {
        $create = self::squash(CircuitDepartureRepository::CREATE_SQL);

        self::assertStringContainsString('PRIMARY KEY (`provider`, `circuit_id`, `departure_code`, `departure_date`)', $create);
        self::assertStringContainsString('`circuit_id` VARCHAR(64) NOT NULL', $create);
        self::assertStringContainsString("`departure_code` VARCHAR(64) NOT NULL DEFAULT ''", $create);
        self::assertStringContainsString('`seats` SMALLINT UNSIGNED DEFAULT NULL', $create, 'unknown, not zero, when the supplier does not say');
    }
}
