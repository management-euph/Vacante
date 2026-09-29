<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Repository;

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Persistence for `cscart_fgo_diagnostic_logs`: one row per issue attempt.
 *
 * ?:fgo_invoices keeps one row per order and overwrites it on every retry;
 * this table keeps the history, with the facts needed to tell WHY FGO
 * refused without storing the customer's CNP: whether the CNP passed its
 * checksum and how long the typed value was sit in their own columns, and
 * the request is stored with the CNP masked (CnpMasker). The real value
 * stays where it legally belongs, on the order.
 *
 * The table is created by addon.xml on install, and by ensureTable() on a
 * store that installed the add-on before the table existed (CS-Cart runs
 * install queries only at install; reinstalling would drop ?:fgo_invoices).
 * ensureTable() runs its DDL once per TABLE_VERSION, stamped in
 * ?:storage_data, so the steady state costs one indexed read.
 *
 * Not `final` so tests can replace it with an in-memory double.
 */
class DiagnosticLogRepository
{
    /** Bump when CREATE_SQL changes, so existing stores run it again. */
    public const TABLE_VERSION = '1';

    private const STAMP = 'fgo_invoicing_diagnostic_logs_table';

    /** Kept in step with addon.xml's install query (a test compares them). */
    public const CREATE_SQL = "CREATE TABLE IF NOT EXISTS `?:fgo_diagnostic_logs` (
                `log_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `order_id` int(11) unsigned NOT NULL,
                `is_cnp_checksum_valid` TINYINT(1) NULL COMMENT '1 checksum passed, 0 failed, NULL no CNP sent',
                `cnp_length` TINYINT unsigned NULL COMMENT 'length of the CNP as typed, NULL no CNP sent',
                `fgo_response_code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'success, rejected/<http>, http/<code>, network, blocked, error',
                `fgo_error_message` TEXT NULL COMMENT 'FGO Message or local error, CNP masked',
                `request_payload` MEDIUMTEXT NULL COMMENT 'JSON form sent to FGO, CNP masked',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`log_id`),
                KEY `idx_order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='FGO issue attempts, CNP masked'";

    private static bool $ensured = false;

    /**
     * Record one issue attempt. Never throws: a diagnostics failure must not
     * turn an issued invoice into an error, nor break checkout.
     *
     * @param array<string, scalar|null> $maskedForm the form as sent, CNP already masked
     */
    public function record(
        int $orderId,
        ?bool $cnpChecksumValid,
        ?int $cnpLength,
        string $responseCode,
        string $errorMessage,
        array $maskedForm,
    ): void {
        if ($orderId <= 0) {
            return;
        }
        try {
            $this->ensureTable();
            db_query('INSERT INTO ?:fgo_diagnostic_logs ?e', [
                'order_id' => $orderId,
                'is_cnp_checksum_valid' => $cnpChecksumValid === null ? null : ($cnpChecksumValid ? 1 : 0),
                'cnp_length' => $cnpLength === null ? null : max(0, min(255, $cnpLength)),
                'fgo_response_code' => mb_substr($responseCode, 0, 32),
                'fgo_error_message' => $errorMessage,
                'request_payload' => $maskedForm === []
                    ? ''
                    : (string) json_encode($maskedForm, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            error_log('fgo_invoicing: could not write ?:fgo_diagnostic_logs — ' . $e->getMessage());
        }
    }

    /**
     * The latest attempts for an order, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForOrder(int $orderId, int $limit = 20): array
    {
        if ($orderId <= 0) {
            return [];
        }
        try {
            $this->ensureTable();
            $rows = db_get_array(
                'SELECT log_id, order_id, is_cnp_checksum_valid, cnp_length, fgo_response_code,'
                . ' fgo_error_message, request_payload, created_at'
                . ' FROM ?:fgo_diagnostic_logs WHERE order_id = ?i ORDER BY log_id DESC LIMIT ?i',
                $orderId,
                max(1, min(200, $limit)),
            );
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Create the table on a store installed before it existed. Once per
     * process, and its DDL once per TABLE_VERSION (stamped in ?:storage_data).
     */
    public function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        $stamped = function_exists('fn_get_storage_data') && function_exists('fn_set_storage_data');
        if ($stamped && TypeCoerce::toString(fn_get_storage_data(self::STAMP)) === self::TABLE_VERSION) {
            return;
        }

        db_query(self::CREATE_SQL);
        if ($stamped) {
            fn_set_storage_data(self::STAMP, self::TABLE_VERSION);
        }
    }

    /** Test seam: forget that the table was ensured in this process. */
    public static function resetEnsured(): void
    {
        self::$ensured = false;
    }
}
