<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Api;

use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\Eurosite\EurositeTransportInterface;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Exception\EurositeApiException;

/**
 * Eurosite API facade — the MVP surface of the "individual accommodations"
 * (Cazari individuale) vertical, plus the static-data starters.
 *
 * Each method builds the endpoint payload, wraps it via EurositeXmlBuilder,
 * POSTs through EurositeHttpClient and hands the response to EurositeXmlParser,
 * returning plain arrays / DTOs so callers never touch SimpleXMLElement. Maps
 * 1:1 to the spec sections in Documentation/Specificatii_API_Eurosite.pdf.
 *
 * Not yet ported (spec covers them; deferred past the MVP): supplementary
 * services, item fees / cancellation penalties, packages, transport, circuits,
 * excursions, pax modification.
 */
final class EurositeApiClient
{
    private int $requestSeq = 0;

    public function __construct(
        private readonly EurositeTransportInterface $http,
        private readonly EurositeXmlBuilder $builder,
        private readonly EurositeXmlParser $parser,
        private readonly string $tourOpCode = 'EU',
    ) {
    }

    // ── Static data (start any integration here) ─────────────────────────────

    /**
     * getCountryRequest — country code/name list.
     *
     * @return list<array{code: string, name: string}>
     */
    public function getCountries(): array
    {
        $details = $this->call('getCountryRequest', '<getCountryRequest/>');
        $out = [];
        foreach ($details->Country as $c) {
            $out[] = [
                'code' => trim((string) $c->CountryCode),
                'name' => trim((string) $c->CountryName),
            ];
        }

        return $out;
    }

    /**
     * getCityRequest — cities for a country.
     *
     * @return list<array{code: string, name: string}>
     */
    public function getCities(string $countryCode): array
    {
        $payload = '<getCityRequest CountryCode="' . EurositeXmlBuilder::attr($countryCode) . '"/>';
        $details = $this->call('getCityRequest', $payload);
        $out = [];
        foreach ($details->City as $c) {
            $out[] = [
                'code' => trim((string) $c->CityCode),
                'name' => trim((string) $c->CityName),
            ];
        }

        return $out;
    }

    /**
     * getOwnCityRequest — cities where the operator has own offers. One list
     * across all countries, so the country code rides along per row.
     *
     * @return list<array{code: string, name: string, country_code: string}>
     */
    public function getOwnCities(): array
    {
        $details = $this->call('getOwnCityRequest', '<getOwnCityRequest/>');
        $out = [];
        foreach ($details->City as $c) {
            $out[] = [
                'code' => trim((string) $c->CityCode),
                'name' => trim((string) $c->CityName),
                'country_code' => trim((string) $c->CountryCode),
            ];
        }

        return $out;
    }

    /**
     * getOwnHotelsRequest — the operator's own hotels in a city (with rooms).
     *
     * @return list<array{code: string, name: string, city_code: string, tourop: string, rooms: array<string, string>}>
     */
    public function getOwnHotels(string $cityCode): array
    {
        $payload = '<getOwnHotelsRequest><CityCode>' . EurositeXmlBuilder::esc($cityCode) . '</CityCode></getOwnHotelsRequest>';
        $details = $this->call('getOwnHotelsRequest', $payload);
        $out = [];
        foreach ($details->Hotel as $h) {
            $rooms = [];
            foreach ($h->Rooms->Room ?? [] as $r) {
                $rooms[trim((string) ($r['code'] ?? $r['Code'] ?? ''))] = trim((string) $r);
            }
            $out[] = [
                'code' => trim((string) $h->HotelCode),
                'name' => trim((string) $h->HotelName),
                'city_code' => trim((string) $h->CityCode),
                'tourop' => trim((string) $h->Touropcode),
                'rooms' => $rooms,
            ];
        }

        return $out;
    }

    /**
     * getRoomRequest — the room-type catalog (GCode => name).
     *
     * @return list<array{code: string, name: string}>
     */
    public function getRoomTypes(): array
    {
        $details = $this->call('getRoomRequest', '<getRoomRequest/>');
        $out = [];
        foreach ($details->Room as $r) {
            $out[] = [
                'code' => trim((string) ($r['Code'] ?? $r['code'] ?? '')),
                'name' => trim((string) $r),
            ];
        }

        return $out;
    }

    /**
     * getTagOffersRequest — the offer-tag catalog (e.g. Craciun, 1 Mai). The
     * catalog may legitimately be empty for an account.
     *
     * @return list<array{code: string, name: string}>
     */
    public function getTagOffers(): array
    {
        $details = $this->call('getTagOffersRequest', '<getTagOffersRequest/>');
        $out = [];
        foreach ($details->Tag as $t) {
            $out[] = [
                'code' => trim((string) $t->TagCode),
                'name' => trim((string) $t->TagName),
            ];
        }

        return $out;
    }

    // ── Hotel search ─────────────────────────────────────────────────────────

    /**
     * getHotelPriceRequest — availability + price search across hotels.
     *
     * @param array<string, mixed> $params {
     *                                     country_code, city_code, check_in, check_out, currency?, language?,
     *                                     product_name?, product_category?, offer_type?, meals?:int[],
     *                                     facilities?:int[], rooms:list<array{code,adults,children:int[]}>
     *                                     }
     * @return list<HotelOffer>
     */
    public function searchHotels(array $params): array
    {
        $details = $this->call('getHotelPriceRequest', $this->buildHotelPricePayload($params));

        $offers = [];
        foreach ($details->Hotel ?? [] as $hotel) {
            $product = $hotel->Product ?? null;
            foreach ($hotel->Offers->Offer ?? [] as $offer) {
                $offers[] = $this->mapOffer($product, $offer);
            }
        }

        return $offers;
    }

    /**
     * getProductInfoRequest — full details for one product (description,
     * pictures, facilities). Returned as a shallow assoc array of the response.
     *
     * @return array<string, mixed>
     */
    public function getProductInfo(string $countryCode, string $cityCode, string $productCode, string $productType = 'hotel', string $touropCode = ''): array
    {
        $payload = '<getProductInfoRequest>'
            . '<ProductType>' . EurositeXmlBuilder::esc($productType) . '</ProductType>'
            . '<CountryCode>' . EurositeXmlBuilder::esc($countryCode) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc($cityCode) . '</CityCode>'
            . '<TourOpCode>' . EurositeXmlBuilder::esc($touropCode !== '' ? $touropCode : $this->tourOpCode) . '</TourOpCode>'
            . '<ProductCode>' . EurositeXmlBuilder::esc($productCode) . '</ProductCode>'
            . '</getProductInfoRequest>';
        $details = $this->call('getProductInfoRequest', $payload);

        $pictures = [];
        foreach ($details->Pictures->Picture ?? $details->Picture ?? [] as $p) {
            $pictures[] = trim((string) $p);
        }

        return [
            'product_code' => trim((string) ($details->ProductCode ?? $productCode)),
            'name' => trim((string) ($details->ProductName ?? $details->Name ?? '')),
            'description' => trim((string) ($details->Description ?? '')),
            'category' => (int) (string) ($details->ProductCategory ?? 0),
            'latitude' => trim((string) ($details->Latitude ?? '')),
            'longitude' => trim((string) ($details->Longitude ?? '')),
            'pictures' => $pictures,
        ];
    }

    /**
     * getProductInfoUpdateRequest — ask whether cached product details are
     * stale. Spec: usually answered Y, but callers keeping the mandated
     * getProductInfo cache use this to skip re-fetching fresh entries.
     *
     * @param array<string, mixed> $p {
     *                                country_code, city_code, product_code, product_type?,
     *                                last_update_date (Y-m-d), last_update_time (H:i:s)
     *                                }
     */
    public function isProductInfoUpdatable(array $p): bool
    {
        $payload = '<getProductInfoUpdateRequest><ProductList><Product>'
            . '<ProductType>' . EurositeXmlBuilder::esc(self::str($p['product_type'] ?? '') ?: 'hotel') . '</ProductType>'
            . '<CountryCode>' . EurositeXmlBuilder::esc(self::str($p['country_code'] ?? '')) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc(self::str($p['city_code'] ?? '')) . '</CityCode>'
            . '<TourOpCode>' . EurositeXmlBuilder::esc(self::str($p['tourop_code'] ?? '') ?: $this->tourOpCode) . '</TourOpCode>'
            . '<ProductCode>' . EurositeXmlBuilder::esc(self::str($p['product_code'] ?? '')) . '</ProductCode>'
            . '<LastUpdateDate>' . EurositeXmlBuilder::esc(self::str($p['last_update_date'] ?? '')) . '</LastUpdateDate>'
            . '<LastUpdateTime>' . EurositeXmlBuilder::esc(self::str($p['last_update_time'] ?? '')) . '</LastUpdateTime>'
            . '</Product></ProductList></getProductInfoUpdateRequest>';
        $details = $this->call('getProductInfoUpdateRequest', $payload);

        return strtoupper(trim((string) ($details->UpdateList->IsUpdatable ?? 'Y'))) === 'Y';
    }

    /**
     * getHotelServiceTypesRequest — supplementary services available for an
     * offer (Type 2 = meals, 7 = transport, 6 = other).
     *
     * @param array<string, mixed> $p {
     *                                country_code, city_code, product_code, variant_id, check_in,
     *                                check_out, language?, tourop_code?
     *                                }
     * @return list<array{type: int, type_name: string, code: string, name: string, has_price: bool, provider: string}>
     */
    public function getHotelServiceTypes(array $p): array
    {
        $payload = '<getHotelServiceTypesRequest>'
            . '<CountryCode>' . EurositeXmlBuilder::esc(self::str($p['country_code'] ?? '')) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc(self::str($p['city_code'] ?? '')) . '</CityCode>'
            . '<TourOpCode>' . EurositeXmlBuilder::esc(self::str($p['tourop_code'] ?? '') ?: $this->tourOpCode) . '</TourOpCode>'
            . '<ProductCode>' . EurositeXmlBuilder::esc(self::str($p['product_code'] ?? '')) . '</ProductCode>'
            . '<VariantId>' . EurositeXmlBuilder::esc(self::str($p['variant_id'] ?? '')) . '</VariantId>'
            . '<Language>' . EurositeXmlBuilder::esc(self::str($p['language'] ?? '') ?: 'RO') . '</Language>'
            . '<PeriodOfStay>'
            . '<CheckIn>' . EurositeXmlBuilder::esc(self::str($p['check_in'] ?? '')) . '</CheckIn>'
            . '<CheckOut>' . EurositeXmlBuilder::esc(self::str($p['check_out'] ?? '')) . '</CheckOut>'
            . '</PeriodOfStay>'
            . '</getHotelServiceTypesRequest>';
        $details = $this->call('getHotelServiceTypesRequest', $payload);

        $out = [];
        foreach ($details->Services->Service ?? [] as $s) {
            $out[] = [
                'type' => (int) (string) ($s->Type ?? 0),
                'type_name' => trim((string) ($s->TypeName ?? '')),
                'code' => trim((string) ($s->Code ?? '')),
                'name' => trim((string) ($s->Name ?? '')),
                'has_price' => strtolower(trim((string) ($s->HasPrice ?? ''))) === 'true',
                'provider' => trim((string) ($s->Provider ?? '')),
            ];
        }

        return $out;
    }

    /**
     * getHotelServicePriceRequest — price one supplementary service for an
     * offer/occupancy.
     *
     * @param array<string, mixed> $p {
     *                                currency?, language?, service_type, service_code, country_code,
     *                                city_code, product_code, variant_id, check_in, check_out,
     *                                tourop_code?, pax:list<array{type, name, child_age?}>
     *                                }
     * @return list<array{type: string, code: string, name: string, price: float, gross: float, net: float, commission: float, currency: string}>
     */
    public function getHotelServicePrices(array $p): array
    {
        $pax = '';
        foreach (self::rows($p['pax'] ?? []) as $g) {
            $type = self::str($g['type'] ?? 'adult');
            $attrs = 'PaxType="' . EurositeXmlBuilder::attr($type) . '"';
            if ($type === 'child' && ($ca = self::str($g['child_age'] ?? '')) !== '') {
                $attrs .= ' ChildAge="' . EurositeXmlBuilder::attr($ca) . '"';
            }
            $pax .= '<PaxName ' . $attrs . '>' . EurositeXmlBuilder::esc(self::str($g['name'] ?? '')) . '</PaxName>';
        }

        $payload = '<getHotelServicePriceRequest>'
            . '<CountryCode>' . EurositeXmlBuilder::esc(self::str($p['country_code'] ?? '')) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc(self::str($p['city_code'] ?? '')) . '</CityCode>'
            . '<TourOpCode>' . EurositeXmlBuilder::esc(self::str($p['tourop_code'] ?? '') ?: $this->tourOpCode) . '</TourOpCode>'
            . '<ProductCode>' . EurositeXmlBuilder::esc(self::str($p['product_code'] ?? '')) . '</ProductCode>'
            . '<CurrencyCode>' . EurositeXmlBuilder::esc(self::str($p['currency'] ?? '') ?: 'EUR') . '</CurrencyCode>'
            . '<VariantId>' . EurositeXmlBuilder::esc(self::str($p['variant_id'] ?? '')) . '</VariantId>'
            . '<Language>' . EurositeXmlBuilder::esc(self::str($p['language'] ?? '') ?: 'RO') . '</Language>'
            . '<Services><Service>'
            . '<ServiceType>' . EurositeXmlBuilder::esc(self::str($p['service_type'] ?? '')) . '</ServiceType>'
            . '<ServiceCode>' . EurositeXmlBuilder::esc(self::str($p['service_code'] ?? '')) . '</ServiceCode>'
            . '<PeriodOfStay>'
            . '<CheckIn>' . EurositeXmlBuilder::esc(self::str($p['check_in'] ?? '')) . '</CheckIn>'
            . '<CheckOut>' . EurositeXmlBuilder::esc(self::str($p['check_out'] ?? '')) . '</CheckOut>'
            . '</PeriodOfStay>'
            . '<PaxNames>' . $pax . '</PaxNames>'
            . '</Service></Services>'
            . '</getHotelServicePriceRequest>';
        $details = $this->call('getHotelServicePriceRequest', $payload);

        $out = [];
        foreach ($details->Services->Service ?? [] as $s) {
            $out[] = [
                'type' => trim((string) ($s->Type ?? '')),
                'code' => trim((string) ($s->Code ?? '')),
                'name' => trim((string) ($s->Name ?? '')),
                'price' => (float) (string) ($s->Price ?? 0),
                'gross' => (float) (string) ($s->Gross ?? 0),
                'net' => (float) (string) ($s->NET ?? 0),
                'commission' => (float) (string) ($s->Commission ?? 0),
                'currency' => trim((string) ($s['CurrencyCode'] ?? '')) ?: (self::str($p['currency'] ?? '') ?: 'EUR'),
            ];
        }

        return $out;
    }

    /**
     * getItemFeesRequest — cancellation penalty schedule for a would-be
     * booking (pre-booking; feeds the "Conditii de Anulare" modal). The
     * payload reuses the AddBooking BookingItems shape.
     *
     * @param array<string, mixed> $p {
     *                                currency?, country_code, city_code, product_code, variant_id,
     *                                check_in, check_out, tourop_code?,
     *                                rooms:list<array{code, adults, children:int[], pax?:list<array{type,name,child_age?}>}>
     *                                }
     * @return list<array{type: string, from_date: string, to_date: string, value: float, is_percent: bool}>
     */
    public function getItemFees(array $p): array
    {
        $rooms = '';
        foreach (self::rows($p['rooms'] ?? []) as $room) {
            $code = EurositeXmlBuilder::attr(self::str($room['code'] ?? ''));
            $adults = max(1, (int) self::str($room['adults'] ?? 2));
            $childAges = self::intList($room['children'] ?? []);
            $attrs = 'Code="' . $code . '" NoAdults="' . $adults . '"';
            if ($childAges !== []) {
                $attrs .= ' NoChildren="' . count($childAges) . '"';
            }

            // PaxNames is mandatory in the spec; before real guests exist we
            // send sequential placeholders (verified against the live shape).
            $paxRows = self::rows($room['pax'] ?? []);
            if ($paxRows === []) {
                for ($i = 1; $i <= $adults; $i++) {
                    $paxRows[] = ['type' => 'adult', 'name' => 'ADULT ' . $i];
                }
                foreach ($childAges as $n => $age) {
                    $paxRows[] = ['type' => 'child', 'name' => 'CHILD ' . ($n + 1), 'child_age' => (string) $age];
                }
            }
            $pax = '';
            foreach ($paxRows as $g) {
                $type = self::str($g['type'] ?? 'adult');
                $attrsP = 'PaxType="' . EurositeXmlBuilder::attr($type) . '"';
                if ($type === 'child' && ($ca = self::str($g['child_age'] ?? '')) !== '') {
                    $attrsP .= ' ChildAge="' . EurositeXmlBuilder::attr($ca) . '"';
                }
                $pax .= '<PaxName ' . $attrsP . '>' . EurositeXmlBuilder::esc(self::str($g['name'] ?? '')) . '</PaxName>';
            }

            $rooms .= '<Room ' . $attrs . '><PaxNames>' . $pax . '</PaxNames></Room>';
        }

        $payload = '<getItemFeesRequest CurrencyCode="'
            . EurositeXmlBuilder::attr(self::str($p['currency'] ?? '') ?: 'EUR') . '">'
            . '<BookingItems>'
            . '<BookingItem ProductType="hotel">'
            . '<TourOpCode>' . EurositeXmlBuilder::esc(self::str($p['tourop_code'] ?? '') ?: $this->tourOpCode) . '</TourOpCode>'
            . '<HotelItem>'
            . '<CountryCode>' . EurositeXmlBuilder::esc(self::str($p['country_code'] ?? '')) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc(self::str($p['city_code'] ?? '')) . '</CityCode>'
            . '<ProductCode>' . EurositeXmlBuilder::esc(self::str($p['product_code'] ?? '')) . '</ProductCode>'
            . '<PeriodOfStay>'
            . '<CheckIn>' . EurositeXmlBuilder::esc(self::str($p['check_in'] ?? '')) . '</CheckIn>'
            . '<CheckOut>' . EurositeXmlBuilder::esc(self::str($p['check_out'] ?? '')) . '</CheckOut>'
            . '</PeriodOfStay>'
            . '<VariantId>' . EurositeXmlBuilder::esc(self::str($p['variant_id'] ?? '')) . '</VariantId>'
            . '<Rooms>' . $rooms . '</Rooms>'
            . '</HotelItem>'
            . '</BookingItem>'
            . '</BookingItems>'
            . '</getItemFeesRequest>';
        $details = $this->call('getItemFeesRequest', $payload);

        $out = [];
        foreach ($details->xpath('.//Fee') ?: [] as $fee) {
            $value = $fee->Value ?? null;
            $out[] = [
                'type' => trim((string) ($fee['Type'] ?? 'cancellation')),
                'from_date' => trim((string) ($fee->FromDate ?? '')),
                'to_date' => trim((string) ($fee->ToDate ?? '')),
                'value' => (float) (string) ($value ?? 0),
                'is_percent' => strtolower(trim((string) ($value['Procent'] ?? 'false'))) === 'true',
            ];
        }

        return $out;
    }

    /**
     * getBookingFeesRequest — re-check cancellation penalties on an EXISTING
     * booking. Fees come back as absolute <Price CurrencyCode=...> amounts.
     *
     * @return array{api_ref: string, client_ref: string, items: list<array{item_client_id: string, item_ref: string, fees: list<array{type: string, from_date: string, to_date: string, price: float, currency: string}>}>}
     */
    public function getBookingFees(string $reference, string $source = 'client'): array
    {
        $payload = '<getBookingFeesRequest><BookingReference Source="' . EurositeXmlBuilder::attr($source) . '">'
            . EurositeXmlBuilder::esc($reference) . '</BookingReference></getBookingFeesRequest>';
        $details = $this->call('getBookingFeesRequest', $payload);

        $apiRef = '';
        $clientRef = '';
        foreach ($details->BookingReferences->BookingReference ?? [] as $ref) {
            $src = (string) $ref['Source'];
            if ($src === 'api') {
                $apiRef = trim((string) $ref);
            } elseif ($src === 'client') {
                $clientRef = trim((string) $ref);
            }
        }

        $items = [];
        foreach ($details->BookingFees->BookingItemFee ?? [] as $item) {
            $fees = [];
            foreach ($item->Fees->Fee ?? [] as $fee) {
                $price = $fee->Price ?? null;
                $fees[] = [
                    'type' => trim((string) ($fee['Type'] ?? 'cancellation')),
                    'from_date' => trim((string) ($fee->FromDate ?? '')),
                    'to_date' => trim((string) ($fee->ToDate ?? '')),
                    'price' => (float) (string) ($price ?? 0),
                    'currency' => trim((string) ($price['CurrencyCode'] ?? 'EUR')),
                ];
            }
            $items[] = [
                'item_client_id' => trim((string) ($item->ItemClientId ?? '')),
                'item_ref' => trim((string) ($item->ItemReference ?? '')),
                'fees' => $fees,
            ];
        }

        return ['api_ref' => $apiRef, 'client_ref' => $clientRef, 'items' => $items];
    }

    // ── Booking ──────────────────────────────────────────────────────────────

    /**
     * AddBookingRequest — create a hotel booking.
     *
     * @param array<string, mixed> $booking {
     *                                      currency?, booking_name, client_id, country_code, city_code,
     *                                      product_code, variant_id, check_in, check_out, language?,
     *                                      booking_agent?, booking_client?,
     *                                      rooms:list<array{code, adults, children:int[],
     *                                      pax:list<array{type,name,gender?,dob?,child_age?}>}>
     *                                      }
     * @return array{ok: bool, api_ref: string, client_ref: string, error: string, raw: string}
     */
    public function addBooking(array $booking): array
    {
        $payload = $this->buildAddBookingPayload($booking);
        $raw = $this->send('AddBookingRequest', $payload);
        $details = $this->parser->responseDetails($raw);

        $apiRef = '';
        $clientRef = '';
        foreach ($details?->BookingReferences->BookingReference ?? [] as $ref) {
            $src = (string) $ref['Source'];
            if ($src === 'api') {
                $apiRef = trim((string) $ref);
            } elseif ($src === 'client') {
                $clientRef = trim((string) $ref);
            }
        }

        $error = $apiRef === '' ? $this->parser->errorMessage($raw) : '';

        return [
            'ok' => $apiRef !== '',
            'api_ref' => $apiRef,
            'client_ref' => $clientRef,
            'error' => $error,
            'raw' => $raw,
        ];
    }

    /**
     * getBookingRequest — status + details of an existing booking.
     *
     * @return array<string, mixed>
     */
    public function getBooking(string $reference, string $source = 'client'): array
    {
        $payload = '<getBookingRequest><BookingReference Source="' . EurositeXmlBuilder::attr($source) . '">'
            . EurositeXmlBuilder::esc($reference) . '</BookingReference></getBookingRequest>';
        $details = $this->call('getBookingRequest', $payload);

        $refs = [];
        foreach ($details->BookingReferences->BookingReference ?? [] as $ref) {
            $refs[(string) $ref['Source']] = trim((string) $ref);
        }

        return [
            'references' => $refs,
            'status' => trim((string) ($details->Status ?? $details->BookingItems->BookingItem->Status ?? '')),
            'raw_xml' => $details->asXML() ?: '',
        ];
    }

    /**
     * CancelBookingRequest — request cancellation of a booking.
     *
     * @return array{ok: bool, error: string, raw: string}
     */
    public function cancelBooking(string $reference, string $source = 'client'): array
    {
        $payload = '<CancelBookingRequest><BookingReference Source="' . EurositeXmlBuilder::attr($source) . '">'
            . EurositeXmlBuilder::esc($reference) . '</BookingReference></CancelBookingRequest>';
        $raw = $this->send('CancelBookingRequest', $payload);
        $error = $this->parser->errorMessage($raw);

        return ['ok' => $error === '', 'error' => $error, 'raw' => $raw];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Build the envelope, POST it, and return the raw response text.
     */
    private function send(string $requestType, string $detailsXml): string
    {
        $xml = $this->builder->envelope(
            $requestType,
            $detailsXml,
            $this->nextRequestId(),
            date('Y-m-d\TH:i:s'),
        );

        return $this->http->post($xml);
    }

    /**
     * Send + return the unwrapped <ResponseDetails> child.
     *
     * @throws EurositeApiException on the server's error envelope (its
     *                              ResponseDetails child is <Errors> — e.g. the -1000 auth refusal) or an
     *                              empty/unparseable body, so an API failure is never mistaken for an
     *                              empty catalog / no results.
     */
    private function call(string $requestType, string $detailsXml): \SimpleXMLElement
    {
        $raw = $this->send($requestType, $detailsXml);
        $details = $this->parser->responseDetails($raw);
        if ($details === null || $details->getName() === 'Errors') {
            $reason = $this->parser->errorMessage($raw);

            throw new EurositeApiException($requestType . ' failed: ' . ($reason !== '' ? $reason : 'malformed response'));
        }

        return $details;
    }

    private function nextRequestId(): string
    {
        return str_pad((string) (++$this->requestSeq), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildHotelPricePayload(array $params): string
    {
        $currency = self::str($params['currency'] ?? '') ?: 'EUR';
        $language = self::str($params['language'] ?? '') ?: 'RO';

        $meals = '';
        foreach (self::intList($params['meals'] ?? []) as $m) {
            $meals .= '<Meal>' . $m . '</Meal>';
        }
        $facilities = '';
        foreach (self::intList($params['facilities'] ?? []) as $f) {
            $facilities .= '<Facility>' . $f . '</Facility>';
        }
        $rooms = '';
        foreach (self::rows($params['rooms'] ?? []) as $room) {
            $rooms .= $this->builder->roomElement($room);
        }

        $optional = '';
        if (($pn = self::str($params['product_name'] ?? '')) !== '') {
            $optional .= '<ProductName>' . EurositeXmlBuilder::esc($pn) . '</ProductName>';
        }
        if (($pc = self::str($params['product_category'] ?? '')) !== '') {
            $optional .= '<ProductCategory>' . EurositeXmlBuilder::esc($pc) . '</ProductCategory>';
        }

        return '<getHotelPriceRequest>'
            . '<CountryCode>' . EurositeXmlBuilder::esc(self::str($params['country_code'] ?? '')) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc(self::str($params['city_code'] ?? '')) . '</CityCode>'
            . '<TourOpCode>' . EurositeXmlBuilder::esc(self::str($params['tourop_code'] ?? '') ?: $this->tourOpCode) . '</TourOpCode>'
            . $optional
            . '<CurrencyCode>' . EurositeXmlBuilder::esc($currency) . '</CurrencyCode>'
            . ($meals !== '' ? '<MealTypes>' . $meals . '</MealTypes>' : '')
            . ($facilities !== '' ? '<Facilities>' . $facilities . '</Facilities>' : '')
            . '<Language>' . EurositeXmlBuilder::esc($language) . '</Language>'
            . '<OfferType>' . EurositeXmlBuilder::esc(self::str($params['offer_type'] ?? 'TOATE')) . '</OfferType>'
            . '<PeriodOfStay>'
            . '<CheckIn>' . EurositeXmlBuilder::esc(self::str($params['check_in'] ?? '')) . '</CheckIn>'
            . '<CheckOut>' . EurositeXmlBuilder::esc(self::str($params['check_out'] ?? '')) . '</CheckOut>'
            . '</PeriodOfStay>'
            . '<Rooms>' . $rooms . '</Rooms>'
            . '</getHotelPriceRequest>';
    }

    /**
     * @param array<string, mixed> $b
     */
    private function buildAddBookingPayload(array $b): string
    {
        $currency = self::str($b['currency'] ?? '') ?: 'EUR';
        $rooms = '';
        foreach (self::rows($b['rooms'] ?? []) as $room) {
            $code = EurositeXmlBuilder::attr(self::str($room['code'] ?? ''));
            $adults = max(1, (int) self::str($room['adults'] ?? 2));
            $childAges = self::intList($room['children'] ?? []);
            $attrs = 'Code="' . $code . '" NoAdults="' . $adults . '"';
            if ($childAges !== []) {
                $attrs .= ' NoChildren="' . count($childAges) . '"';
            }

            $pax = '';
            foreach (self::rows($room['pax'] ?? []) as $p) {
                $type = self::str($p['type'] ?? 'adult');
                $name = EurositeXmlBuilder::esc(self::str($p['name'] ?? ''));
                $attrsP = 'PaxType="' . EurositeXmlBuilder::attr($type) . '"';
                if (($g = self::str($p['gender'] ?? '')) !== '') {
                    $attrsP .= ' TGender="' . EurositeXmlBuilder::attr($g) . '"';
                }
                if ($type === 'child' && ($ca = self::str($p['child_age'] ?? '')) !== '') {
                    $attrsP .= ' ChildAge="' . EurositeXmlBuilder::attr($ca) . '"';
                }
                if (($dob = self::str($p['dob'] ?? '')) !== '') {
                    $attrsP .= ' DOB="' . EurositeXmlBuilder::attr($dob) . '"';
                }
                $pax .= '<PaxName ' . $attrsP . '>' . $name . '</PaxName>';
            }

            $rooms .= '<Room ' . $attrs . '><PaxNames>' . $pax . '</PaxNames></Room>';
        }

        $agent = self::str($b['booking_agent'] ?? '');
        $client = self::str($b['booking_client'] ?? '');

        return '<AddBookingRequest CurrencyCode="' . EurositeXmlBuilder::attr($currency) . '">'
            . '<BookingName>' . EurositeXmlBuilder::esc(self::str($b['booking_name'] ?? '')) . '</BookingName>'
            . '<BookingClientId>' . EurositeXmlBuilder::esc(self::str($b['client_id'] ?? '')) . '</BookingClientId>'
            . '<BookingItems>'
            . '<BookingItem ProductType="hotel">'
            . '<ItemClientId>1</ItemClientId>'
            . '<TourOpCode>' . EurositeXmlBuilder::esc(self::str($b['tourop_code'] ?? '') ?: $this->tourOpCode) . '</TourOpCode>'
            . '<HotelItem>'
            . ($agent !== '' ? '<BookingAgent>' . EurositeXmlBuilder::esc($agent) . '</BookingAgent>' : '')
            . ($client !== '' ? '<BookingClient>' . EurositeXmlBuilder::esc($client) . '</BookingClient>' : '')
            . '<CountryCode>' . EurositeXmlBuilder::esc(self::str($b['country_code'] ?? '')) . '</CountryCode>'
            . '<CityCode>' . EurositeXmlBuilder::esc(self::str($b['city_code'] ?? '')) . '</CityCode>'
            . '<ProductCode>' . EurositeXmlBuilder::esc(self::str($b['product_code'] ?? '')) . '</ProductCode>'
            . '<Language>' . EurositeXmlBuilder::esc(self::str($b['language'] ?? '') ?: 'RO') . '</Language>'
            . '<PeriodOfStay>'
            . '<CheckIn>' . EurositeXmlBuilder::esc(self::str($b['check_in'] ?? '')) . '</CheckIn>'
            . '<CheckOut>' . EurositeXmlBuilder::esc(self::str($b['check_out'] ?? '')) . '</CheckOut>'
            . '</PeriodOfStay>'
            . '<VariantId>' . EurositeXmlBuilder::esc(self::str($b['variant_id'] ?? '')) . '</VariantId>'
            . '<Rooms>' . $rooms . '</Rooms>'
            . '</HotelItem>'
            . '</BookingItem>'
            . '</BookingItems>'
            . '</AddBookingRequest>';
    }

    /**
     * Map one <Product>/<Offer> pair to a HotelOffer DTO.
     */
    private function mapOffer(?\SimpleXMLElement $product, \SimpleXMLElement $offer): HotelOffer
    {
        $rooms = [];
        foreach ($offer->BookingRoomTypes->Room ?? [] as $r) {
            $rooms[] = [
                'code' => trim((string) ($r['Code'] ?? '')),
                'gcode' => trim((string) ($r['GCode'] ?? '')),
                'name' => trim((string) $r),
                'quantity' => trim((string) ($r['Quantity'] ?? '1')),
                'price' => trim((string) ($r['ServicePrice'] ?? '')),
            ];
        }
        $meals = [];
        foreach ($offer->Meals->Meal ?? [] as $m) {
            $meals[] = [
                'type' => trim((string) ($m['Type'] ?? '')),
                'code' => trim((string) ($m['Code'] ?? '')),
                'name' => trim((string) $m),
                'price' => trim((string) ($m['ServicePrice'] ?? '')),
            ];
        }

        return new HotelOffer(
            productCode: trim((string) ($product->ProductCode ?? '')),
            productName: trim((string) ($product->ProductName ?? '')),
            countryCode: trim((string) ($product->CountryCode ?? '')),
            cityCode: trim((string) ($product->CityCode ?? '')),
            cityName: trim((string) ($product->CityName ?? '')),
            category: (int) (string) ($product->ProductCategory ?? 0),
            class: trim((string) ($product->Class ?? '')),
            firstImage: trim((string) ($product->FirstImage ?? '')),
            latitude: trim((string) ($product->Latitude ?? '')),
            longitude: trim((string) ($product->Longitude ?? '')),
            currency: trim((string) ($offer['CurrencyCode'] ?? 'EUR')),
            offerType: trim((string) ($offer->OfferType ?? '')),
            availability: trim((string) ($offer->Availability ?? '')),
            checkIn: trim((string) ($offer->PeriodOfStay->CheckIn ?? '')),
            checkOut: trim((string) ($offer->PeriodOfStay->CheckOut ?? '')),
            price: (float) (string) ($offer->ProductPrice ?? 0),
            gross: (float) (string) ($offer->Gross ?? 0),
            net: (float) (string) ($offer->NET ?? 0),
            commission: (float) (string) ($offer->Commission ?? 0),
            variantId: trim((string) ($offer->PackageVariantId ?? '')),
            grila: trim((string) ($offer->GrilaName ?? '')),
            rooms: $rooms,
            meals: $meals,
            seriesId: trim((string) ($offer->SeriesId ?? '')),
            availabilityCode: HotelOffer::normalizeAvailability(
                (string) ($offer->Availability['Code'] ?? ''),
                (string) ($offer->Availability ?? ''),
            ),
            priceNoRedd: (float) (string) ($offer->PriceNoRedd ?? 0),
            offerDescription: trim((string) ($offer->OfferDescription ?? '')),
        );
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item)) {
                $out[] = (int) $item;
            }
        }

        return $out;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
