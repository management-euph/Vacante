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

        self::assertSame(['path' => '', 'added' => 0, 'failed' => [1 => 'timed out'], 'unfetched' => []], $zip);
    }

    /**
     * Past the time budget no download starts: the rest is listed as not
     * downloaded, in the result and in missing-pdfs.txt, next to the orders
     * over the batch cap. The archive is readable by its owner only.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[RequiresPhpExtension('zip')]
    public function testDownloadsStopAtTheTimeBudgetAndTheRestIsListed(): void
    {
        self::boot();
        $files = sys_get_temp_dir() . '/fgo-files-' . bin2hex(random_bytes(4));
        mkdir($files);
        Registry::set('config.dir.files', $files . '/');
        $now = 100.0;
        $fetched = [];

        $zip = fn_fgo_invoicing_build_pdf_zip(
            [
                1 => ['pdf_link' => 'https://api.fgo.ro/p/1', 'invoice_series' => 'F', 'invoice_number' => '1'],
                2 => ['pdf_link' => 'https://api.fgo.ro/p/2', 'invoice_series' => 'F', 'invoice_number' => '2'],
                3 => ['pdf_link' => 'https://api.fgo.ro/p/3', 'invoice_series' => 'F', 'invoice_number' => '3'],
            ],
            static function (string $url) use (&$now, &$fetched): string {
                $fetched[] = $url;
                $now += 30.0; // a slow FGO

                return '%PDF-1.4';
            },
            45,
            static function () use (&$now): float {
                return $now;
            },
            [900, 901],
        );

        self::assertSame(['https://api.fgo.ro/p/1', 'https://api.fgo.ro/p/2'], $fetched, 'no download starts after 45 s');
        self::assertSame(2, $zip['added']);
        self::assertSame([3], $zip['unfetched']);
        self::assertSame(0600, fileperms($zip['path']) & 0777);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip['path']));
        $note = (string) $archive->getFromName('missing-pdfs.txt');
        self::assertStringContainsString('#3: <fgo_invoicing.zip_note_time_budget> 45', $note);
        self::assertStringContainsString('#900: <fgo_invoicing.zip_note_over_cap> 250', $note);
        self::assertStringContainsString('#901:', $note);
        $archive->close();

        unlink($zip['path']);
        rmdir($files . '/fgo_invoicing');
        rmdir($files);
    }

    /**
     * A request killed mid-way cleans nothing up itself: every build first
     * removes this add-on's archives and scratch PDFs older than an hour, and
     * nothing else.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOldArchivesAndScratchFilesArePurged(): void
    {
        self::boot();
        $dir = sys_get_temp_dir() . '/fgo-purge-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $old = time() - 7200;
        foreach (['fgo-invoices-aa.zip', 'fgo-pdf-bb', 'unrelated.zip'] as $name) {
            touch($dir . '/' . $name, $old);
        }
        touch($dir . '/fgo-invoices-new.zip');

        self::assertSame(2, fn_fgo_invoicing_bulk_purge_temp($dir));
        self::assertFileDoesNotExist($dir . '/fgo-invoices-aa.zip');
        self::assertFileDoesNotExist($dir . '/fgo-pdf-bb');
        self::assertFileExists($dir . '/unrelated.zip');
        self::assertFileExists($dir . '/fgo-invoices-new.zip', 'a fresh one may still be streaming');

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    /**
     * A store with the add-on's PHP and templates but without its js/ or
     * design/backend/css/ folders gets the missing files named on the page.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePageNamesTheScriptAndStylesTheStoreDoesNotHave(): void
    {
        self::boot();
        $root = sys_get_temp_dir() . '/fgo-assets-' . bin2hex(random_bytes(4));
        mkdir($root . '/js/addons/fgo_invoicing', 0777, true);
        Registry::set('config.dir.root', $root . '/');

        self::assertSame(
            ['js/addons/fgo_invoicing/bulk.js', 'design/backend/css/addons/fgo_invoicing/styles.css'],
            fn_fgo_invoicing_missing_page_assets(),
        );

        touch($root . '/js/addons/fgo_invoicing/bulk.js');
        self::assertSame(['design/backend/css/addons/fgo_invoicing/styles.css'], fn_fgo_invoicing_missing_page_assets());

        mkdir($root . '/design/backend/css/addons/fgo_invoicing', 0777, true);
        touch($root . '/design/backend/css/addons/fgo_invoicing/styles.css');
        self::assertSame([], fn_fgo_invoicing_missing_page_assets());

        Registry::set('config.dir.root', '');
        self::assertSame([], fn_fgo_invoicing_missing_page_assets(), 'an unknown store root reports nothing');

        unlink($root . '/js/addons/fgo_invoicing/bulk.js');
        unlink($root . '/design/backend/css/addons/fgo_invoicing/styles.css');
        foreach (['js/addons/fgo_invoicing', 'js/addons', 'js', 'design/backend/css/addons/fgo_invoicing', 'design/backend/css/addons', 'design/backend/css', 'design/backend', 'design', ''] as $sub) {
            rmdir(rtrim($root . '/' . $sub, '/'));
        }
    }

    /**
     * The selection lives in the admin's session: remembered under a token,
     * recalled by it, unknown for any other token or session.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASelectionIsKeptInTheSessionUnderAToken(): void
    {
        self::boot();
        require_once dirname(__DIR__, 2) . '/Fixtures/tygh_app_stub.php';
        \Tygh::$app = new \ArrayObject(['session' => new \ArrayObject()]);

        $token = fn_fgo_invoicing_bulk_remember(BulkAction::Delete, [7, 3], false);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $selection = fn_fgo_invoicing_bulk_recall($token);
        self::assertNotNull($selection);
        self::assertSame(BulkAction::Delete, $selection->action);
        self::assertSame([7, 3], $selection->orderIds);
        self::assertFalse($selection->sendEmail);
        self::assertNull(fn_fgo_invoicing_bulk_recall(str_repeat('0', 32)));
        self::assertNull(fn_fgo_invoicing_bulk_recall('1,2,3'));

        \Tygh::$app = new \ArrayObject(['session' => new \ArrayObject()]);
        self::assertNull(fn_fgo_invoicing_bulk_recall($token), 'another session knows nothing of it');

        \Tygh::$app = new \ArrayObject([]);
        self::assertSame('', fn_fgo_invoicing_bulk_remember(BulkAction::Issue, [1]), 'no session, no token');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARowViewCarriesTheReasonCodesItShows(): void
    {
        self::boot();
        ConfigProvider::seed([]);
        $row = (new BulkPrecheck(new BillingMapper('RON')))->check(
            BulkAction::Issue,
            ['order_id' => 3, 'status' => 'N', 'total' => 0, 'b_firstname' => 'Ion', 'b_country' => 'RO'],
            null,
        );

        self::assertSame('order_status,zero_total', fn_fgo_invoicing_bulk_row_view($row)['reason_codes']);
    }
}
