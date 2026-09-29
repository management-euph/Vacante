<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * Wraps cancel / storno / delete / AWB admin actions. Unlike the issuer,
 * these never auto-fire — they're only invoked from the admin controller
 * (the invoice page and the bulk page).
 *
 * Cancel, storno and delete act on an `issued` invoice only, and record the
 * new state only while the row is STILL `issued` (a conditional UPDATE):
 * two admins cancelling the same invoice, or a cancel racing a storno, can
 * both reach FGO, but only the first write lands. The other one is answered
 * `conflict` with what FGO accepted and what the row holds now, instead of
 * overwriting it.
 */
final class InvoiceCanceler
{
    public function __construct(
        private readonly FgoApiClient $api,
        private readonly InvoiceRepository $repo,
    ) {
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function cancel(int $orderId): array
    {
        return $this->actOnIssued($orderId, 'cancellation (Anulare)', function (string $serie, string $numar): void {
            $this->api->cancelInvoice($serie, $numar);
        }, fn (int $oid): bool => $this->repo->markCanceled($oid));
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function storno(int $orderId): array
    {
        return $this->actOnIssued($orderId, 'reversal (Storno)', function (string $serie, string $numar): void {
            $this->api->stornoInvoice($serie, $numar);
        }, fn (int $oid): bool => $this->repo->markReversed($oid));
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function delete(int $orderId): array
    {
        return $this->actOnIssued($orderId, 'deletion', function (string $serie, string $numar): void {
            $this->api->deleteInvoice($serie, $numar);
        }, fn (int $oid): bool => $this->repo->markDeleted($oid));
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function attachAwb(int $orderId, string $awb): array
    {
        if ($awb === '') {
            return ['status' => 'invalid', 'error' => 'AWB must not be empty'];
        }
        $row = $this->repo->findByOrderId($orderId);
        if ($row === null) {
            return ['status' => 'invalid', 'error' => 'No FGO invoice exists for order ' . $orderId];
        }
        [$serie, $numar] = self::seriesNumber($row);
        if ($serie === '' || $numar === '') {
            return ['status' => 'invalid', 'error' => 'FGO invoice for order ' . $orderId . ' has no series/number'];
        }
        try {
            $this->api->attachAwb($serie, $numar, $awb);
        } catch (FgoApiException $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
        $this->repo->attachAwb($orderId, $awb);

        return ['status' => 'ok'];
    }

    /**
     * @param string $what the action, for the conflict message
     * @param callable(string, string): void $apiCall
     * @param callable(int): bool $persistCall true when it wrote (the row was still `issued`)
     *
     * @return array{status: string, error?: string}
     */
    private function actOnIssued(int $orderId, string $what, callable $apiCall, callable $persistCall): array
    {
        $row = $this->repo->findByOrderId($orderId);
        if ($row === null) {
            return ['status' => 'invalid', 'error' => 'No FGO invoice exists for order ' . $orderId];
        }
        $status = strtolower(trim(TypeCoerce::toString($row['status'] ?? '')));
        if ($status !== Constants::STATUS_ISSUED) {
            return ['status' => 'invalid', 'error' => 'The FGO invoice of order ' . $orderId . ' is not issued (status: ' . ($status !== '' ? $status : 'unknown') . ')'];
        }
        [$serie, $numar] = self::seriesNumber($row);
        if ($serie === '' || $numar === '') {
            return ['status' => 'invalid', 'error' => 'FGO invoice for order ' . $orderId . ' has no series/number'];
        }
        try {
            $apiCall($serie, $numar);
        } catch (FgoApiException $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
        if ($persistCall($orderId)) {
            return ['status' => 'ok'];
        }

        $now = $this->repo->findByOrderId($orderId) ?? [];
        $nowStatus = strtolower(trim(TypeCoerce::toString($now['status'] ?? '')));

        return [
            'status' => 'conflict',
            'error' => 'FGO accepted the ' . $what . ' of invoice ' . $serie . ' ' . $numar . ' (order ' . $orderId . '),'
                . ' but the invoice row changed meanwhile (now: ' . ($nowStatus !== '' ? $nowStatus : 'missing') . ')'
                . ': another request acted on it at the same time, and its state was kept. Check the invoice in FGO.',
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: string}
     */
    private static function seriesNumber(array $row): array
    {
        return [
            trim(TypeCoerce::toString($row['invoice_series'] ?? '')),
            trim(TypeCoerce::toString($row['invoice_number'] ?? '')),
        ];
    }
}
