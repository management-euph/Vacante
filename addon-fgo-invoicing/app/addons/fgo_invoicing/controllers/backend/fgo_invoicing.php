<?php

declare(strict_types=1);

use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\OrderIdList;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunResult;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkUi;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckReason;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckSummary;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\Container;
use Tygh\Addons\FgoInvoicing\Services\OrderInfoSource;
use Tygh\Addons\FgoInvoicing\Services\Pdf\InvoicePdfZipper;
use Tygh\Addons\FgoInvoicing\Services\Pdf\PdfFetcher;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Backend controller for the FGO Invoicing addon admin pages.
 *
 * Routes (CS-Cart dispatcher style):
 *   GET  /fgo_invoicing.manage         List recent invoice rows.
 *   GET  /fgo_invoicing.view?order_id=N Show one row + raw payload.
 *   POST /fgo_invoicing.issue          Manual issue / re-issue button.
 *   POST /fgo_invoicing.cancel
 *   POST /fgo_invoicing.storno
 *   POST /fgo_invoicing.delete
 *   POST /fgo_invoicing.attach_awb
 *   POST /fgo_invoicing.test_connection /factura/check preflight from settings.
 *
 * Bulk actions from the orders list ("FGO invoice" context menu):
 *   POST /fgo_invoicing.m_issue | m_retry | m_email | m_cancel | m_storno | m_delete
 *        order_ids[] from the list -> redirect to the pre-check page.
 *   GET  /fgo_invoicing.bulk?action=<issue|retry|email|cancel|storno|delete>&order_ids=1,2,3
 *        The pre-check page; its script then runs the ticked orders one by one:
 *   POST /fgo_invoicing.bulk_run       (AJAX) action, order_id, send_email=Y|N
 *        -> data.fgo_result; the order is pre-checked AGAIN here first.
 *   POST /fgo_invoicing.m_download_pdfs order_ids (array or CSV) -> ZIP of the PDFs.
 *
 * A page, not a dialog over the orders list: the list re-renders itself by
 * AJAX (pagination, filters, status changes) and a dialog container living in
 * it would be torn down mid-run; a page reached by a plain POST/redirect has
 * nothing to lose and survives a reload.
 */

if (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN) {
    return [CONTROLLER_STATUS_DENIED];
}

$container = Container::getInstance();
$repo = $container->repository();

$view = is_object(Tygh::$app) && method_exists(Tygh::$app, 'offsetGet') ? Tygh::$app->offsetGet('view') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = TypeCoerce::toInt($_REQUEST['order_id'] ?? 0);

    if ($mode === 'issue') {
        if ($orderId <= 0) {
            fn_set_notification('E', __('error'), __('fgo_invoicing.missing_order_id'));
            return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.manage'];
        }
        $result = $container->issuer()->issueForOrder($orderId);
        if ($result['status'] === 'issued') {
            fn_set_notification('N', __('notice'), __('fgo_invoicing.invoice_issued'));
        } else {
            fn_set_notification(
                'E',
                __('error'),
                TypeCoerce::toString(__('fgo_invoicing.invoice_failed')) . ': ' . ($result['error'] ?? 'unknown'),
            );
        }
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.view?order_id=' . $orderId];
    }

    if ($mode === 'cancel' || $mode === 'storno' || $mode === 'delete') {
        $result = match ($mode) {
            'cancel' => $container->canceler()->cancel($orderId),
            'storno' => $container->canceler()->storno($orderId),
            'delete' => $container->canceler()->delete($orderId),
        };
        if ($result['status'] === 'ok') {
            fn_set_notification('N', __('notice'), __('fgo_invoicing.action_succeeded'));
        } else {
            fn_set_notification('E', __('error'), $result['error'] ?? 'failed');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.view?order_id=' . $orderId];
    }

    if ($mode === 'attach_awb') {
        $awb = trim(TypeCoerce::toString($_REQUEST['awb'] ?? ''));
        $result = $container->canceler()->attachAwb($orderId, $awb);
        if ($result['status'] === 'ok') {
            fn_set_notification('N', __('notice'), __('fgo_invoicing.awb_attached'));
        } else {
            fn_set_notification('E', __('error'), $result['error'] ?? 'failed');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.view?order_id=' . $orderId];
    }

    if ($mode === 'test_connection') {
        try {
            $resp = $container->api()->check();
            $msg = TypeCoerce::toString($resp['Message'] ?? __('fgo_invoicing.connection_ok'));
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('fgo_invoicing.connection_ok')) . ': ' . $msg);
        } catch (FgoApiException $e) {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('fgo_invoicing.connection_failed')) . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('fgo_invoicing.connection_failed')) . ': ' . $e->getMessage());
        }
        return [CONTROLLER_STATUS_REDIRECT, 'addons.update?addon=fgo_invoicing'];
    }

    // ── Context menu of the orders list: straight to the pre-check page ──
    if (
        $mode === 'm_issue'
        || $mode === 'm_retry'
        || $mode === 'm_email'
        || $mode === 'm_cancel'
        || $mode === 'm_storno'
        || $mode === 'm_delete'
    ) {
        $bulkAction = BulkAction::fromMenuMode($mode);
        $ids = OrderIdList::parse($_REQUEST['order_ids'] ?? []);
        // The orders list form posts its own redirect_url (and page), and
        // fn_dispatch() prefers it to ours: without this the admin lands
        // back on the list and never sees the pre-check.
        unset($_REQUEST['redirect_url'], $_REQUEST['page']);

        if ($bulkAction === null || $ids === []) {
            fn_set_notification('W', __('warning'), __('fgo_invoicing.bulk_nothing_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
        }

        // One run handles MAX_BATCH orders; the page says how many more were
        // selected, and the URL stays short however many rows were ticked.
        $url = 'fgo_invoicing.bulk?action=' . $bulkAction->value
            . '&order_ids=' . OrderIdList::toCsv(array_slice($ids, 0, BulkAction::MAX_BATCH));
        if (count($ids) > BulkAction::MAX_BATCH) {
            $url .= '&selected=' . count($ids);
        }
        return [CONTROLLER_STATUS_REDIRECT, $url];
    }

    // ── One order of a bulk run (the pre-check page's script, AJAX) ──
    if ($mode === 'bulk_run') {
        $bulkAction = BulkAction::tryFrom(TypeCoerce::toString($_REQUEST['action'] ?? ''));
        if (!defined('AJAX_REQUEST')) {
            // Only the page script sends this; a plain form post goes back to
            // the pre-check, which acts on nothing by itself.
            return [
                CONTROLLER_STATUS_REDIRECT,
                $bulkAction !== null && $orderId > 0
                    ? 'fgo_invoicing.bulk?action=' . $bulkAction->value . '&order_ids=' . $orderId
                    : 'orders.manage',
            ];
        }

        $result = $bulkAction === null
            ? new BulkRunResult($orderId, BulkRunResult::OUTCOME_FAILED, '', [
                new PrecheckReason('unknown_action', PrecheckReason::LEVEL_BLOCK),
            ])
            : $container->bulkRunner(fn_fgo_invoicing_order_status_names())->run(
                $bulkAction,
                $orderId,
                TypeCoerce::toString($_REQUEST['send_email'] ?? 'N') === 'Y',
            );

        // No fn_set_notification() here: $.ceAjax would pop one toast per
        // order. The page shows the outcome on the order's row instead.
        fn_fgo_invoicing_ajax_assign('fgo_result', fn_fgo_invoicing_bulk_result_payload($result));

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    // ── ZIP of the invoice PDFs (context menu, and the results page) ──
    if ($mode === 'm_download_pdfs') {
        $ids = array_slice(OrderIdList::parse($_REQUEST['order_ids'] ?? []), 0, BulkAction::MAX_BATCH);
        unset($_REQUEST['redirect_url'], $_REQUEST['page']);

        if ($ids === []) {
            fn_set_notification('W', __('warning'), __('fgo_invoicing.bulk_nothing_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
        }
        if (!InvoicePdfZipper::isAvailable()) {
            fn_set_notification('E', __('error'), __('fgo_invoicing.zip_unavailable'));
            return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
        }

        $invoices = [];
        foreach ($repo->findByOrderIds($ids) as $id => $row) {
            if (TypeCoerce::toString($row['status'] ?? '') === Constants::STATUS_ISSUED
                && TypeCoerce::toString($row['pdf_link'] ?? '') !== ''
            ) {
                $invoices[$id] = $row;
            }
        }
        if ($invoices === []) {
            fn_set_notification('W', __('warning'), __('fgo_invoicing.zip_nothing'));
            return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
        }

        // Up to MAX_BATCH downloads of up to 20 s each, one after the other.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $fetcher = new PdfFetcher();
        try {
            $zip = fn_fgo_invoicing_build_pdf_zip($invoices, $fetcher->fetch(...));
        } catch (\Throwable $e) {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('fgo_invoicing.zip_failed')) . ': ' . $e->getMessage());
            return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
        }

        $failedList = implode(', ', array_map(static fn (int $id): string => '#' . $id, array_keys($zip['failed'])));
        if ($zip['path'] === '') {
            fn_set_notification('E', __('error'), __('fgo_invoicing.zip_all_failed', ['[orders]' => $failedList]));
            return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
        }
        if ($zip['failed'] !== []) {
            // Shown on the next page the admin opens: this response is the file.
            fn_set_notification('W', __('warning'), __('fgo_invoicing.zip_some_failed', ['[orders]' => $failedList]));
        }

        fn_get_file($zip['path'], 'fgo-invoices-' . date('Ymd-His') . '.zip', true);

        // fn_get_file() exits after streaming; reaching this line means it
        // could not even open the file.
        @unlink($zip['path']);
        fn_set_notification('E', __('error'), __('fgo_invoicing.zip_failed'));
        return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
    }
}

if ($mode === 'manage') {
    $rows = $repo->listRecent(200);
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('fgo_invoices', $rows);
        $view->assign('fgo_sandbox', ConfigProvider::isSandbox());
    }
}

if ($mode === 'view') {
    $orderId = TypeCoerce::toInt($_REQUEST['order_id'] ?? 0);
    $row = $repo->findByOrderId($orderId);
    if ($row === null) {
        fn_set_notification('W', __('warning'), __('fgo_invoicing.no_invoice_for_order'));
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.manage'];
    }
    $requestRaw = TypeCoerce::toString($row['request_payload'] ?? '[]');
    $responseRaw = TypeCoerce::toString($row['payload'] ?? '[]');
    $requestArr = json_decode($requestRaw !== '' ? $requestRaw : '[]', true);
    $responseArr = json_decode($responseRaw !== '' ? $responseRaw : '[]', true);
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('fgo_invoice', $row);
        $view->assign(
            'fgo_request_pretty',
            json_encode(
                is_array($requestArr) ? $requestArr : [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
        $view->assign(
            'fgo_response_pretty',
            json_encode(
                is_array($responseArr) ? $responseArr : [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
        // Every issue attempt (CNP masked), newest first.
        $attempts = [];
        foreach ($container->diagnostics()->listForOrder($orderId) as $attempt) {
            $payload = json_decode(TypeCoerce::toString($attempt['request_payload'] ?? ''), true);
            $attempt['request_pretty'] = is_array($payload) && $payload !== []
                ? (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '';
            $attempts[] = $attempt;
        }
        $view->assign('fgo_attempts', $attempts);
    }
}

// ── The pre-check page ─────────────────────────────────────────────────
if ($mode === 'bulk') {
    $bulkAction = BulkAction::tryFrom(TypeCoerce::toString($_REQUEST['action'] ?? ''));
    if ($bulkAction === null) {
        fn_set_notification('E', __('error'), __('fgo_invoicing.pc_unknown_action'));
        return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
    }

    $requested = OrderIdList::parse($_REQUEST['order_ids'] ?? '');
    $ids = array_slice($requested, 0, BulkAction::MAX_BATCH);
    if ($ids === []) {
        fn_set_notification('W', __('warning'), __('fgo_invoicing.bulk_nothing_selected'));
        return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
    }
    $selectedTotal = max(count($requested), TypeCoerce::toInt($_REQUEST['selected'] ?? 0));
    $truncated = $selectedTotal > count($ids);
    if ($truncated) {
        fn_set_notification('W', __('warning'), __('fgo_invoicing.bulk_truncated', [
            '[selected]' => $selectedTotal,
            '[max]' => BulkAction::MAX_BATCH,
        ]));
    }

    $statusNames = fn_fgo_invoicing_order_status_names();
    $invoices = $repo->findByOrderIds($ids);
    $resolver = $container->billingExtrasResolver();
    $precheck = $container->bulkPrecheck($statusNames);
    $loadOrder = OrderInfoSource::core();

    $checks = [];
    $missing = 0;
    foreach ($ids as $id) {
        try {
            $info = $loadOrder($id);
        } catch (\Throwable) {
            $info = null;
        }
        if ($info === null) {
            $missing++;
            continue;
        }
        $info['order_id'] = $id;
        $checks[] = $precheck->check($bulkAction, $resolver->resolve($info), $invoices[$id] ?? null);
    }
    if ($checks === []) {
        fn_set_notification('W', __('warning'), __('fgo_invoicing.bulk_nothing_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'orders.manage'];
    }

    $summary = PrecheckSummary::of($checks);
    $translate = static fn (string $key): string => fn_fgo_invoicing_t($key);
    $apiHost = parse_url(ConfigProvider::apiBaseUrl(), PHP_URL_HOST);

    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('fgo_bulk', [
            'action' => $bulkAction->value,
            'title' => fn_fgo_invoicing_t(BulkUi::titleKey($bulkAction)),
            'rows' => array_map('fn_fgo_invoicing_bulk_row_view', $checks),
            'summary' => $summary->toArray(),
            'start_label' => BulkUi::startLabel($bulkAction, $summary->toProcess, $translate),
            'statuses' => $statusNames,
            'selected_total' => $selectedTotal,
            'max_batch' => BulkAction::MAX_BATCH,
            'truncated' => $truncated,
            'missing' => $missing,
            'sandbox' => ConfigProvider::isSandbox(),
            'api_host' => is_string($apiHost) ? $apiHost : '',
            'issues' => $bulkAction->issues(),
            'settings' => [
                'document_type' => ConfigProvider::invoiceType(),
                'series' => ConfigProvider::invoiceSeries(),
                'currency' => ConfigProvider::primaryCurrency(),
            ],
            'email_default' => ConfigProvider::autoEmailPdf(),
            // FGO takes about one request a second; the PHP throttle is per
            // process, so the page itself must space the requests out.
            'interval_ms' => max(1000, ConfigProvider::minCallIntervalMs()),
            'zip_enabled' => $bulkAction->issues() || $bulkAction === BulkAction::Email,
            'retry_action' => $bulkAction->retryAction()->value,
            'i18n_json' => (string) json_encode(
                BulkUi::jsStrings($bulkAction, $translate),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            ),
        ]);
    }
}
