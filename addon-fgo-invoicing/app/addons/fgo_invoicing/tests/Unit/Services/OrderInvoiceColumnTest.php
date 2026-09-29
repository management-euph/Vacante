<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\OrderInvoiceColumn;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;

/**
 * The FGO column of the orders list: every listed order gets its cell data
 * from ONE lookup, and nothing stored can turn into an unsafe link.
 */
#[CoversClass(OrderInvoiceColumn::class)]
final class OrderInvoiceColumnTest extends TestCase
{
    public function testEveryListedOrderGetsItsCellFromOneLookup(): void
    {
        $repo = (new InMemoryInvoiceRepository())
            ->put(1, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002', 'pdf_link' => 'https://api.fgo.ro/p/2'])
            ->put(2, ['status' => 'failed', 'last_error' => "CIF\ninvalid"]);

        $orders = OrderInvoiceColumn::attach([
            ['order_id' => '1', 'total' => 10],
            ['order_id' => 2],
            ['order_id' => 3],
        ], $repo);

        self::assertSame([[1, 2, 3]], $repo->batchLookups, 'one query for the page');
        self::assertIsArray($orders[0]);
        self::assertSame([
            'status' => 'issued',
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'last_error' => '',
        ], $orders[0]['fgo_invoice']);
        self::assertSame(10, $orders[0]['total'], 'the rest of the row is untouched');
        self::assertIsArray($orders[1]);
        self::assertIsArray($orders[1]['fgo_invoice']);
        self::assertSame('failed', $orders[1]['fgo_invoice']['status']);
        self::assertSame('CIF invalid', $orders[1]['fgo_invoice']['last_error']);
        self::assertIsArray($orders[2]);
        self::assertIsArray($orders[2]['fgo_invoice']);
        self::assertSame(OrderInvoiceColumn::STATUS_NONE, $orders[2]['fgo_invoice']['status'], '"not invoiced" is explicit');
    }

    public function testNoOrdersNoQuery(): void
    {
        $repo = new InMemoryInvoiceRepository();

        self::assertSame([], OrderInvoiceColumn::attach([], $repo));
        self::assertSame([['x' => 1], 'junk'], OrderInvoiceColumn::attach([['x' => 1], 'junk'], $repo));
        self::assertSame([], $repo->batchLookups);
    }

    public function testRowsThatAreNotOrdersAreLeftAlone(): void
    {
        $repo = new InMemoryInvoiceRepository();

        $orders = OrderInvoiceColumn::attach(['junk', ['order_id' => 0], ['order_id' => 4]], $repo);

        self::assertSame('junk', $orders[0]);
        self::assertSame(['order_id' => 0], $orders[1]);
        self::assertIsArray($orders[2]);
        self::assertArrayHasKey('fgo_invoice', $orders[2]);
    }

    public function testOnlyAnHttpsLinkIsKept(): void
    {
        self::assertSame('https://api.fgo.ro/p/1?x=1&y=2', OrderInvoiceColumn::safeLink(' https://api.fgo.ro/p/1?x=1&y=2 '));
        self::assertSame('', OrderInvoiceColumn::safeLink('javascript:alert(1)'));
        self::assertSame('', OrderInvoiceColumn::safeLink('http://api.fgo.ro/p/1'));
        self::assertSame('', OrderInvoiceColumn::safeLink('https://api.fgo.ro/p/1" onmouseover="x'));
        self::assertSame('', OrderInvoiceColumn::safeLink(''));
    }

    public function testAnUnknownStatusIsShownAsPending(): void
    {
        self::assertSame('pending', OrderInvoiceColumn::cell(['status' => 'weird'])['status']);
        self::assertSame('canceled', OrderInvoiceColumn::cell(['status' => 'CANCELED'])['status']);
    }

    public function testALongErrorIsShortenedForTheTooltip(): void
    {
        $cell = OrderInvoiceColumn::cell(['status' => 'failed', 'last_error' => str_repeat('x', 500)]);

        self::assertSame(OrderInvoiceColumn::ERROR_MAX, mb_strlen($cell['last_error']));
        self::assertStringEndsWith('…', $cell['last_error']);
    }
}
