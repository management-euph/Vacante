<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Helpers\DebugLogger;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class ReservationApiClient extends ApiClientBase implements ReservationApiClientInterface
{
    /**
     * 7. hotel_res_RQ - Reservation request
     *
     * @param array<string, mixed> $bookingData
     */
    #[\Override]
    public function createReservation(array $bookingData): \SimpleXMLElement
    {
        $xml = $this->buildReservationXml($bookingData);

        $this->lastRequest = $xml;

        DebugLogger::log('Novoton hotel_res_RQ Request (Test Mode: ' . (ConfigProvider::isTestBooking() ? 'YES' : 'NO') . ')', ['xml' => $this->maskCredentials($xml)]);

        $response = $this->callApi(Constants::API_FUNCTION_RESERVATION, $xml, TypeCoerce::toString($bookingData['lang'] ?? 'UK'));

        DebugLogger::log('Novoton hotel_res_RQ Response', ['response' => $response]);

        return $this->xmlParser->parse($response);
    }

    /**
     * The hotel_res_RQ that createReservation() sends for $bookingData,
     * credentials masked, without sending it: a booking's admin page shows
     * the request of its stored api_request.
     *
     * Pure builder, as generateHotelRequestXml(): no HTTP call, no debug
     * state touched.
     * @param array<string, mixed> $bookingData
     */
    #[\Override]
    public function generateReservationXml(array $bookingData): string
    {
        return $this->maskCredentials($this->buildReservationXml($bookingData));
    }

    /**
     * 15. resinfo - Reservations Info
     */
    #[\Override]
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $searchXml = $idNum !== '' && $idNum !== '0' ? '<IdNum>' . htmlspecialchars($idNum) . '</IdNum>' :
                              '<ConfirmAgency>' . htmlspecialchars($confirmAgency) . '</ConfirmAgency>';

        $xml = $this->xmlHeader() . '
        <resinfo>
            ' . $this->xmlCredentials() . '
            ' . $searchXml . '
        </resinfo>';

        return $this->callApiAndParse(Constants::API_FUNCTION_RES_INFO, $xml, $lang);
    }

    /**
     * 22. hotel_request - Request alternatives when no prices available
     *
     * @param array<string, mixed> $requestData
     * @return \SimpleXMLElement|array<string, mixed>
     */
    #[\Override]
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false): \SimpleXMLElement|array
    {
        $xml = $this->buildHotelRequestXml($requestData);

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton hotel_request Request',
            'xml' => $this->maskCredentials($xml),
        ]);

        $response = $this->callApi(Constants::API_FUNCTION_HOTEL_REQUEST, $xml, $lang);
        $parsed = $this->xmlParser->parse($response);

        if ($returnXml) {
            return [
                'xml_sent' => $this->maskCredentials($xml),
                'xml_response' => $response,
                'parsed' => $parsed,
                'id_num' => isset($parsed->IdNum) ? (string)$parsed->IdNum : null,
            ];
        }

        return $parsed;
    }

    /**
     * Generate hotel_request XML without sending (for preview/testing).
     *
     * Pure builder: no HTTP call, no debug state mutation. Facade wrappers
     * intentionally bypass the delegate/syncFrom pipeline for this method —
     * running `syncFrom()` would overwrite the current debug state with stale
     * values from the last real API call on this sub-client.
     * @param array<string, mixed> $requestData
     */
    #[\Override]
    public function generateHotelRequestXml(array $requestData): string
    {
        return $this->maskCredentials($this->buildHotelRequestXml($requestData));
    }

    /**
     * 23. alternative_RS - Check for available requested alternatives
     */
    #[\Override]
    public function getAlternatives(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
<alternative_RS>
  ' . $this->xmlCredentials() . '
  <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
</alternative_RS>';

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton alternative_RS Request',
            'xml' => $this->maskCredentials($xml),
        ]);

        return $this->callApiAndParse(Constants::API_FUNCTION_ALTERNATIVE_RS, $xml, $lang);
    }

    /**
     * 8. hotel_acc_RQ_html - Invoice as HTML
     */
    #[\Override]
    public function getInvoiceHtml(string $idNum, string $lang = 'UK'): string
    {
        $xml = $this->xmlHeader() . '
        <hotel_acc_RQ_html>
            ' . $this->xmlCredentials() . '
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ_html>';

        return $this->callApi(Constants::API_FUNCTION_INVOICE_HTML, $xml, $lang);
    }

    /**
     * 9. hotel_acc_RQ - Invoice as XML
     */
    #[\Override]
    public function getInvoiceXml(string $idNum, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <hotel_acc_RQ>
            ' . $this->xmlCredentials() . '
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ>';

        return $this->callApiAndParse(Constants::API_FUNCTION_INVOICE_XML, $xml, $lang);
    }

    /**
     * 14. list_invoices - List Invoices
     */
    #[\Override]
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK'): \SimpleXMLElement
    {
        $arrFromXml = $arrFrom !== '' && $arrFrom !== '0' ? '<ArrFrom>' . htmlspecialchars($arrFrom) . '</ArrFrom>' : '';
        $arrToXml = $arrTo !== '' && $arrTo !== '0' ? '<ArrTo>' . htmlspecialchars($arrTo) . '</ArrTo>' : '';

        $xml = $this->xmlHeader() . '
        <list_invoices>
            ' . $this->xmlCredentials() . '
            ' . $arrFromXml . '
            ' . $arrToXml . '
        </list_invoices>';

        return $this->callApiAndParse(Constants::API_FUNCTION_LIST_INVOICES, $xml, $lang);
    }

    // ---- Private XML builders ----

    /**
     * Build <Guests> XML elements from the guest list.
     * @param list<array<string, mixed>> $guests
     */
    private function buildGuestsXml(array $guests, int $startId = 1, int $defaultAge = 0): string
    {
        $xml = '';
        $id = $startId;
        foreach ($guests as $guest) {
            $xml .= '
    <Guests>
        <IdGuest>' . TypeCoerce::toString($guest['id'] ?? $id) . '</IdGuest>
        <Name>' . htmlspecialchars(TypeCoerce::toString($guest['name'] ?? '')) . '</Name>
        <BirthDay>' . htmlspecialchars(TypeCoerce::toString($guest['birthday'] ?? '')) . '</BirthDay>
        <Age>' . TypeCoerce::toInt($guest['age'] ?? $defaultAge) . '</Age>
    </Guests>';
            $id++;
        }
        return $xml;
    }

    /**
     * Build <room_acc> XML elements from the guest list.
     * @param list<array<string, mixed>> $guests
     */
    private function buildRoomAccXml(array $guests, int $startId = 1): string
    {
        $xml = '';
        $id = $startId;
        foreach ($guests as $guest) {
            $xml .= '
            <room_acc>
                <IdGuest>' . TypeCoerce::toString($guest['id'] ?? $id) . '</IdGuest>
                <Name>' . htmlspecialchars(TypeCoerce::toString($guest['name'] ?? '')) . '</Name>
            </room_acc>';
            $id++;
        }
        return $xml;
    }

    /**
     * Build the full hotel_res_RQ XML (shared by createReservation and generateReservationXml).
     * @param array<string, mixed> $bookingData
     */
    private function buildReservationXml(array $bookingData): string
    {
        $remark = $bookingData['remark'] ?? '';
        $comment = $bookingData['comment'] ?? '';

        if (ConfigProvider::isTestBooking()) {
            $remark = 'test reservation, do not proceed';
            $comment = 'test reservation, do not proceed';
        }

        $guestsXml = $this->buildGuestsXml(TypeCoerce::toRowList($bookingData['guests'] ?? []));

        // Check if this is multi-room booking
        $rooms = TypeCoerce::toRowList($bookingData['rooms'] ?? []);
        $hotelAccXml = '';

        if (!empty($rooms) && count($rooms) > 1) {
            $guestIdCounter = 1;
            foreach ($rooms as $roomIdx => $roomData) {
                $roomGuests = TypeCoerce::toRowList($roomData['guests'] ?? []);
                $roomAccXml = $this->buildRoomAccXml($roomGuests, $guestIdCounter);
                $guestIdCounter += count($roomGuests);

                $firstGuest = $roomGuests[0] ?? [];

                $hotelAccXml .= '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars(TypeCoerce::toString($bookingData['check_in'] ?? '')) . '</CheckIn>
        <CheckOut>' . htmlspecialchars(TypeCoerce::toString($bookingData['check_out'] ?? '')) . '</CheckOut>
        <IdRoom>' . htmlspecialchars(TypeCoerce::toString($roomData['room_id'] ?? '')) . '</IdRoom>
        <IdBoard>' . htmlspecialchars(TypeCoerce::toString($roomData['board_id'] ?? '')) . '</IdBoard>
        <IdExtBoard>' . htmlspecialchars(TypeCoerce::toString($roomData['ext_board_id'] ?? '')) . '</IdExtBoard>
        <IdStar>' . htmlspecialchars(TypeCoerce::toString($bookingData['star_rating'] ?? '')) . '</IdStar>
        <Holder>' . htmlspecialchars(TypeCoerce::toString($firstGuest['name'] ?? $bookingData['holder'] ?? '')) . '</Holder>
        <ISO_National>' . htmlspecialchars(TypeCoerce::toString($bookingData['iso_national'] ?? Constants::DEFAULT_ISO_NATIONAL)) . '</ISO_National>
        <Remark>' . htmlspecialchars(TypeCoerce::toString($remark)) . '</Remark>
        <Comment>' . htmlspecialchars(TypeCoerce::toString($comment) . ' [Room ' . ($roomIdx + 1) . ']') . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
            }
        } else {
            $roomAccXml = $this->buildRoomAccXml(TypeCoerce::toRowList($bookingData['guests'] ?? []));

            $hotelAccXml = '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars(TypeCoerce::toString($bookingData['check_in'] ?? '')) . '</CheckIn>
        <CheckOut>' . htmlspecialchars(TypeCoerce::toString($bookingData['check_out'] ?? '')) . '</CheckOut>
        <IdRoom>' . htmlspecialchars(TypeCoerce::toString($bookingData['room_id'] ?? '')) . '</IdRoom>
        <IdBoard>' . htmlspecialchars(TypeCoerce::toString($bookingData['board_id'] ?? '')) . '</IdBoard>
        <IdExtBoard>' . htmlspecialchars(TypeCoerce::toString($bookingData['ext_board_id'] ?? '')) . '</IdExtBoard>
        <IdStar>' . htmlspecialchars(TypeCoerce::toString($bookingData['star_rating'] ?? '')) . '</IdStar>
        <Holder>' . htmlspecialchars(TypeCoerce::toString($bookingData['holder'] ?? '')) . '</Holder>
        <ISO_National>' . htmlspecialchars(TypeCoerce::toString($bookingData['iso_national'] ?? Constants::DEFAULT_ISO_NATIONAL)) . '</ISO_National>
        <Remark>' . htmlspecialchars(TypeCoerce::toString($remark)) . '</Remark>
        <Comment>' . htmlspecialchars(TypeCoerce::toString($comment)) . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
        }

        $discountType = $bookingData['discount_type'] ?? '';

        return $this->xmlHeader() . '
<hotel_res_RQ>
    ' . $this->xmlCredentials() . '
    <IdHotel>' . htmlspecialchars(TypeCoerce::toString($bookingData['hotel_id'] ?? '')) . '</IdHotel>
    <CreatedBy>' . htmlspecialchars(Constants::DEFAULT_CREATED_BY) . '</CreatedBy>
    <PackageName>' . htmlspecialchars(TypeCoerce::toString($bookingData['package_name'] ?? '')) . '</PackageName>
    <CheckIn>' . htmlspecialchars(TypeCoerce::toString($bookingData['check_in'] ?? '')) . '</CheckIn>
    <CheckOut>' . htmlspecialchars(TypeCoerce::toString($bookingData['check_out'] ?? '')) . '</CheckOut>
    <DiscountType>' . htmlspecialchars(TypeCoerce::toString($discountType)) . '</DiscountType>' . $guestsXml . $hotelAccXml . '
    <OrderNum>' . htmlspecialchars(TypeCoerce::toString($bookingData['order_num'] ?? '')) . '</OrderNum>
</hotel_res_RQ>';
    }

    /**
     * Build the full hotel_request XML (shared by createHotelRequest and generateHotelRequestXml).
     * @param array<string, mixed> $requestData
     */
    private function buildHotelRequestXml(array $requestData): string
    {
        $guestsXml = $this->buildGuestsXml(TypeCoerce::toRowList($requestData['guests'] ?? []), 1, 30);

        $roomAccXml = '';
        if (!empty($requestData['room_guests'])) {
            $roomAccXml = $this->buildRoomAccXml(TypeCoerce::toRowList($requestData['room_guests']));
        }

        return $this->xmlHeader() . '
<hotel_request>
  ' . $this->xmlCredentials() . '
  <IdHotel>' . htmlspecialchars(TypeCoerce::toString($requestData['hotel_id'] ?? '')) . '</IdHotel>
  <CreatedBy>' . htmlspecialchars(TypeCoerce::toString($requestData['created_by'] ?? Constants::DEFAULT_CREATED_BY)) . '</CreatedBy>
  <PackageName>' . htmlspecialchars(TypeCoerce::toString($requestData['package_name'] ?? '')) . '</PackageName>
  <CheckIn>' . htmlspecialchars(TypeCoerce::toString($requestData['check_in'] ?? '')) . '</CheckIn>
  <CheckOut>' . htmlspecialchars(TypeCoerce::toString($requestData['check_out'] ?? '')) . '</CheckOut>
' . $guestsXml . '
<hotel_acc>
  <CheckIn>' . htmlspecialchars(TypeCoerce::toString($requestData['check_in'] ?? '')) . '</CheckIn>
  <CheckOut>' . htmlspecialchars(TypeCoerce::toString($requestData['check_out'] ?? '')) . '</CheckOut>
  <IdRoom>' . htmlspecialchars(TypeCoerce::toString($requestData['room_id'] ?? '')) . '</IdRoom>
  <IdBoard>' . htmlspecialchars(TypeCoerce::toString($requestData['board_id'] ?? '')) . '</IdBoard>
  <IdExtBoard>' . htmlspecialchars(TypeCoerce::toString($requestData['ext_board_id'] ?? '')) . '</IdExtBoard>
  <IdStar>' . htmlspecialchars(TypeCoerce::toString($requestData['star_rating'] ?? '')) . '</IdStar>
  <Holder>' . htmlspecialchars(TypeCoerce::toString($requestData['holder'] ?? '')) . '</Holder>
  <Remark>' . htmlspecialchars(TypeCoerce::toString($requestData['remark'] ?? '')) . '</Remark>
  <Comment>' . htmlspecialchars(TypeCoerce::toString($requestData['comment'] ?? '')) . '</Comment>
' . $roomAccXml . '
</hotel_acc>
</hotel_request>';
    }
}
