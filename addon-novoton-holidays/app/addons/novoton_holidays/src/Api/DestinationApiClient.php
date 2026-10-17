<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Api\Contracts\DestinationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;

class DestinationApiClient extends ApiClientBase implements DestinationApiClientInterface
{
    /**
     * 16. resort_list - Destinations List
     */
    #[\Override]
    public function getResortList(string $country = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <resort_list>
            <Country>' . htmlspecialchars($country) . '</Country>
        </resort_list>';

        return $this->callApiAndParse(Constants::API_FUNCTION_RESORT_LIST, $xml, $lang);
    }

    /**
     * 25. offers_update - Updated/New Offers
     *
     * A refusal throws: read as "no changes", it moved the baseline past the
     * changes it never saw (Refusal).
     */
    #[\Override]
    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = ''): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <offers_update>
            ' . $this->xmlCredentials() . '
            <DateTime>' . htmlspecialchars($dateTime) . '</DateTime>
            <Country>' . $this->xmlCdata($country) . '</Country>
            <Resort>' . $this->xmlCdata($resort) . '</Resort>
            <Hotel>' . $this->xmlCdata($hotel) . '</Hotel>
        </offers_update>';

        return $this->callApiForAnswer(Constants::API_FUNCTION_OFFERS_UPDATE, $xml);
    }

    /**
     * 24. kickback_RS - Check for kickback (commission)
     */
    #[\Override]
    public function getKickbackInfo(string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <kickback_RS>
            ' . $this->xmlCredentials() . '
        </kickback_RS>';

        return $this->callApiAndParse(Constants::API_FUNCTION_KICKBACK, $xml, $lang);
    }
}
