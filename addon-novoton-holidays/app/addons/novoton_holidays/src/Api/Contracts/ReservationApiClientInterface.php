<?php

declare(strict_types=1);

/**
 * Contract for the Novoton Reservations API sub-client.
 *
 * Covers reservation create, hotel_request (alternatives), invoices and related endpoints.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Api\Contracts;

interface ReservationApiClientInterface
{
    /**
     * 7. hotel_res_RQ — Reservation request.
     * @param array<string, mixed> $bookingData
     */
    public function createReservation(array $bookingData): \SimpleXMLElement;

    /**
     * Generate the hotel_res_RQ XML that createReservation() sends for
     * $bookingData, credentials masked, **without sending** it — for a
     * booking's admin page.
     *
     * This is a pure XML builder: no HTTP call, no debug state mutation.
     * @param array<string, mixed> $bookingData
     */
    public function generateReservationXml(array $bookingData): string;

    /** 15. resinfo — Reservations info. */
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK'): \SimpleXMLElement;

    /**
     * 22. hotel_request — Request alternatives when no prices are available.
     *
     * @param array<string, mixed> $requestData
     * @return \SimpleXMLElement|array<string, mixed> When $returnXml is true, an array is returned.
     */
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false): \SimpleXMLElement|array;

    /**
     * Generate hotel_request XML **without sending** — for preview / testing.
     *
     * This is a pure XML builder: no HTTP call, no debug state mutation.
     * @param array<string, mixed> $requestData
     */
    public function generateHotelRequestXml(array $requestData): string;

    /** 23. alternative_RS — Check for available requested alternatives. */
    public function getAlternatives(string $idNum, string $lang = 'UK'): \SimpleXMLElement;

    /** 8. hotel_acc_RQ_html — Invoice as HTML. */
    public function getInvoiceHtml(string $idNum, string $lang = 'UK'): string;

    /** 9. hotel_acc_RQ — Invoice as XML. */
    public function getInvoiceXml(string $idNum, string $lang = 'UK'): \SimpleXMLElement;

    /** 14. list_invoices — List invoices. */
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK'): \SimpleXMLElement;
}
