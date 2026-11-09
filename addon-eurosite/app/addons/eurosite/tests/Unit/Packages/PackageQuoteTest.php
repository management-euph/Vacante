<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Services\PackageOfferToken;
use Tygh\Addons\Eurosite\Services\PackageQuote;
use Tygh\Addons\Eurosite\Tests\Support\CannedTransport;

/**
 * "Camere și prețuri" on a Eurosite hotel's page in a package mode: the
 * spec's sample answer turned into the blocks the package bar shows, and
 * what the quote asks Eurosite.
 */
final class PackageQuoteTest extends TestCase
{
    private const array HOTEL = ['product_code' => 'TR0012', 'tourop_code' => 'EU', 'country_code' => 'TR', 'city_code' => 'TRALN'];

    private const array DEPARTURE = ['code' => 'ROBCH1', 'country_code' => 'RO'];

    private ?CannedTransport $transport = null;

    private function quote(string $answer): PackageQuote
    {
        $this->transport = new CannedTransport($answer);

        return new PackageQuote(
            new EurositeApiClient($this->transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA'),
            new PackageOfferToken('secret'),
            'Fără masă',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sample(string $transport = 'flight'): array
    {
        $answer = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/package_price_response.xml');

        return $this->quote($answer)->blocks(self::HOTEL, self::DEPARTURE, $transport, '2012-09-21', 7, [['adults' => 2, 'children_ages' => []]], 'EUR', 'RO');
    }

    public function testItAsksForTheHotelsCityOperatorDepartureAndStay(): void
    {
        $this->sample();
        $sent = $this->transport?->lastRequest ?? '';

        self::assertStringContainsString('RequestType="getPackageNVPriceRequest"', $sent);
        foreach ([
            '<CountryCode>TR</CountryCode><CityCode>TRALN</CityCode>',
            '<DepCountryCode>RO</DepCountryCode><DepCityCode>ROBCH1</DepCityCode><Transport>plane</Transport><TourOpCode>EU</TourOpCode>',
            '<CurrencyCode>EUR</CurrencyCode>',
            '<Language>RO</Language>',
            '<PeriodOfStay><CheckIn>2012-09-21</CheckIn><CheckOut>2012-09-28</CheckOut></PeriodOfStay><Days>1</Days>',
            '<Rooms><Room Code="DB" NoAdults="2"/></Rooms>',
        ] as $part) {
            self::assertStringContainsString($part, $sent);
        }
    }

    /** The two offers travel the same way: one block, the cheaper first, under the flight both take. */
    public function testTheSpecSampleIsOneFlightWithTwoOffers(): void
    {
        $blocks = $this->sample();

        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame(['flight', '2012-09-21', '2012-09-28'], [$block['transport'], $block['check_in'], $block['check_out']]);
        self::assertSame([
            'outbound' => [
                'departure_date' => '2012-09-21', 'departure_time' => '07:05', 'from' => 'Otopeni', 'from_code' => '',
                'arrival_date' => '2012-09-21', 'arrival_time' => '08:50', 'to' => 'Antalya', 'to_code' => '',
                'airline' => '', 'stops' => 0,
            ],
            'inbound' => [
                'departure_date' => '2012-09-28', 'departure_time' => '09:40', 'from' => 'Antalya', 'from_code' => '',
                'arrival_date' => '2012-09-28', 'arrival_time' => '11:25', 'to' => 'Otopeni', 'to_code' => '',
                'airline' => '', 'stops' => 0,
            ],
        ], $block['flight']);

        $offers = $block['offers'];
        self::assertSame([1321.6, 1422.4], array_column($offers, 'price'));
        self::assertSame([['name' => 'STANDARD ROOM', 'count' => 1]], $offers[0]['rooms']);
        self::assertSame(['UAI', 'on_request', 'EUR'], [$offers[0]['meal'], $offers[0]['confirmation'], $offers[0]['currency']]);
    }

    /** "Rezervă" carries what the booking form asks Eurosite again with, signed. */
    public function testEachOfferCarriesItsBookingIds(): void
    {
        $offer = (new PackageOfferToken('secret'))->decode((string) $this->sample()[0]['offers'][0]['offer_id']);

        self::assertSame([
            'product_code' => 'TR0012', 'tourop_code' => 'EU', 'country_code' => 'TR', 'city_code' => 'TRALN',
            'dep_country_code' => 'RO', 'dep_city_code' => 'ROBCH1', 'transport' => 'plane', 'departure_date' => '2012-09-21',
            'nights' => '7', 'package_id' => '4', 'variant_id' => '1|1068746_669_1', 'room_codes' => '274',
            'check_in' => '2012-09-21', 'check_out' => '2012-09-28', 'price' => '1321.6', 'currency' => 'EUR',
        ], $offer);
    }

    /** Another hotel of the city, a stop sale, a hidden offer: none of them shown; a coach trip has no flight. */
    public function testOnlyThisHotelsBookableOffersByCoach(): void
    {
        $offer = static fn (string $availability, string $price, string $extra = ''): string => '<Offer CurrencyCode="EUR"><OfferType>Package</OfferType>'
            . '<Availability Code="' . $availability . '">x</Availability>'
            . '<PeriodOfStay><CheckIn>2027-06-12</CheckIn><CheckOut>2027-06-19</CheckOut></PeriodOfStay>' . $extra
            . '<ProductPrice>' . $price . '</ProductPrice><PackageId>9</PackageId><PackageVariantId>1|9_' . $price . '</PackageVariantId>'
            . '<BookingRoomTypes><Room Code="43" GCode="DB" Quantity="1">Dubla</Room></BookingRoomTypes>'
            . '<PriceDetails><Services><Service><Type>7</Type><Code>5</Code><Name>Transport Autocar BUCURESTI - THASSOS</Name><Transport>bus</Transport>'
            . '<PeriodOfStay><CheckIn>2027-06-11 20:00:00</CheckIn><CheckOut>2027-06-12 08:00:00</CheckOut></PeriodOfStay><ServicePrice>60</ServicePrice></Service>'
            . '</Services></PriceDetails></Offer>';
        $answer = '<?xml version="1.0" encoding="utf-8"?><Response ResponseType="getPackageNVPriceResponse">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo><ResponseDetails><getPackageNVPriceResponse>'
            . '<Hotel><Product><TourOpCode>EU</TourOpCode><ProductCode>TR0012</ProductCode></Product><Offers>'
            . $offer('IM', '510') . $offer('ST', '400') . $offer('IM', '390', '<Visible>false</Visible>') . $offer('OR', '480')
            . '</Offers></Hotel>'
            . '<Hotel><Product><TourOpCode>EU</TourOpCode><ProductCode>TR0099</ProductCode></Product><Offers>' . $offer('IM', '300') . '</Offers></Hotel>'
            . '</getPackageNVPriceResponse></ResponseDetails></Response>';

        $blocks = $this->quote($answer)->blocks(self::HOTEL, self::DEPARTURE, 'bus', '2027-06-12', 7, [['adults' => 2, 'children_ages' => [5]]], 'EUR', 'RO');

        self::assertStringContainsString('<Transport>bus</Transport>', $this->transport?->lastRequest ?? '');
        self::assertCount(1, $blocks);
        self::assertSame(['bus', null], [$blocks[0]['transport'], $blocks[0]['flight']]);
        self::assertSame([480.0, 510.0], array_column($blocks[0]['offers'], 'price'));
        self::assertSame(['on_request', 'immediate'], array_column($blocks[0]['offers'], 'confirmation'));
        // No board in the offer: the room-only label.
        self::assertSame('Fără masă', $blocks[0]['offers'][0]['meal']);
    }

    public function testNoOffersIsNoBlock(): void
    {
        self::assertSame([], $this->quote('<?xml version="1.0" encoding="utf-8"?><Response ResponseType="getPackageNVPriceResponse">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><getPackageNVPriceResponse/></ResponseDetails></Response>')->blocks(self::HOTEL, self::DEPARTURE, 'flight', '2027-06-12', 7, [['adults' => 2, 'children_ages' => []]], 'EUR', 'RO'));
    }

    // ── The pieces ──────────────────────────────────────────────────────

    public function testTheAirportsFromAJourneysName(): void
    {
        self::assertSame(['Otopeni', 'Antalya'], PackageQuote::places('Transport Avion OTOPENI - ANTALYA , vineri Bucuresti - Antalya'));
        self::assertSame(['Antalya', 'Otopeni'], PackageQuote::places('Transport Avion ANTALYA - OTOPENI, vineri Antalya - Bucuresti'));
        self::assertSame(['București', 'Thassos'], PackageQuote::places('Transport autocar București - Thassos'));
        self::assertSame(['Cluj-Napoca', 'Heraklion'], PackageQuote::places('CLUJ-NAPOCA - HERAKLION'), 'a hyphen inside a name is no separator');
        self::assertSame(['', ''], PackageQuote::places('TAXA DE AEROPORT'));
        self::assertSame(['', ''], PackageQuote::places('Transport Avion A - B - C'));
    }

    /** The way there is what leaves before the middle of the stay; a connection is a stop. */
    public function testAConnectingFlightAndANightFlight(): void
    {
        $leg = static fn (string $code, string $start, string $end, string $name): array => [
            'type' => '7', 'code' => $code, 'name' => $name, 'transport' => 'plane', 'availability_code' => 'IM',
            'start' => $start, 'end' => $end, 'seats' => null, 'price' => 0.0,
        ];
        $flight = PackageQuote::flight([
            // Leaves the evening before the stay starts.
            $leg('1', '2027-07-02 22:10:00', '2027-07-02 23:40:00', 'Transport Avion CLUJ - VIENA'),
            $leg('2', '2027-07-03 01:00:00', '2027-07-03 04:30:00', 'Transport Avion VIENA - HERAKLION'),
            $leg('3', '2027-07-10 05:00:00', '2027-07-10 07:30:00', 'Transport Avion HERAKLION - CLUJ'),
        ], '2027-07-03', '2027-07-10');

        self::assertNotNull($flight);
        self::assertSame(['2027-07-02', '22:10', 'Cluj', '2027-07-03', '04:30', 'Heraklion', 1], [
            $flight['outbound']['departure_date'] ?? '', $flight['outbound']['departure_time'] ?? '', $flight['outbound']['from'] ?? '',
            $flight['outbound']['arrival_date'] ?? '', $flight['outbound']['arrival_time'] ?? '', $flight['outbound']['to'] ?? '', $flight['outbound']['stops'] ?? -1,
        ]);
        self::assertSame(['Heraklion', 'Cluj', 0], [$flight['inbound']['from'] ?? '', $flight['inbound']['to'] ?? '', $flight['inbound']['stops'] ?? -1]);
        self::assertNull(PackageQuote::flight([], '2027-07-03', '2027-07-10'));
    }

    public function testTheSearchedCheckOutAndTheRoomsSent(): void
    {
        self::assertSame('2027-07-10', PackageQuote::checkOut('2027-07-03', 7));
        self::assertSame('2027-03-01', PackageQuote::checkOut('2027-02-27', 2));
        self::assertSame('', PackageQuote::checkOut('03.07.2027', 7));

        self::assertTrue(PackageQuote::validOccupancy([['adults' => 2, 'children_ages' => [3, 17]]], 1));
        self::assertTrue(PackageQuote::validOccupancy([['adults' => 1, 'children_ages' => []], ['adults' => 9, 'children_ages' => [0, 1, 2, 3]]], 2));
        self::assertFalse(PackageQuote::validOccupancy([], 3));
        self::assertFalse(PackageQuote::validOccupancy([['adults' => 2, 'children_ages' => []], ['adults' => 2, 'children_ages' => []]], 1), 'more rooms than the store books');
        self::assertFalse(PackageQuote::validOccupancy([['adults' => 10, 'children_ages' => []]], 1));
        self::assertFalse(PackageQuote::validOccupancy([['adults' => 2, 'children_ages' => [18]]], 1));
        self::assertFalse(PackageQuote::validOccupancy([['adults' => 2, 'children_ages' => [1, 2, 3, 4, 5]]], 1));
    }
}
