<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Install\SettingsPlacement;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * On an existing store the settings heal appends new settings at the END of
 * the form (under "Resilience & Diagnostics"). SettingsPlacement moves them
 * back under the header addon.xml declares them in — and must leave a fresh
 * install, which already has the right order, completely alone.
 */
#[CoversClass(SettingsPlacement::class)]
final class SettingsPlacementTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @return array<string, int> name => position, from the recorded UPDATEs
     */
    private static function movedTo(): array
    {
        $moved = [];
        foreach (DbStub::calls('UPDATE ?:settings_objects SET position') as $call) {
            $moved[(string) $call['params'][1]] = (int) $call['params'][0];
        }

        return $moved;
    }

    public function testSettingsAppendedByTheHealAreMovedUnderTheirHeader(): void
    {
        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => '150'],
            ['name' => 'lines_header', 'position' => '160'],
            ['name' => 'debug_logging', 'position' => '300'],
            ['name' => 'cif_field', 'position' => '310'],
            ['name' => 'reg_com_field', 'position' => '320'],
            ['name' => 'cnp_field', 'position' => '330'],
        ];

        self::assertSame(3, SettingsPlacement::apply());
        self::assertSame(['cif_field' => 152, 'reg_com_field' => 154, 'cnp_field' => 156], self::movedTo());

        $update = DbStub::calls('UPDATE ?:settings_objects SET position')[0];
        self::assertSame('fgo_invoicing', $update['params'][2], 'scoped to this add-on section');
        $select = DbStub::calls('SELECT name, position')[0];
        self::assertSame('fgo_invoicing', $select['params'][0]);
        self::assertSame(
            ['client_cnp_required', 'lines_header', 'cif_field', 'reg_com_field', 'cnp_field'],
            $select['params'][1],
        );
    }

    public function testAFreshInstallInAddonXmlOrderIsLeftAlone(): void
    {
        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => 150],
            ['name' => 'cif_field', 'position' => 160],
            ['name' => 'reg_com_field', 'position' => 170],
            ['name' => 'cnp_field', 'position' => 180],
            ['name' => 'lines_header', 'position' => 190],
        ];

        self::assertSame(0, SettingsPlacement::apply());
        self::assertSame([], self::movedTo());
    }

    public function testOnlyTheSettingsPresentAreSpaced(): void
    {
        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => 100],
            ['name' => 'lines_header', 'position' => 110],
            ['name' => 'cnp_field', 'position' => 400],
        ];

        self::assertSame(1, SettingsPlacement::placeBetween('client_cnp_required', 'lines_header', ['cif_field', 'cnp_field']));
        self::assertSame(['cnp_field' => 105], self::movedTo());
    }

    public function testNothingMovesWithoutBothAnchorsOrWithoutRoom(): void
    {
        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => 100],
            ['name' => 'cif_field', 'position' => 400],
        ];
        self::assertSame(0, SettingsPlacement::apply(), 'the next header is missing');

        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => 100],
            ['name' => 'lines_header', 'position' => 102],
            ['name' => 'cif_field', 'position' => 400],
            ['name' => 'reg_com_field', 'position' => 410],
            ['name' => 'cnp_field', 'position' => 420],
        ];
        self::assertSame(0, SettingsPlacement::apply(), 'no room for three between 100 and 102');

        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => 100],
            ['name' => 'lines_header', 'position' => 110],
        ];
        self::assertSame(0, SettingsPlacement::apply(), 'none of the settings exists yet');

        self::assertSame(0, SettingsPlacement::placeBetween('a', 'b', []));
        self::assertSame([], self::movedTo());
    }

    public function testASettingAlreadyOnItsTargetIsNotRewritten(): void
    {
        DbStub::$rows = [
            ['name' => 'client_cnp_required', 'position' => 100],
            ['name' => 'lines_header', 'position' => 110],
            ['name' => 'cif_field', 'position' => 102],
            ['name' => 'reg_com_field', 'position' => 500],
            ['name' => 'cnp_field', 'position' => 106],
        ];

        self::assertSame(1, SettingsPlacement::apply());
        self::assertSame(['reg_com_field' => 104], self::movedTo());
    }
}
