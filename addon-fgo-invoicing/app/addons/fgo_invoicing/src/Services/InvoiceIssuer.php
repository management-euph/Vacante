<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceRequest;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * Orchestrates issuing an FGO invoice for a CS-Cart order.
 *
 * Invariants:
 *   - At most one `issued` row per `order_id` (DB-enforced UNIQUE).
 *   - Concurrent triggers (place_order_post + change_order_status arriving
 *     in the same request) hit the same row through `insertPending` and
 *     short-circuit when status is already `issued`.
 *   - Failures persist `status=failed`, increment `retry_count`, and write
 *     the FGO `Message` (truncated) into `last_error`. The pending row is
 *     reused on subsequent re-issue attempts.
 *   - Every order_info — handed in by a hook or loaded here — goes through
 *     BillingExtrasResolver before mapping, so the CIF / Reg. Com. / CNP the
 *     customer typed into the store's profile fields reach the invoice.
 *   - `client_vat_required` / `client_cnp_required` are enforced after that
 *     resolution and BEFORE any API call: a PJ without a CIF (or a Romanian
 *     PF without a CNP) is marked failed with the fix spelled out, and FGO is
 *     never called. A fiscal invoice issued without the identifier cannot
 *     simply be edited afterwards; it has to be reversed.
 *     They block ONLY on a store that has a profile field the identifier
 *     could have been typed into. Both settings shipped long before anything
 *     read them ("Require CIF / CNP at checkout"), so stores may have them
 *     on without such a field; blocking there would stop every invoice at
 *     deploy for something no customer could supply. Those orders are issued
 *     as before and a warning is logged instead.
 *   - On success the customer e-mail with the signed PDF link goes out
 *     through InvoiceMailer when `auto_email_pdf` is on, or when the caller
 *     says so ($sendEmail: the bulk page's "Email the PDF link" box, which
 *     overrides the setting either way). Failure to send the e-mail does NOT
 *     roll back issuance — the invoice has already been emitted on FGO's
 *     side; the result reports it as `email_status`.
 */
final class InvoiceIssuer
{
    private readonly InvoiceMailer $mailer;

    /**
     * $mailer defaults to the production one (CS-Cart's mailer); the bulk
     * page and the hooks get the Container's.
     */
    public function __construct(
        private readonly FgoApiClient $api,
        private readonly InvoiceRepository $repo,
        private readonly BillingMapper $mapper,
        private readonly BillingExtrasResolver $resolver,
        ?InvoiceMailer $mailer = null,
    ) {
        $this->mailer = $mailer ?? new InvoiceMailer($repo, InvoiceMailer::productionSender(), OrderInfoSource::core());
    }

    /**
     * @param array<string, mixed>|null $orderInfo Pre-fetched order_info; fetched lazily when null.
     * @param bool|null $sendEmail E-mail the PDF link after issuing; null follows the auto_email_pdf setting.
     *
     * @return array{status: string, invoice_id?: string, error?: string, email_status?: string}
     */
    public function issueForOrder(int $orderId, ?array $orderInfo = null, ?bool $sendEmail = null): array
    {
        if ($orderId <= 0) {
            return ['status' => 'invalid', 'error' => 'orderId must be positive'];
        }

        $row = $this->repo->insertPending($orderId);
        if ($row['status'] === Constants::STATUS_ISSUED) {
            return ['status' => Constants::STATUS_ISSUED, 'invoice_id' => 'already-issued'];
        }

        if ($orderInfo === null) {
            $orderInfo = $this->loadOrderInfo($orderId);
            if ($orderInfo === null) {
                $err = 'fn_get_order_info returned no data for order ' . $orderId;
                $this->repo->markFailed($orderId, $err, []);
                $this->logEvent('error', 'order-not-found', ['order_id' => $orderId]);
                return ['status' => Constants::STATUS_FAILED, 'error' => $err];
            }
        }

        try {
            $orderInfo = $this->resolver->resolve($orderInfo);
            $request = $this->mapper->mapOrderInfo($orderInfo);

            $missing = $this->missingIdentity($request, $orderId);
            if ($missing !== null) {
                $this->repo->markFailed($orderId, $missing, []);
                $this->logEvent('warn', 'identity-missing', ['order_id' => $orderId, 'message' => $missing]);
                return ['status' => Constants::STATUS_FAILED, 'error' => $missing];
            }

            $form = $request->toFormFields();

            $rawResponse = $this->api->issueInvoice($form);
            $response = IssueInvoiceResponse::fromApiResponse($rawResponse);

            $this->repo->markIssued($orderId, $response, $form);
            $emailStatus = $this->maybeEmail($orderId, $orderInfo, $response, $sendEmail ?? ConfigProvider::autoEmailPdf());
            $this->logEvent('info', 'issued', [
                'order_id' => $orderId,
                'invoice_number' => $response->invoiceNumber,
                'invoice_series' => $response->invoiceSeries,
            ]);
            return [
                'status' => Constants::STATUS_ISSUED,
                'invoice_id' => (string) ($response->invoiceNumber ?? ''),
                'email_status' => $emailStatus,
            ];
        } catch (FgoApiException $e) {
            $form ??= [];
            $this->repo->markFailed($orderId, $e->getMessage(), $form, $e->rawResponse);
            $this->logEvent('error', 'fgo-rejected', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'http' => $e->httpStatus,
            ]);
            return ['status' => Constants::STATUS_FAILED, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $form ??= [];
            $msg = '[' . $e::class . '] ' . $e->getMessage();
            $this->repo->markFailed($orderId, $msg, $form);
            $this->logEvent('error', 'unexpected', [
                'order_id' => $orderId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return ['status' => Constants::STATUS_FAILED, 'error' => $msg];
        }
    }

    /**
     * Why this invoice must not be issued yet, or null when it may.
     *
     * The CNP rule skips foreign customers: the CNP is a Romanian identifier,
     * and a customer abroad cannot have one to give. Neither rule blocks on a
     * store with no field to hold the identifier (see the class docblock);
     * that case is logged, once per issue attempt.
     */
    private function missingIdentity(IssueInvoiceRequest $request, int $orderId): ?string
    {
        $client = $request->client;
        if ($client->codUnic !== null && $client->codUnic !== '') {
            return null;
        }

        if ($client->isCompany()) {
            if (!ConfigProvider::clientVatRequired()) {
                return null;
            }
            if (!$this->resolver->hasSourceFor(BillingExtrasResolver::KEY_CIF)) {
                $this->logEvent('warn', 'identity-source-missing', [
                    'order_id' => $orderId,
                    'message' => 'client_vat_required is on but the store has no CIF profile field;'
                        . ' issued without a CIF. Create a CIF text field under Administration > Profile fields.',
                ]);

                return null;
            }

            return 'Company customer has no CIF / VAT ID: fill the CIF profile field on order #' . $orderId
                . ', then retry with Issue on its FGO invoice page (fgo_invoicing.view?order_id=' . $orderId . ').';
        }

        if (!ConfigProvider::clientCnpRequired() || $client->strain) {
            return null;
        }
        if (!$this->resolver->hasSourceFor(BillingExtrasResolver::KEY_CNP)) {
            $this->logEvent('warn', 'identity-source-missing', [
                'order_id' => $orderId,
                'message' => 'client_cnp_required is on but the store has no CNP profile field;'
                    . ' issued without a CNP. Create a CNP text field under Administration > Profile fields.',
            ]);

            return null;
        }

        return 'Individual customer has no CNP: fill the CNP profile field on order #' . $orderId
            . ', then retry with Issue on its FGO invoice page (fgo_invoicing.view?order_id=' . $orderId . ').';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadOrderInfo(int $orderId): ?array
    {
        return OrderInfoSource::core()($orderId);
    }

    /**
     * @param array<string, mixed> $orderInfo
     *
     * @return string InvoiceMailer status, or 'off' when no e-mail was wanted
     */
    private function maybeEmail(int $orderId, array $orderInfo, IssueInvoiceResponse $response, bool $wanted): string
    {
        if (!$wanted) {
            return 'off';
        }
        $orderInfo['order_id'] = $orderId;
        $result = $this->mailer->send(
            $orderInfo,
            $response->invoiceSeries ?? '',
            $response->invoiceNumber ?? '',
            $response->pdfLink ?? '',
            $response->paymentLink ?? '',
        );
        if ($result['status'] === InvoiceMailer::STATUS_FAILED) {
            $this->logEvent('warn', 'email-send-failed', [
                'order_id' => $orderId,
                'message' => $result['error'] ?? '',
            ]);
        }

        return $result['status'];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $level, string $event, array $context): void
    {
        if (!function_exists('fn_log_event')) {
            return;
        }
        if (!ConfigProvider::debugLogging() && $level === 'info') {
            return;
        }
        fn_log_event('fgo_invoicing', 'runtime', [
            'message' => '[' . $level . '] ' . $event,
            'context' => $context,
        ]);
    }
}
