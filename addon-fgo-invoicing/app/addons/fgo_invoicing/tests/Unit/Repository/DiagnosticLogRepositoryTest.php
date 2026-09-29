<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Repository\DiagnosticLogRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

#[CoversClass(DiagnosticLogRepository::class)]
final class DiagnosticLogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
        DiagnosticLogRepository::resetEnsured();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        DiagnosticLogRepository::resetEnsured();
    }

    public function testRecordInsertsOneRowWithTheDiagnosticColumns(): void
    {
        (new DiagnosticLogRepository())->record(
            1234,
            false,
            12,
            'rejected/200',
            'CNP invalid',
            ['Client[Tip]' => 'PF', 'Client[CodUnic]' => 'INVALID_LENGTH_12'],
        );

        $inserts = DbStub::calls('INSERT INTO ?:fgo_diagnostic_logs');
        self::assertCount(1, $inserts);
        self::assertSame(
            [
                'order_id' => 1234,
                'is_cnp_checksum_valid' => 0,
                'cnp_length' => 12,
                'fgo_response_code' => 'rejected/200',
                'fgo_error_message' => 'CNP invalid',
                'request_payload' => '{"Client[Tip]":"PF","Client[CodUnic]":"INVALID_LENGTH_12"}',
            ],
            $inserts[0]['params'][0],
        );
    }

    public function testNoCnpIsStoredAsNullNotAsFalse(): void
    {
        (new DiagnosticLogRepository())->record(1, null, null, 'success', '', []);

        $row = DbStub::calls('INSERT INTO ?:fgo_diagnostic_logs')[0]['params'][0];
        self::assertIsArray($row);
        self::assertNull($row['is_cnp_checksum_valid']);
        self::assertNull($row['cnp_length']);
        self::assertSame('', $row['request_payload'], 'nothing was sent');
    }

    public function testTheResponseCodeFitsItsColumn(): void
    {
        (new DiagnosticLogRepository())->record(1, null, null, str_repeat('x', 40), '', []);

        $row = DbStub::calls('INSERT INTO ?:fgo_diagnostic_logs')[0]['params'][0];
        self::assertIsArray($row);
        self::assertSame(32, mb_strlen((string) $row['fgo_response_code']));
    }

    public function testAnInvalidOrderIdWritesNothing(): void
    {
        (new DiagnosticLogRepository())->record(0, null, null, 'success', '', []);

        self::assertSame([], DbStub::calls());
    }

    public function testTheTableIsEnsuredOncePerProcess(): void
    {
        $repo = new DiagnosticLogRepository();
        $repo->record(1, null, null, 'success', '', []);
        $repo->record(2, null, null, 'success', '', []);
        $repo->listForOrder(1);

        self::assertCount(1, DbStub::calls('CREATE TABLE IF NOT EXISTS `?:fgo_diagnostic_logs`'));
        self::assertCount(2, DbStub::calls('INSERT INTO ?:fgo_diagnostic_logs'));
    }

    public function testListForOrderReadsNewestFirstWithAClampedLimit(): void
    {
        DbStub::$rows = [['log_id' => 2, 'order_id' => 5], 'not a row', ['log_id' => 1, 'order_id' => 5]];

        $rows = (new DiagnosticLogRepository())->listForOrder(5, 5000);

        self::assertSame([['log_id' => 2, 'order_id' => 5], ['log_id' => 1, 'order_id' => 5]], $rows);
        $select = DbStub::calls('FROM ?:fgo_diagnostic_logs')[0];
        self::assertStringContainsString('ORDER BY log_id DESC', $select['query']);
        self::assertSame([5, 200], $select['params']);
        self::assertSame([], (new DiagnosticLogRepository())->listForOrder(0));
    }

    /**
     * The install query and the query existing stores get from ensureTable()
     * must create the same table.
     */
    public function testTheCreateStatementMatchesAddonXml(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../addon.xml');
        self::assertNotFalse($xml);
        $installQuery = null;
        foreach ($xml->queries->item as $item) {
            if ((string) $item['for'] === 'install' && str_contains((string) $item, 'fgo_diagnostic_logs')) {
                $installQuery = (string) $item;
            }
        }
        self::assertNotNull($installQuery, 'addon.xml creates ?:fgo_diagnostic_logs on install');

        $normalise = static fn (string $sql): string => trim((string) preg_replace('/\s+/', ' ', $sql));
        self::assertSame($normalise(DiagnosticLogRepository::CREATE_SQL), $normalise($installQuery));

        $uninstall = [];
        foreach ($xml->queries->item as $item) {
            if ((string) $item['for'] === 'uninstall') {
                $uninstall[] = trim((string) $item);
            }
        }
        self::assertContains('DROP TABLE IF EXISTS `?:fgo_diagnostic_logs`', $uninstall);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAStampedStoreSkipsTheDdl(): void
    {
        $GLOBALS['fgo_test_storage'] = ['fgo_invoicing_diagnostic_logs_table' => DiagnosticLogRepository::TABLE_VERSION];
        eval('function fn_get_storage_data($key) { return $GLOBALS["fgo_test_storage"][$key] ?? null; }
              function fn_set_storage_data($key, $value) { $GLOBALS["fgo_test_storage"][$key] = $value; }');

        (new DiagnosticLogRepository())->ensureTable();

        self::assertSame([], DbStub::calls('CREATE TABLE'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnUnstampedStoreCreatesTheTableAndStampsIt(): void
    {
        $GLOBALS['fgo_test_storage'] = [];
        eval('function fn_get_storage_data($key) { return $GLOBALS["fgo_test_storage"][$key] ?? null; }
              function fn_set_storage_data($key, $value) { $GLOBALS["fgo_test_storage"][$key] = $value; }');

        (new DiagnosticLogRepository())->ensureTable();

        self::assertCount(1, DbStub::calls('CREATE TABLE IF NOT EXISTS `?:fgo_diagnostic_logs`'));
        self::assertSame(DiagnosticLogRepository::TABLE_VERSION, $GLOBALS['fgo_test_storage']['fgo_invoicing_diagnostic_logs_table']);
    }
}
