<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * The FGO column of the admin orders list: attaches to every listed order
 * the little the cell shows, $order['fgo_invoice'] = [status,
 * invoice_series, invoice_number, pdf_link, last_error].
 *
 * One query for the whole page (InvoiceRepository::findByOrderIds), never
 * one per row: the get_orders_post hook runs on every fn_get_orders() call.
 * Every listed order gets the key, 'none' when it has no invoice row, so the
 * template can tell "not invoiced" from "the lookup did not run".
 */
final class OrderInvoiceColumn
{
    /** Shown in a tooltip; the full error is on the invoice page. */
    public const ERROR_MAX = 200;

    public const STATUS_NONE = 'none';

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $orders fn_get_orders() rows
     *
     * @return array<mixed>
     */
    public static function attach(array $orders, InvoiceRepository $repo): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $id = is_array($order) ? TypeCoerce::toInt($order['order_id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $orders;
        }

        $rows = $repo->findByOrderIds($ids);
        foreach ($orders as $key => $order) {
            if (!is_array($order)) {
                continue;
            }
            $id = TypeCoerce::toInt($order['order_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $order['fgo_invoice'] = self::cell($rows[$id] ?? null);
            $orders[$key] = $order;
        }

        return $orders;
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array{status: string, invoice_series: string, invoice_number: string, pdf_link: string, last_error: string}
     */
    public static function cell(?array $row): array
    {
        if ($row === null) {
            return [
                'status' => self::STATUS_NONE,
                'invoice_series' => '',
                'invoice_number' => '',
                'pdf_link' => '',
                'last_error' => '',
            ];
        }

        $status = strtolower(trim(TypeCoerce::toString($row['status'] ?? '')));
        $known = [
            Constants::STATUS_PENDING,
            Constants::STATUS_ISSUED,
            Constants::STATUS_FAILED,
            Constants::STATUS_CANCELED,
            Constants::STATUS_REVERSED,
            Constants::STATUS_DELETED,
        ];

        return [
            'status' => in_array($status, $known, true) ? $status : Constants::STATUS_PENDING,
            'invoice_series' => trim(TypeCoerce::toString($row['invoice_series'] ?? '')),
            'invoice_number' => trim(TypeCoerce::toString($row['invoice_number'] ?? '')),
            'pdf_link' => self::safeLink(TypeCoerce::toString($row['pdf_link'] ?? '')),
            'last_error' => self::excerpt(TypeCoerce::toString($row['last_error'] ?? '')),
        ];
    }

    /**
     * What the order details panel gets as $order_info['fgo_invoice'] (the
     * get_order_info hook): the summary columns only, never the request /
     * response payloads, which hold the customer's data as sent to FGO and
     * would travel with the order into every consumer of fn_get_order_info().
     * Raw values (the panel shows the full error), the PDF link https only.
     *
     * @param array<string, mixed> $row a ?:fgo_invoices row
     *
     * @return array{status: string, invoice_series: string, invoice_number: string, pdf_link: string, last_error: string, updated_at: string}
     */
    public static function summary(array $row): array
    {
        return [
            'status' => strtolower(trim(TypeCoerce::toString($row['status'] ?? ''))),
            'invoice_series' => trim(TypeCoerce::toString($row['invoice_series'] ?? '')),
            'invoice_number' => trim(TypeCoerce::toString($row['invoice_number'] ?? '')),
            'pdf_link' => self::safeLink(TypeCoerce::toString($row['pdf_link'] ?? '')),
            'last_error' => TypeCoerce::toString($row['last_error'] ?? ''),
            'updated_at' => TypeCoerce::toString($row['updated_at'] ?? ''),
        ];
    }

    /**
     * The link is rendered as an <a href>: only an https URL is, so whatever
     * a response stored there can never become a javascript: link.
     */
    public static function safeLink(string $url): string
    {
        $url = trim($url);

        return preg_match('~^https://[^\s"\'<>]+$~i', $url) === 1 ? $url : '';
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > self::ERROR_MAX ? rtrim(mb_substr($text, 0, self::ERROR_MAX - 1)) . '…' : $text;
    }
}
