<?php

declare(strict_types=1);

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunResult;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkSelection;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckReason;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckRow;
use Tygh\Addons\FgoInvoicing\Services\Pdf\InvoicePdfZipper;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * CS-Cart glue for the bulk FGO actions (controllers/backend/fgo_invoicing.php):
 * translation, order status names, the selection kept in the admin's session,
 * the rows and results as the page and the page script consume them, the
 * AJAX answer and the ZIP scratch directory.
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
    // What the page showed, sent back with the order (bulk_run's
    // seen_verdict / seen_reasons): a warning it did not show stops it.
    $view['reason_codes'] = implode(',', array_map(static fn (PrecheckReason $r): string => $r->code, $row->reasons));

    return $view;
}

/**
 * CS-Cart's session (Tygh::$app['session'], ArrayAccess), or null outside a
 * request that has one.
 *
 * @return \ArrayAccess<string, mixed>|null
 */
function fn_fgo_invoicing_session(): ?\ArrayAccess
{
    if (!class_exists('Tygh')) {
        return null;
    }
    try {
        $app = Tygh::$app;
        $session = $app instanceof \ArrayAccess && $app->offsetExists('session') ? $app->offsetGet('session') : null;
    } catch (\Throwable) {
        return null;
    }

    return $session instanceof \ArrayAccess ? $session : null;
}

/**
 * Keep a bulk selection in the admin's session (BulkSelection) and return
 * its token for fgo_invoicing.bulk?token=...; '' when there is no session.
 * The bucket is read and written back whole: nested writes through
 * ArrayAccess are not reliable.
 *
 * @param list<int> $orderIds
 */
function fn_fgo_invoicing_bulk_remember(BulkAction $action, array $orderIds, ?bool $sendEmail = null): string
{
    $session = fn_fgo_invoicing_session();
    if ($session === null) {
        return '';
    }
    $token = BulkSelection::newToken();
    $bucket = $session->offsetExists(BulkSelection::SESSION_KEY) ? $session->offsetGet(BulkSelection::SESSION_KEY) : [];
    $session->offsetSet(
        BulkSelection::SESSION_KEY,
        (new BulkSelection($action, $orderIds, $sendEmail, time()))->storeIn($bucket, $token),
    );

    return $token;
}

/** The selection a token names in this admin's session, or null. */
function fn_fgo_invoicing_bulk_recall(string $token): ?BulkSelection
{
    $session = fn_fgo_invoicing_session();
    if ($session === null || !$session->offsetExists(BulkSelection::SESSION_KEY)) {
        return null;
    }

    return BulkSelection::findIn($session->offsetGet(BulkSelection::SESSION_KEY), $token);
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
 * "#12, #15" for a notification.
 *
 * @param list<int> $orderIds
 */
function fn_fgo_invoicing_order_list(array $orderIds): string
{
    return implode(', ', array_map(static fn (int $id): string => '#' . $id, $orderIds));
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
 * Which of the bulk page's own files this store does not have, so the page
 * can name them instead of only saying that its script did not run.
 *
 * The script and the styles live outside app/addons/, so a store can have
 * the add-on's PHP and templates without them: a Docker dev store started
 * before these folders existed (its links are made when the container
 * starts), or an upload of app/ alone. Nothing is reported when the store
 * root is unknown.
 *
 * @return list<string> store-root-relative paths
 */
function fn_fgo_invoicing_missing_page_assets(): array
{
    $root = TypeCoerce::toString(Registry::get('config.dir.root'));
    if ($root === '') {
        return [];
    }

    return array_values(array_filter(
        [
            'js/addons/fgo_invoicing/bulk.js',
            'design/backend/css/addons/fgo_invoicing/styles.css',
        ],
        static fn (string $path): bool => !is_file(rtrim($root, '/') . '/' . $path),
    ));
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
 * Remove archives and PDF scratch files older than $maxAgeSeconds from the
 * ZIP directory: a request killed mid-way (a fatal error, a worker that was
 * stopped) runs no cleanup of its own. Only this add-on's file names are
 * touched, also when the directory is the system temp dir.
 *
 * @return int files removed
 */
function fn_fgo_invoicing_bulk_purge_temp(string $dir, int $maxAgeSeconds = 3600, ?int $now = null): int
{
    $now ??= time();
    $removed = 0;
    foreach (['fgo-invoices-*.zip', 'fgo-pdf-*'] as $pattern) {
        foreach (glob(rtrim($dir, '/') . '/' . $pattern) ?: [] as $file) {
            $mtime = @filemtime($file);
            if (is_file($file) && $mtime !== false && $now - $mtime > $maxAgeSeconds && @unlink($file)) {
                $removed++;
            }
        }
    }

    return $removed;
}

/**
 * Build the ZIP of the given invoices' PDFs.
 *
 * Downloads stop once $budgetSeconds have passed (InvoicePdfZipper::
 * TIME_BUDGET_SECONDS): the orders not reached are returned as `unfetched`
 * and, like the failed ones and $notIncluded (selected above the batch
 * cap), listed in missing-pdfs.txt inside the archive. The archive is
 * readable by the web server's user only, and a shutdown function removes
 * it and the scratch PDFs whatever ends the request (after fn_get_file()
 * has streamed it, on a timeout, on a fatal error).
 *
 * @param array<int, array<string, mixed>> $invoices order_id => ?:fgo_invoices row (issued, with a pdf_link)
 * @param \Closure(string): string $fetch downloads one PDF (PdfFetcher::fetch)
 * @param (\Closure(): float)|null $clock seconds, monotonic enough (microtime); tests pass a fake
 * @param list<int> $notIncluded selected, but past the batch cap: listed in the note, not fetched
 *
 * @return array{path: string, added: int, failed: array<int, string>, unfetched: list<int>}
 */
function fn_fgo_invoicing_build_pdf_zip(
    array $invoices,
    \Closure $fetch,
    float $budgetSeconds = InvoicePdfZipper::TIME_BUDGET_SECONDS,
    ?\Closure $clock = null,
    array $notIncluded = [],
): array {
    $clock ??= static fn (): float => microtime(true);
    $dir = fn_fgo_invoicing_bulk_temp_dir();
    fn_fgo_invoicing_bulk_purge_temp($dir);
    $path = $dir . '/fgo-invoices-' . bin2hex(random_bytes(6)) . '.zip';
    $zipper = new InvoicePdfZipper($path, $dir);
    register_shutdown_function(static function () use ($zipper, $path): void {
        $zipper->removeTemporaryFiles();
        if (is_file($path)) {
            @unlink($path);
        }
    });

    $started = $clock();
    $added = 0;
    $failed = [];
    $unfetched = [];
    foreach ($invoices as $orderId => $row) {
        if ($clock() - $started >= $budgetSeconds) {
            $unfetched[] = $orderId;
            continue;
        }
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

    if ($added > 0 && ($failed !== [] || $unfetched !== [] || $notIncluded !== [])) {
        $note = fn_fgo_invoicing_t('fgo_invoicing.zip_missing_note') . "\r\n\r\n";
        foreach ($failed as $orderId => $error) {
            $note .= '#' . $orderId . ': ' . $error . "\r\n";
        }
        $later = fn_fgo_invoicing_t('fgo_invoicing.zip_note_time_budget', ['[seconds]' => (int) $budgetSeconds]);
        foreach ($unfetched as $orderId) {
            $note .= '#' . $orderId . ': ' . $later . "\r\n";
        }
        $over = fn_fgo_invoicing_t('fgo_invoicing.zip_note_over_cap', ['[max]' => BulkAction::MAX_BATCH]);
        foreach ($notIncluded as $orderId) {
            $note .= '#' . $orderId . ': ' . $over . "\r\n";
        }
        $zipper->addText('missing-pdfs.txt', $note);
    }
    $zipper->close();
    if ($added > 0) {
        @chmod($path, 0600);
    }

    return ['path' => $added > 0 ? $path : '', 'added' => $added, 'failed' => $failed, 'unfetched' => $unfetched];
}
