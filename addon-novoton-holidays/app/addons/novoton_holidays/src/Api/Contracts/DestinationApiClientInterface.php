<?php

declare(strict_types=1);

/**
 * Contract for the Novoton Destinations API sub-client.
 *
 * Covers resort list, offers update and kickback (commission) endpoints.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Api\Contracts;

interface DestinationApiClientInterface
{
    /** 16. resort_list — Destinations list. */
    public function getResortList(string $country = '', string $lang = 'UK'): \SimpleXMLElement;

    /** 25. offers_update — Updated/new offers. A refusal (Api\Refusal) throws ApiException. */
    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = ''): \SimpleXMLElement;

    /** 24. kickback_RS — Check for kickback (commission). */
    public function getKickbackInfo(string $lang = 'UK'): \SimpleXMLElement;
}
