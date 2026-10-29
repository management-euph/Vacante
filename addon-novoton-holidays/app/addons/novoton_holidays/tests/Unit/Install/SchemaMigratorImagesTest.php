<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Install\SchemaMigrator;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * The photo refresh's table and column reach a store that already has the
 * add-on (SchemaMigrator) exactly as a new install gets them (addon.xml).
 */
final class SchemaMigratorImagesTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        (new \ReflectionProperty(SchemaMigrator::class, 'done'))->setValue(null, false);
        DbStub::$query = function (string $query): int {
            $this->queries[] = $query;

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        (new \ReflectionProperty(SchemaMigrator::class, 'done'))->setValue(null, false);
    }

    private static function addonXml(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/addon.xml');
    }

    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    public function testANewInstallAndAnUpgradeGetTheSameTable(): void
    {
        $xml = self::addonXml();
        self::assertSame(1, preg_match('/CREATE TABLE IF NOT EXISTS `\?:novoton_hotel_images`.*?COMMENT=\'[^\']*\'/s', $xml, $m));
        self::assertSame(self::squash(SchemaMigrator::IMAGES_TABLE), self::squash($m[0]));
        self::assertStringContainsString('<item for="uninstall">DROP TABLE IF EXISTS `?:novoton_hotel_images`</item>', $xml);

        foreach (array_keys(SchemaMigrator::HOTEL_COLUMNS) as $column) {
            self::assertStringContainsString("`{$column}` datetime", $xml, "{$column} is in the novoton_hotels CREATE");
        }
    }

    public function testAnInstalledStoreGetsTheTableAndTheColumn(): void
    {
        DbStub::$getArray = static fn (string $query, mixed ...$params): array => $params === ['cscart_novoton_hotels']
            ? [['n' => 'hotel_id'], ['n' => 'hotelinfo_due_at'], ['n' => 'hotelinfo_tried_at']]
            : [['n' => 'price_changed_at']];

        SchemaMigrator::ensure('cscart_');

        self::assertCount(2, $this->queries);
        self::assertStringContainsString('ALTER TABLE ?:novoton_hotels ADD COLUMN `images_checked_at`', $this->queries[0]);
        self::assertSame(SchemaMigrator::IMAGES_TABLE, $this->queries[1]);
    }

    public function testAStoreWithoutTheAddOnIsLeftAlone(): void
    {
        DbStub::$getArray = static fn (): array => [];

        SchemaMigrator::ensure('cscart_');

        self::assertSame([], $this->queries);
    }
}
