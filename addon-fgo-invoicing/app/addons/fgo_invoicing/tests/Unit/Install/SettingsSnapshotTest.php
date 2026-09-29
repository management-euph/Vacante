<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Install\SettingsSnapshot;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * The settings heal must only ADD rows: SettingsSnapshot tells whether
 * anything is missing and puts back what travel_core's migrator rewrote on
 * settings that already existed (a cleared shipping_code refilled with
 * SHIPPING would land on every invoice as CodArticol).
 */
#[CoversClass(SettingsSnapshot::class)]
final class SettingsSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testTakeReadsNameTypeAndValueOfThisSectionOnly(): void
    {
        DbStub::$rows = [
            ['name' => 'shipping_code', 'type' => 'I', 'value' => ''],
            ['name' => 'cif_field', 'type' => 'S', 'value' => null],
            ['name' => 'api_max_retries', 'type' => 'I', 'value' => 0],
            ['name' => '', 'type' => 'I', 'value' => 'no name'],
            'not a row',
        ];

        self::assertSame([
            'shipping_code' => ['type' => 'I', 'value' => ''],
            'cif_field' => ['type' => 'S', 'value' => null],
            'api_max_retries' => ['type' => 'I', 'value' => '0'],
        ], SettingsSnapshot::take());

        $select = DbStub::calls('FROM ?:settings_objects');
        self::assertCount(1, $select);
        self::assertSame(['fgo_invoicing'], $select[0]['params'], 'scoped to this add-on section');
    }

    public function testDeclaredNamesAreEveryItemOfTheShippedAddonXml(): void
    {
        $names = SettingsSnapshot::declaredNames(dirname(__DIR__, 3) . '/addon.xml');

        foreach (['customer_header', 'client_vat_required', 'cif_field', 'reg_com_field', 'cnp_field', 'shipping_code'] as $name) {
            self::assertContains($name, $names);
        }
        self::assertSame(array_values(array_unique($names)), $names, 'each name once');
        self::assertSame([], SettingsSnapshot::declaredNames(__DIR__ . '/no-such-addon.xml'));
    }

    public function testMissingIsWhatIsDeclaredButHasNoRow(): void
    {
        $snapshot = [
            'shipping_code' => ['type' => 'I', 'value' => ''],
            'client_cnp_required' => ['type' => 'C', 'value' => 'N'],
        ];

        self::assertSame(
            ['cif_field', 'cnp_field'],
            SettingsSnapshot::missing(['shipping_code', 'cif_field', 'client_cnp_required', 'cnp_field'], $snapshot),
        );
        self::assertSame([], SettingsSnapshot::missing(['shipping_code'], $snapshot));
    }

    public function testRestorePutsBackOnlyWhatChangedOnRowsThatExisted(): void
    {
        $before = [
            'shipping_code' => ['type' => 'I', 'value' => ''],
            'invoice_series' => ['type' => 'T', 'value' => 'F'],
            'discount_code' => ['type' => 'I', 'value' => 'DISCOUNT'],
            'healed_blank' => ['type' => 'S', 'value' => null],
        ];
        $after = [
            'shipping_code' => ['type' => 'I', 'value' => 'SHIPPING'],   // repairValue
            'invoice_series' => ['type' => 'I', 'value' => 'F'],         // repairType
            'discount_code' => ['type' => 'I', 'value' => 'DISCOUNT'],   // untouched
            'healed_blank' => ['type' => 'S', 'value' => '0'],
            'cif_field' => ['type' => 'S', 'value' => null],             // just created
        ];

        self::assertSame(['shipping_code', 'invoice_series', 'healed_blank'], SettingsSnapshot::restore($before, $after));

        $updates = DbStub::calls('UPDATE ?:settings_objects');
        self::assertCount(3, $updates);
        self::assertStringContainsString('SET value = ?s, type = ?s', $updates[0]['query']);
        self::assertSame(['', 'I', 'shipping_code', 'fgo_invoicing'], $updates[0]['params']);
        self::assertSame(['F', 'T', 'invoice_series', 'fgo_invoicing'], $updates[1]['params']);
        self::assertStringContainsString('SET value = NULL, type = ?s', $updates[2]['query'], 'a NULL value comes back as NULL, not as \'\'');
        self::assertSame(['S', 'healed_blank', 'fgo_invoicing'], $updates[2]['params']);
    }

    public function testRestoreWithNothingChangedWritesNothing(): void
    {
        $same = ['shipping_code' => ['type' => 'I', 'value' => '']];

        self::assertSame([], SettingsSnapshot::restore($same, $same));
        self::assertSame([], SettingsSnapshot::restore($same, []), 'a row that vanished is not recreated');
        self::assertSame([], DbStub::calls());
    }
}
