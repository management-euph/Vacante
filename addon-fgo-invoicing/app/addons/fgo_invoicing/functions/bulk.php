<?php

declare(strict_types=1);

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunResult;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckReason;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckRow;
use Tygh\Addons\FgoInvoicing\Services\Pdf\InvoicePdfZipper;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * CS-Cart glue for the bulk FGO actions (controllers/backend/fgo_invoicing.php):
 * translation, order status names, the rows and results as the page and the
 * page script consume them, the AJAX answer and the ZIP scratch directory.
 * The decisions themselves live in src/Services/Bulk (pure, unit-tested);
 * nothing here decides anything.
 *
 * Only function definitions: func.php loads this file on every request,
 * including the settings form of an inactive add-on (no autoloader), so
 * nothing may run at include time.
 */

/**
 * __() with a string result.
 *
 * @param array<string, string|int> $params CS-Cart placeholders, e.g. ['[count]' => 3]
 */
function fn_fgo_invoicing_t(string $key, array $params = []): string
{
    return function_exists('__') ? TypeCoerce::toString(__($key, $params)) : $key;
}

/**
 * Order status code => name, in the admin's language.
 *
 * @return array<string, string>
 */
function fn_fgo_invoicing_order_status_names(): array
{
    if (!function_exists('fn_get_simple_statuses')) {
        return [];
    }
    $raw = fn_get_simple_statuses(defined('STATUSES_ORDER') ? constant('STATUSES_ORDER') : 'O');
    $names = [];
    if (is_array($raw)) {
        foreach ($raw as $code => $name) {
            $names[strtoupper(TypeCoerce::toString($code))] = TypeCoerce::toString($name);
        }
    }

    return $names;
}

/**
 * @param list<PrecheckReason> $reasons
 *
 * @return list<array{level: string, text: string}>
 */
function fn_fgo_invoicing_bulk_reason_lines(array $reasons): array
{
    $lines = [];
    foreach ($reasons as $reason) {
        $lines[] = ['level' => $reason->level, 'text' => fn_fgo_invoicing_t($reason->langKey(), $reason->params)];
    }

    return $lines;
}

/**
 * One pre-check row as bulk.tpl renders it.
 *
 * @return array<string, mixed>
 */
function fn_fgo_invoicing_bulk_row_view(PrecheckRow $row): array
{
    $view = $row->toArray();
    $view['verdict_label'] = fn_fgo_invoicing_t($row->verdict->langKey());
    $view['lines'] = fn_fgo_invoicing_bulk_reason_lines($row->reasons);

    return $view;
}

/**
 * The `fgo_result` a bulk_run request answers with.
 *
 * @return array<string, mixed>
 */
function fn_fgo_invoicing_bulk_result_payload(BulkRunResult $result): array
{
    $payload = $result->toArray();
    $texts = array_column(fn_fgo_invoicing_bulk_reason_lines($result->reasons), 'text');
    $payload['message'] = $result->message !== '' ? $result->message : implode(' ', $texts);
    $payload['view_url'] = function_exists('fn_url')
        ? TypeCoerce::toString(fn_url('fgo_invoicing.view?order_id=' . $result->orderId))
        : '';

    return $payload;
}

/**
 * Hand a value to the page script of an AJAX request (data.<name> in the
 * $.ceAjax callback). False when there is no AJAX response to write to.
 */
function fn_fgo_invoicing_ajax_assign(string $name, mixed $value): bool
{
    if (!defined('AJAX_REQUEST') || !class_exists('Tygh')) {
        return false;
    }
    try {
        $app = Tygh::$app;
        $ajax = $app instanceof \ArrayAccess && $app->offsetExists('ajax') ? $app->offsetGet('ajax') : null;
        if (!is_object($ajax) || !method_exists($ajax, 'assign')) {
            return false;
        }
        $ajax->assign($name, $value);

        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Where the ZIP is assembled: CS-Cart's var/files (writable by design, and
 * inside open_basedir where the system temp dir may not be), else the system
 * temp dir.
 */
function fn_fgo_invoicing_bulk_temp_dir(): string
{
    $files = TypeCoerce::toString(Registry::get('config.dir.files'));
    if ($files !== '') {
        $dir = rtrim($files, '/') . '/fgo_invoicing';
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            if (is_writable($dir)) {
                return $dir;
            }
        }
    }

    return sys_get_temp_dir();
}

/**
 * Build the ZIP of the given invoices' PDFs.
 *
 * @param array<int, array<string, mixed>> $invoices order_id => ?:fgo_invoices row (issued, with a pdf_link)
 * @param \Closure(string): string $fetch downloads one PDF (PdfFetcher::fetch)
 *
 * @return array{path: string, added: int, failed: array<int, string>}
 */
function fn_fgo_invoicing_build_pdf_zip(array $invoices, \Closure $fetch): array
{
    $dir = fn_fgo_invoicing_bulk_temp_dir();
    $path = $dir . '/fgo-invoices-' . bin2hex(random_bytes(6)) . '.zip';
    $zipper = new InvoicePdfZipper($path, $dir);

    $added = 0;
    $failed = [];
    foreach ($invoices as $orderId => $row) {
        try {
            $pdf = $fetch(TypeCoerce::toString($row['pdf_link'] ?? ''));
            $zipper->addPdf(
                $orderId,
                TypeCoerce::toString($row['invoice_series'] ?? ''),
                TypeCoerce::toString($row['invoice_number'] ?? ''),
                $pdf,
            );
            $added++;
            unset($pdf);
        } catch (\Throwable $e) {
            $failed[$orderId] = $e->getMessage();
        }
    }

    if ($added > 0 && $failed !== []) {
        $note = fn_fgo_invoicing_t('fgo_invoicing.zip_missing_note') . "\r\n\r\n";
        foreach ($failed as $orderId => $error) {
            $note .= '#' . $orderId . ': ' . $error . "\r\n";
        }
        $zipper->addText('missing-pdfs.txt', $note);
    }
    $zipper->close();

    return ['path' => $added > 0 ? $path : '', 'added' => $added, 'failed' => $failed];
}
