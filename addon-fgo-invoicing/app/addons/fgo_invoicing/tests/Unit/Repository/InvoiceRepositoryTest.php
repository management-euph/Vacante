<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * findByOrderIds() backs the orders-list FGO column (every admin load of the
 * list) and the bulk pre-check: one query, never one per order.
 *
 * The row is also the claim on an order's FGO call: the claim and the
 * conditional writes are pinned to what CS-Cart's db_query() returns for a
 * write (the new AUTO_INCREMENT id, else the affected-row count), which
 * DbStub::$queryResults stands in for.
 */
#[CoversClass(InvoiceRepository::class)]
final class InvoiceRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
        InvoiceRepository::resetSchemaState(true);
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        InvoiceRepository::resetSchemaState();
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
     * nor the pre-check shows. The ages are computed by MySQL (no PHP/DB time
     * zone mismatch).
     */
    public function testOnlyTheSummaryColumnsAreRead(): void
    {
        (new InvoiceRepository())->findByOrderIds([1]);

        $query = DbStub::calls('?:fgo_invoices')[0]['query'];
        self::assertStringNotContainsString('*', $query);
        self::assertStringNotContainsString('payload', $query);
        foreach (['status', 'invoice_series', 'invoice_number', 'pdf_link', 'last_error', 'emailed_at'] as $column) {
            self::assertStringContainsString($column, $query);
        }
        self::assertStringContainsString('TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS updated_age', $query);
        self::assertStringContainsString('TIMESTAMPDIFF(SECOND, emailed_at, NOW()) AS emailed_age', $query);
    }

    public function testWithoutTheNewColumnTheListStillLoads(): void
    {
        InvoiceRepository::resetSchemaState(false);

        (new InvoiceRepository())->findByOrderIds([1]);

        $query = DbStub::calls('?:fgo_invoices')[0]['query'];
        self::assertStringNotContainsString('emailed', $query);
        self::assertStringContainsString('updated_age', $query);
    }

    public function testNoIdsNoQuery(): void
    {
        self::assertSame([], (new InvoiceRepository())->findByOrderIds([]));
        self::assertSame([], (new InvoiceRepository())->findByOrderIds([0, -1]));
        self::assertSame([], DbStub::calls());
    }

    /**
     * REGRESSION: CS-Cart's db_get_row() answers "no such row" with [], not
     * false. findByOrderId() returned that [] as a row, so insertPending()
     * took every new order for an existing row and never inserted one: the
     * invoice was issued at FGO and recorded nowhere, and issued again on
     * the next trigger.
     */
    public function testAMissingRowIsNullEvenAsAnEmptyArray(): void
    {
        DbStub::$row = [];
        self::assertNull((new InvoiceRepository())->findByOrderId(5));

        DbStub::$row = false;
        self::assertNull((new InvoiceRepository())->findByOrderId(5));
        self::assertNull((new InvoiceRepository())->findByOrderId(0));
    }

    public function testTheRowComesWithItsAge(): void
    {
        DbStub::$row = ['order_id' => '5', 'status' => 'pending', 'updated_age' => '12'];

        $row = (new InvoiceRepository())->findByOrderId(5);

        self::assertSame('12', $row['updated_age'] ?? null);
        self::assertStringStartsWith('SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS updated_age', DbStub::calls()[0]['query']);
    }

    // ── The claim ───────────────────────────────────────────────────────

    public function testInsertPendingClaimsOnlyWhenThisCallInsertedTheRow(): void
    {
        DbStub::$row = ['id' => '41', 'order_id' => '5', 'status' => 'pending'];
        DbStub::$queryResults = [41];

        $won = (new InvoiceRepository())->insertPending(5);

        self::assertTrue($won['claimed']);
        self::assertFalse($won['isExisting']);
        self::assertSame(41, $won['id']);
        $insert = DbStub::calls('INSERT IGNORE')[0]['query'];
        self::assertStringStartsWith('INSERT IGNORE INTO ?:fgo_invoices', $insert, 'the verb at offset 0: core counts rows by it');

        // The UNIQUE key refused the row: no id, 0 rows affected.
        DbStub::$queryResults = [0];
        DbStub::$row = ['id' => '41', 'order_id' => '5', 'status' => 'issued'];

        $lost = (new InvoiceRepository())->insertPending(5);

        self::assertFalse($lost['claimed']);
        self::assertTrue($lost['isExisting']);
        self::assertSame('issued', $lost['status']);
    }

    public function testClaimForRetryTakesOnlyAClaimableRowAndOnlyWhenOneRowChanged(): void
    {
        $repo = new InvoiceRepository();

        DbStub::$queryResults = [1];
        self::assertTrue($repo->claimForRetry(5));
        $call = DbStub::calls('UPDATE')[0];
        self::assertStringStartsWith('UPDATE ?:fgo_invoices SET status = ?s, updated_at = NOW()', $call['query']);
        self::assertStringContainsString('status IN (?a) OR (status = ?s AND updated_at < NOW() - INTERVAL ?i SECOND)', $call['query']);
        self::assertSame(
            ['pending', 5, ['failed', 'canceled', 'reversed', 'deleted'], 'pending', Constants::PENDING_STALE_SECONDS],
            $call['params'],
            'never an issued row, a pending one only once stale',
        );

        DbStub::$queryResults = [0];
        self::assertFalse($repo->claimForRetry(5), 'another request holds it, or it is issued');
        self::assertFalse($repo->claimForRetry(0));
    }

    public function testTheResultIsWrittenOnlyOverThisRequestsPendingRow(): void
    {
        $repo = new InvoiceRepository();
        $response = IssueInvoiceResponse::fromApiResponse(['Success' => true, 'Factura' => ['Serie' => 'F', 'Numar' => '0003']]);

        DbStub::$queryResults = [1, 0, 1, 0];
        self::assertTrue($repo->markIssued(5, $response, []));
        self::assertFalse($repo->markIssued(5, $response, []));
        self::assertTrue($repo->markFailed(5, 'boom', []));
        self::assertFalse($repo->markFailed(5, 'boom', []));

        foreach (DbStub::calls('UPDATE') as $call) {
            self::assertStringEndsWith('WHERE order_id = ?i AND status = ?s', $call['query']);
            self::assertSame(['5', 'pending'], array_map('strval', array_slice($call['params'], -2)));
            self::assertStringNotContainsString('invoice_series = NULL', $call['query']);
        }
        self::assertStringNotContainsString('invoice_number', DbStub::calls('status = ?s, success = 0')[0]['query'], 'a failure keeps the series and number');
    }

    public function testTheCancelFamilyWritesOnlyAnIssuedRow(): void
    {
        $repo = new InvoiceRepository();

        DbStub::$queryResults = [1, 0, 1];
        self::assertTrue($repo->markCanceled(5));
        self::assertFalse($repo->markReversed(5));
        self::assertTrue($repo->markDeleted(5));

        $calls = DbStub::calls('UPDATE');
        self::assertSame(['canceled', 5, 'issued'], $calls[0]['params']);
        self::assertSame(['reversed', 5, 'issued'], $calls[1]['params']);
        self::assertSame(['deleted', 5, 'issued'], $calls[2]['params']);
        self::assertStringEndsWith('WHERE order_id = ?i AND status = ?s', $calls[0]['query']);
    }

    // ── emailed_at ──────────────────────────────────────────────────────

    public function testMarkEmailedLeavesUpdatedAtAlone(): void
    {
        DbStub::$queryResults = [1];

        self::assertTrue((new InvoiceRepository())->markEmailed(5));

        $query = DbStub::calls('UPDATE')[0]['query'];
        self::assertSame('UPDATE ?:fgo_invoices SET emailed_at = NOW(), updated_at = updated_at WHERE order_id = ?i', $query);
    }

    public function testMarkEmailedIsANoOpWithoutTheColumn(): void
    {
        InvoiceRepository::resetSchemaState(false);

        self::assertFalse((new InvoiceRepository())->markEmailed(5));
        self::assertSame([], DbStub::calls());
    }

    /**
     * A store installed before emailed_at existed gets it added, guarded by
     * information_schema (the real, prefixed table name: '?:' is replaced
     * inside the quotes too), once.
     */
    public function testEnsureSchemaAddsAMissingColumnOnce(): void
    {
        InvoiceRepository::resetSchemaState();
        DbStub::$field = '0';

        self::assertTrue((new InvoiceRepository())->ensureSchema());
        self::assertTrue((new InvoiceRepository())->ensureSchema());

        $lookups = DbStub::calls('information_schema.COLUMNS');
        self::assertCount(1, $lookups, 'once per process');
        self::assertStringContainsString("TABLE_NAME = '?:fgo_invoices' AND COLUMN_NAME = ?s", $lookups[0]['query']);
        self::assertSame(['emailed_at'], $lookups[0]['params']);
        $alter = DbStub::calls('ALTER TABLE');
        self::assertCount(1, $alter);
        self::assertSame('ALTER TABLE ?:fgo_invoices ADD COLUMN `emailed_at` ' . InvoiceRepository::ADDED_COLUMNS['emailed_at'], $alter[0]['query']);
    }

    public function testEnsureSchemaSkipsAColumnThatExists(): void
    {
        InvoiceRepository::resetSchemaState();
        DbStub::$field = '1';

        self::assertTrue((new InvoiceRepository())->ensureSchema());
        self::assertSame([], DbStub::calls('ALTER TABLE'));
    }

    /**
     * addon.xml's CREATE TABLE (fresh installs) and the migration (older
     * installs) must add the same column.
     */
    public function testAddonXmlCreatesTheAddedColumns(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../../addon.xml');

        foreach (InvoiceRepository::ADDED_COLUMNS as $name => $definition) {
            self::assertStringContainsString('`' . $name . '` ' . $definition, $xml, $name);
        }
    }
}
