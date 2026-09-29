<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * findByOrderIds() backs the orders-list FGO column (every admin load of the
 * list) and the bulk pre-check: one query, never one per order.
 */
#[CoversClass(InvoiceRepository::class)]
final class InvoiceRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testManyOrdersAreReadInOneQueryAndKeyedByOrder(): void
    {
        DbStub::$rows = [
            ['order_id' => '7', 'status' => 'issued', 'invoice_number' => '0002'],
            ['order_id' => '3', 'status' => 'failed'],
            ['order_id' => '0', 'status' => 'junk'],
        ];

        $rows = (new InvoiceRepository())->findByOrderIds([3, 7, 7, 0, -2, 9]);

        self::assertSame([7, 3], array_keys($rows));
        self::assertSame('issued', $rows[7]['status']);
        $calls = DbStub::calls('?:fgo_invoices');
        self::assertCount(1, $calls);
        self::assertStringContainsString('WHERE order_id IN (?n)', $calls[0]['query']);
        self::assertSame([[3, 7, 9]], $calls[0]['params'], 'positive, de-duplicated ids');
    }

    /**
     * The payload columns are kilobytes of JSON per row that neither the list
     * nor the pre-check shows.
     */
    public function testOnlyTheSummaryColumnsAreRead(): void
    {
        (new InvoiceRepository())->findByOrderIds([1]);

        $query = DbStub::calls('?:fgo_invoices')[0]['query'];
        self::assertStringNotContainsString('*', $query);
        self::assertStringNotContainsString('payload', $query);
        foreach (['status', 'invoice_series', 'invoice_number', 'pdf_link', 'last_error'] as $column) {
            self::assertStringContainsString($column, $query);
        }
    }

    public function testNoIdsNoQuery(): void
    {
        self::assertSame([], (new InvoiceRepository())->findByOrderIds([]));
        self::assertSame([], (new InvoiceRepository())->findByOrderIds([0, -1]));
        self::assertSame([], DbStub::calls());
    }
}
