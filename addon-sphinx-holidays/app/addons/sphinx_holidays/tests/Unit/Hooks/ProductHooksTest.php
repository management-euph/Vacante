<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Hooks\ProductHooks;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelLinkingRepository;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Products deleted in the admin left their hotels linked to the deleted id:
 * the dashboard counted them as linked and "Create Products" never made
 * them a new one. A deleted product now unlinks its hotel or circuit, and
 * the links already left behind are cleared before products are made.
 */
final class ProductHooksTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        Container::reset();
        $this->queries = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            $this->queries[] = [$query, array_values($params)];

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Container::reset();
    }

    public function testADeletedProductUnlinksItsHotelAndCircuit(): void
    {
        ProductHooks::unlinkDeleted('861', true);

        self::assertCount(2, $this->queries);
        self::assertStringContainsString("UPDATE ?:sphinx_hotels SET product_id = NULL, gate_hidden = 'N', gate_released = 'N' WHERE product_id = ?i", $this->queries[0][0]);
        self::assertStringContainsString("UPDATE ?:sphinx_circuits SET product_id = NULL, product_hidden = 'N' WHERE product_id = ?i", $this->queries[1][0]);
        self::assertSame([861], $this->queries[0][1]);
        self::assertSame([861], $this->queries[1][1]);
    }

    public function testNothingWhenTheProductWasNotDeleted(): void
    {
        ProductHooks::unlinkDeleted(861, false);
        ProductHooks::unlinkDeleted(0, true);

        self::assertSame([], $this->queries);
    }

    public function testLinksToMissingProductsAreCleared(): void
    {
        self::assertSame(1, (new HotelLinkingRepository())->unlinkMissingProducts());
        self::assertSame(1, (new CircuitRepository())->unlinkMissingProducts());

        [$hotels, $circuits] = array_column($this->queries, 0);
        self::assertStringContainsString('LEFT JOIN ?:products p ON p.product_id = h.product_id', $hotels);
        self::assertStringContainsString("SET h.product_id = NULL, h.gate_hidden = 'N', h.gate_released = 'N'", $hotels);
        self::assertStringContainsString('WHERE h.product_id > 0 AND p.product_id IS NULL', $hotels);
        self::assertStringContainsString('LEFT JOIN ?:products p ON p.product_id = c.product_id', $circuits);
        self::assertStringContainsString("SET c.product_id = NULL, c.product_hidden = 'N'", $circuits);
        self::assertStringContainsString('WHERE c.product_id > 0 AND p.product_id IS NULL', $circuits);
    }

    /** The hook is registered, and both product runs clear old links before they look for work. */
    public function testTheWiring(): void
    {
        $root = dirname(__DIR__, 3);
        $init = (string) file_get_contents($root . '/init.php');
        $func = (string) file_get_contents($root . '/func.php');

        self::assertMatchesRegularExpression("/fn_register_hooks\\([^;]*'delete_product_post'/s", $init);
        self::assertStringContainsString('ProductHooks::unlinkDeleted($product_id, $product_deleted);', $func);

        foreach ([
            '/src/Cron/Commands/AddProductsCommand.php' => '$hotelRepo->findUnlinked(',
            '/src/Cron/Commands/AddCircuitProductsCommand.php' => '$repo->findUnlinked(',
            '/controllers/backend/sphinx_holidays.php' => "\$hotelRepo->findUnlinked('', 1)",
        ] as $file => $find) {
            $code = (string) file_get_contents($root . $file);
            $clear = strpos($code, 'unlinkMissingProducts()');
            self::assertNotFalse($clear, $file);
            self::assertLessThan(strpos($code, $find), $clear, $file . ': before it looks for work');
        }
    }
}
