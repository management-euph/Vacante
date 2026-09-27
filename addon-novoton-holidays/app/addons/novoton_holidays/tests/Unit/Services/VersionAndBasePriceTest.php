<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\Commands\PriceComputeCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

final class VersionAndBasePriceTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** The admin shows the running code's version, and every declaration agrees. */
    public function testTheVersionIsTheCodesAndDeclaredOnce(): void
    {
        $xml = simplexml_load_file(self::root() . '/addon.xml');
        self::assertNotFalse($xml);
        $manifest = json_decode((string) file_get_contents(self::root() . '/manifest.json'), true);
        self::assertIsArray($manifest);

        self::assertSame(Constants::VERSION, (string) $xml->version);
        self::assertSame(Constants::VERSION, $manifest['version'] ?? null);
        self::assertSame(Constants::VERSION, ConfigProvider::getVersion());
    }

    /** CS-Cart 4 keeps prices in ?:product_prices: the base row, created when missing. */
    public function testTheCatalogPriceGoesToTheBasePriceRow(): void
    {
        $seen = [];
        DbStub::$query = static function (string $sql, mixed ...$params) use (&$seen): int {
            $seen[] = [trim((string) preg_replace('/\s+/', ' ', $sql)), $params];

            return 1;
        };

        PriceComputeCommand::writeBasePrice(14, 40.0);
        PriceComputeCommand::writeBasePrice(0, 40.0);

        self::assertCount(1, $seen, 'no product, no write');
        self::assertSame(
            'INSERT INTO ?:product_prices (product_id, price, lower_limit, usergroup_id) VALUES (?i, ?d, 1, 0) ON DUPLICATE KEY UPDATE price = VALUES(price)',
            $seen[0][0],
        );
        self::assertSame([14, 40.0], $seen[0][1]);
    }

    /** "Unknown column 'price' in 'SET' (1054)": nothing writes a price into ?:products any more. */
    public function testNothingWritesAPriceIntoTheProductsTable(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/UPDATE \?:products SET price/', $src, $path);
        }
    }
}
