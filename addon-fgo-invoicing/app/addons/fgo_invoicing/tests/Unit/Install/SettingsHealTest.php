<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade path for settings added after install (the CIF / Reg. Com. /
 * CNP field selectors): CS-Cart creates settings only at install, and
 * reinstalling drops ?:fgo_invoices. fn_fgo_invoicing_heal_settings_once()
 * creates the missing rows through travel_core's SettingsMigrator.
 *
 * Runs in a clean php process per scenario: the heal needs AREA and
 * travel_core's functions, both process-global and absent from the unit
 * bootstrap (which must never load travel_core). Pinned:
 *   - admin area only, via dispatch_before_display, once per fingerprint;
 *   - it creates, then places, then re-mirrors labels — in that order;
 *   - ADD-ONLY: the migrator runs only when a declared row is missing, and
 *     every setting that existed keeps its value and type (the migrator
 *     would write SHIPPING back into a cleared shipping_code);
 *   - a failing migrator is contained and still stamped (no retry storm);
 *   - without travel_core, or on the storefront, it does nothing at all.
 */
#[CoversNothing]
final class SettingsHealTest extends TestCase
{
    /**
     * @return array{calls: list<list<string>>, queries: list<string>, settings: array<string, array{type: string, value: string|null}>}
     */
    private static function simulate(string $area, string $mode): array
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/settings_heal_sim.php';
        $addonRoot = dirname(__DIR__, 3);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($addonRoot)
            . ' ' . escapeshellarg($area) . ' ' . escapeshellarg($mode) . ' 2>&1';
        exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);

        self::assertSame(0, $exitCode, $output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, "unexpected output:\n{$output}");

        /** @var array{calls: list<list<string>>, queries: list<string>, settings: array<string, array{type: string, value: string|null}>} $decoded */
        return $decoded;
    }

    /**
     * @param list<list<string>> $calls
     *
     * @return list<string>
     */
    private static function names(array $calls): array
    {
        return array_map(static fn (array $call): string => $call[0], $calls);
    }

    public function testAdminRequestCreatesPlacesAndMirrorsOnceThenIsThrottled(): void
    {
        $out = self::simulate('A', 'ok');

        self::assertSame(
            ['due', 'guard', 'ensure', 'stamp', 'due'],
            self::names($out['calls']),
            'the second page load sees the stamp and stops',
        );
        self::assertSame(
            ['ensure', 'fgo_invoicing', '/store/app/addons/fgo_invoicing', '/store/var/langs'],
            $out['calls'][2],
            'store paths, so symlinked add-ons heal too',
        );
        self::assertSame('fgo_invoicing_settings', $out['calls'][0][1]);

        $queries = array_map(static fn (string $q): string => substr($q, 0, 44), $out['queries']);
        self::assertSame(
            [
                'SELECT name, type, value FROM ?:settings_obj', // snapshot before
                'SELECT name, type, value FROM ?:settings_obj', // ... and after the migrator
                'UPDATE ?:settings_objects SET value = ?s, ty', // restore what it rewrote
                'UPDATE ?:settings_objects SET value = ?s, ty',
                'UPDATE ?:settings_objects SET value = ?s, ty',
                'SELECT name, position FROM ?:settings_object', // placement after creation
                'SELECT object_id, name FROM ?:settings_objec', // labels mirrored last
            ],
            $queries,
        );

        foreach (['cif_field', 'reg_com_field', 'cnp_field'] as $created) {
            self::assertArrayHasKey($created, $out['settings'], "{$created} is created");
        }
    }

    /**
     * REGRESSION: SettingsMigrator writes the addon.xml default into every
     * existing setting holding '' and re-applies declared types. Here that
     * would put CodArticol SHIPPING on the invoices of a merchant who cleared
     * the code, and turn "0 retries" of the issue POST back into 2.
     */
    public function testSettingsThatExistedKeepTheirValueAndType(): void
    {
        $out = self::simulate('A', 'ok');

        self::assertSame(['type' => 'I', 'value' => ''], $out['settings']['shipping_code']);
        self::assertSame(['type' => 'I', 'value' => ''], $out['settings']['api_max_retries']);
        self::assertSame('T', $out['settings']['invoice_series']['type'], 'the type is not re-applied either');
        self::assertSame('DISCOUNT', $out['settings']['discount_code']['value'], 'untouched settings stay as they were');
    }

    /**
     * A store whose rows are all there (fresh install, or healed already) must
     * never reach the migrator: nothing to add means nothing to "repair".
     */
    public function testAStoreWithEveryRowNeverCallsTheMigrator(): void
    {
        $out = self::simulate('A', 'in-sync');

        self::assertSame(['due', 'guard', 'stamp', 'due'], self::names($out['calls']));
        self::assertCount(1, $out['queries'], 'one snapshot query, nothing written');
        self::assertStringStartsWith('SELECT name, type, value FROM ?:settings_objects', $out['queries'][0]);
        self::assertSame(['type' => 'I', 'value' => ''], $out['settings']['shipping_code']);
    }

    public function testAFailingMigratorIsContainedAndStillStamped(): void
    {
        $out = self::simulate('A', 'ensure-throws');

        self::assertSame(['due', 'guard', 'ensure', 'guard-caught', 'stamp', 'due'], self::names($out['calls']));
        self::assertSame('Settings API unavailable', $out['calls'][3][1]);
        self::assertCount(1, $out['queries'], 'only the snapshot before the failed step');
        self::assertStringStartsWith('SELECT name, type, value FROM ?:settings_objects', $out['queries'][0]);
    }

    public function testTheStorefrontNeverHeals(): void
    {
        $out = self::simulate('C', 'ok');

        self::assertSame([], $out['calls']);
        self::assertSame([], $out['queries']);
    }

    /**
     * fgo installs without travel_core; there the new settings simply stay
     * absent and ConfigProvider reads them as "auto-detect".
     */
    public function testWithoutTravelCoreItIsANoOp(): void
    {
        $out = self::simulate('A', 'no-travel-core');

        self::assertSame([], $out['calls']);
        self::assertSame([], $out['queries']);
    }
}
