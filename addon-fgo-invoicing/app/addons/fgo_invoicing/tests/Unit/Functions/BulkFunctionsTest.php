<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Functions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkPrecheck;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunResult;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckReason;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Registry;

/**
 * functions/bulk.php: the CS-Cart glue between the bulk services and the
 * page (translation, the AJAX answer, the ZIP). Separate processes: the
 * CS-Cart functions it calls (__, fn_url, ...) are stubbed as globals.
 */
#[CoversNothing]
final class BulkFunctionsTest extends TestCase
{
    /**
     * Global stand-ins for __() and fn_url() (eval: a function declared in
     * this namespaced file would not be global).
     */
    private static function boot(): void
    {
        if (!function_exists('__')) {
            eval('function __($key, $params = []) {
                $out = "<" . $key . ">";
                return $params === [] ? $out : $out . " " . implode("|", array_map("strval", $params));
            }');
        }
        if (!function_exists('fn_url')) {
            eval('function fn_url($url) { return "admin.php?dispatch=" . str_replace("?", "&", $url); }');
        }
        require_once dirname(__DIR__, 3) . '/functions/bulk.php';
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheResultPayloadCarriesTranslatedReasonsAndTheInvoiceLink(): void
    {
        self::boot();
        $result = new BulkRunResult(9, BulkRunResult::OUTCOME_SKIPPED, '', [
            new PrecheckReason('already_invoiced', PrecheckReason::LEVEL_INFO, ['[invoice]' => 'F 2']),
        ], 'F', '2');

        $payload = fn_fgo_invoicing_bulk_result_payload($result);

        self::assertSame('skipped', $payload['outcome']);
        self::assertSame('<fgo_invoicing.pc_already_invoiced> F 2', $payload['message']);
        self::assertSame('admin.php?dispatch=fgo_invoicing.view&order_id=9', $payload['view_url']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAMessageFromFgoIsShownAsItCame(): void
    {
        self::boot();

        $payload = fn_fgo_invoicing_bulk_result_payload(new BulkRunResult(9, BulkRunResult::OUTCOME_FAILED, 'CIF invalid'));

        self::assertSame('CIF invalid', $payload['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARowViewHasItsVerdictAndReasonTexts(): void
    {
        self::boot();
        ConfigProvider::seed([]);
        $row = (new BulkPrecheck(new BillingMapper('RON')))->check(
            BulkAction::Issue,
            ['order_id' => 3, 'status' => 'P', 'total' => 0, 'b_firstname' => 'Ion', 'b_country' => 'RO'],
            null,
        );

        $view = fn_fgo_invoicing_bulk_row_view($row);

        self::assertSame('<fgo_invoicing.verdict_warn>', $view['verdict_label']);
        self::assertSame([['level' => 'warn', 'text' => '<fgo_invoicing.pc_zero_total>']], $view['lines']);
        self::assertSame(3, $view['order_id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStatusNamesAreKeyedByUpperCaseCode(): void
    {
        self::boot();
        self::assertSame([], fn_fgo_invoicing_order_status_names(), 'no core, no names');

        eval('function fn_get_simple_statuses($type) { return ["p" => "Processed", "N" => "Incomplete"]; }');

        self::assertSame(['P' => 'Processed', 'N' => 'Incomplete'], fn_fgo_invoicing_order_status_names());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheAjaxAnswerIsOnlyWrittenInAnAjaxRequest(): void
    {
        self::boot();
        require_once dirname(__DIR__, 2) . '/Fixtures/tygh_app_stub.php';
        $ajax = new \FgoTestAjax();
        \Tygh::$app = new \ArrayObject(['ajax' => $ajax]);

        self::assertFalse(fn_fgo_invoicing_ajax_assign('fgo_result', ['x' => 1]));
        self::assertSame([], $ajax->assigned);

        define('AJAX_REQUEST', true);
        self::assertTrue(fn_fgo_invoicing_ajax_assign('fgo_result', ['x' => 1]));
        self::assertSame(['fgo_result' => ['x' => 1]], $ajax->assigned);

        \Tygh::$app = new \ArrayObject([]);
        self::assertFalse(fn_fgo_invoicing_ajax_assign('fgo_result', 1), 'no AJAX response object');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[RequiresPhpExtension('zip')]
    public function testTheZipHoldsTheDownloadedPdfsAndExplainsTheMissingOnes(): void
    {
        self::boot();
        $files = sys_get_temp_dir() . '/fgo-files-' . bin2hex(random_bytes(4));
        mkdir($files);
        Registry::set('config.dir.files', $files . '/');

        $zip = fn_fgo_invoicing_build_pdf_zip([
            1 => ['pdf_link' => 'https://api.fgo.ro/p/1', 'invoice_series' => 'F', 'invoice_number' => '0001'],
            2 => ['pdf_link' => 'https://api.fgo.ro/p/2', 'invoice_series' => 'F', 'invoice_number' => '0002'],
        ], static function (string $url): string {
            if (str_ends_with($url, '/2')) {
                throw new \RuntimeException('HTTP 404');
            }

            return '%PDF-1.4 one';
        });

        self::assertSame(1, $zip['added']);
        self::assertSame([2 => 'HTTP 404'], $zip['failed']);
        self::assertStringStartsWith($files . '/fgo_invoicing/', $zip['path']);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip['path']));
        self::assertSame('%PDF-1.4 one', $archive->getFromName('F-0001.pdf'));
        self::assertStringContainsString('#2: HTTP 404', (string) $archive->getFromName('missing-pdfs.txt'));
        $archive->close();

        unlink($zip['path']);
        rmdir($files . '/fgo_invoicing');
        rmdir($files);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[RequiresPhpExtension('zip')]
    public function testNoDownloadedPdfMeansNoZip(): void
    {
        self::boot();
        Registry::set('config.dir.files', '');

        $zip = fn_fgo_invoicing_build_pdf_zip([
            1 => ['pdf_link' => 'https://api.fgo.ro/p/1'],
        ], static function (string $url): string {
            throw new \RuntimeException('timed out');
        });

        self::assertSame(['path' => '', 'added' => 0, 'failed' => [1 => 'timed out']], $zip);
    }
}
