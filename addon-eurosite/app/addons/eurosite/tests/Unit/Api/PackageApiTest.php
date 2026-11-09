<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\Dto\PackageOffer;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Exception\EurositeApiException;
use Tygh\Addons\Eurosite\Tests\Support\CannedTransport;

/**
 * The package calls (Pachete): getPackageNVRoutesRequest and
 * getPackageNVPriceRequest. The answers are the spec's own samples
 * (tests/Fixtures/package_*_response.xml), and the search is checked against
 * the spec's sample request, element by element.
 */
final class PackageApiTest extends TestCase
{
    private ?CannedTransport $transport = null;

    public function testRoutesAskForTheTransportAndFlattenTheSpecSample(): void
    {
        $routes = $this->client(self::fixture('package_routes_response.xml'))->getPackageRoutes('plane');

        self::assertStringContainsString('RequestType="getPackageNVRoutesRequest"', $this->sent());
        self::assertSame('<getPackageNVRoutesRequest><Transport>plane</Transport></getPackageNVRoutesRequest>', self::details($this->sent(), 'getPackageNVRoutesRequest'));

        // One row per destination city and departure city.
        self::assertSame(
            ['HRDBR|ROBCH1', 'HRMLN1|ROBCH1', 'TRALN|ROBCH1', 'TRALN|ROCLJNPC', 'TRALN|ROTMS'],
            array_map(static fn (array $r): string => $r['city_code'] . '|' . $r['dep_city_code'], $routes),
        );
        self::assertSame([
            'transport' => 'plane',
            'country_code' => 'HR',
            'country_name' => 'Croatia',
            'city_code' => 'HRDBR',
            'city_name' => 'Dubrovnik',
            'zone_code' => 'HRDBR',
            'zone_name' => 'Dubrovnik',
            'dep_country_code' => 'RO',
            'dep_country_name' => 'Romania',
            'dep_city_code' => 'ROBCH1',
            'dep_city_name' => 'Bucharest',
            'dates' => ['2012-09-13', '2012-09-20'],
        ], $routes[0]);
        // A city of a zone keeps the zone (Alanya is in Antalya's).
        self::assertSame(['TCANT', 'Antalya'], [$routes[2]['zone_code'], $routes[2]['zone_name']]);
        self::assertSame(
            ['2012-09-07', '2012-09-10', '2012-09-12', '2012-09-14', '2012-09-21', '2012-09-28', '2012-10-05'],
            $routes[2]['dates'],
        );
        self::assertSame(['Cluj Napoca', ['2012-09-11', '2012-09-18']], [$routes[3]['dep_city_name'], $routes[3]['dates']]);
    }

    /** A pair listed twice is one row; its dates in order, once each, and only real dates. */
    public function testRoutesMergeARepeatedPairAndKeepOnlyDates(): void
    {
        $departure = static fn (string $dates): string => '<Departure><CountryCode>RO</CountryCode><CountryName>Romania</CountryName>'
            . '<CityCode>ROBCH1</CityCode><CityName>Bucharest</CityName><Dates>' . $dates . '</Dates></Departure>';
        $routes = $this->client(self::wrap(
            'getPackageNVRoutesResponse',
            '<Country><CountryCode>GR</CountryCode><CountryName>Greece</CountryName><Destinations>'
            . '<Destination><CityCode>GRTHS</CityCode><CityName>Thassos</CityName><Departures>'
            . $departure('<Date>2026-07-10</Date><Date>2026-06-26</Date><Date>13.07.2026</Date>')
            . '</Departures></Destination>'
            . '<Destination><CityCode>GRTHS</CityCode><CityName>Thassos</CityName><Departures>'
            . $departure('<Date>2026-07-03</Date><Date>2026-07-10</Date><Date/>')
            . '</Departures></Destination>'
            . '</Destinations></Country>',
        ))->getPackageRoutes('bus');

        self::assertCount(1, $routes);
        self::assertSame('bus', $routes[0]['transport']);
        self::assertSame(['2026-06-26', '2026-07-03', '2026-07-10'], $routes[0]['dates']);
        // No zone in the answer: none made up.
        self::assertSame(['', ''], [$routes[0]['zone_code'], $routes[0]['zone_name']]);
    }

    public function testNoRoutesIsAnEmptyList(): void
    {
        self::assertSame([], $this->client(self::wrap('getPackageNVRoutesResponse', ''))->getPackageRoutes());
        self::assertStringContainsString('<Transport>search</Transport>', $this->sent());
    }

    public function testAnUnknownTransportIsRefusedBeforeAnythingIsSent(): void
    {
        $client = $this->client(self::wrap('getPackageNVRoutesResponse', ''));

        try {
            $client->getPackageRoutes('flight');
            self::fail('A transport outside search, bus and plane must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"flight"', $e->getMessage());
        }
        try {
            $client->searchPackages(['transport' => 'coach']);
            self::fail('A transport outside search, bus and plane must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"coach"', $e->getMessage());
        }
        self::assertSame('', $this->sent());
    }

    /** The spec's sample request, element for element and in its order. */
    public function testTheSearchSendsTheSpecSampleRequest(): void
    {
        $this->client(self::fixture('package_price_response.xml'))->searchPackages([
            'country_code' => 'TR',
            'city_code' => 'TRALN',
            'dep_country_code' => 'RO',
            'dep_city_code' => 'ROBCH1',
            'transport' => 'plane',
            'tourop_code' => 'EU',
            'product_name' => 'AYDINBEY',
            'currency' => 'EUR',
            'meals' => [1],
            'facilities' => [6, 10],
            'language' => 'RO',
            'offer_type' => 'TOATE',
            'check_in' => '2012-09-21',
            'check_out' => '2012-09-28',
            'days' => 1,
            'rooms' => [['code' => 'DB', 'adults' => 2, 'children' => [8, 3]]],
        ]);

        self::assertStringContainsString('RequestType="getPackageNVPriceRequest"', $this->sent());
        self::assertSame(
            '<getPackageNVPriceRequest>'
            . '<CountryCode>TR</CountryCode><CityCode>TRALN</CityCode>'
            . '<DepCountryCode>RO</DepCountryCode><DepCityCode>ROBCH1</DepCityCode>'
            . '<Transport>plane</Transport><TourOpCode>EU</TourOpCode><ProductName>AYDINBEY</ProductName>'
            . '<CurrencyCode>EUR</CurrencyCode>'
            . '<MealTypes><Meal>1</Meal></MealTypes><Facilities><Facility>6</Facility><Facility>10</Facility></Facilities>'
            . '<Language>RO</Language><OfferType>TOATE</OfferType>'
            . '<PeriodOfStay><CheckIn>2012-09-21</CheckIn><CheckOut>2012-09-28</CheckOut></PeriodOfStay>'
            . '<Days>1</Days>'
            . '<Rooms><Room Code="DB" NoAdults="2" NoChildren="2"><Children><Age>8</Age><Age>3</Age></Children></Room></Rooms>'
            . '</getPackageNVPriceRequest>',
            self::details($this->sent(), 'getPackageNVPriceRequest'),
        );
    }

    /**
     * A zone search sends <Zone> after the city; no operator given, none sent
     * (the spec leaves it optional); the margin stays within 1-3 days.
     */
    public function testTheSearchSendsAZoneAndOnlyTheOperatorItIsGiven(): void
    {
        $this->client(self::wrap('getPackageNVPriceResponse', ''))->searchPackages([
            'country_code' => 'TR',
            'city_code' => 'TRALN',
            'zone' => 'TCANT',
            'dep_country_code' => 'RO',
            'dep_city_code' => 'ROBCH1',
            'transport' => 'bus',
            'check_in' => '2026-07-03',
            'check_out' => '2026-07-10',
            'days' => 7,
            'rooms' => [['code' => 'DB', 'adults' => 2, 'children' => []]],
        ]);
        $request = self::details($this->sent(), 'getPackageNVPriceRequest');

        self::assertStringContainsString('<CityCode>TRALN</CityCode><Zone>TCANT</Zone><DepCountryCode>RO</DepCountryCode>', $request);
        self::assertStringContainsString('<Transport>bus</Transport><CurrencyCode>EUR</CurrencyCode>', $request);
        self::assertStringNotContainsString('<TourOpCode>', $request);
        self::assertStringContainsString('<Days>3</Days>', $request);
        self::assertStringContainsString('<Rooms><Room Code="DB" NoAdults="2"/></Rooms>', $request);
    }

    public function testTheSpecSampleAnswerIsTwoPackageOffers(): void
    {
        $offers = $this->client(self::fixture('package_price_response.xml'))->searchPackages(self::search());

        self::assertCount(2, $offers);
        [$first, $second] = $offers;

        // The hotel offer, as the hotel search maps it.
        $hotel = $first->hotel;
        self::assertSame(['TR0012', 'AYDINBEY GOLD DREAMS', 'TR', 'TRALN', 'Alanya', 5], [
            $hotel->productCode, $hotel->productName, $hotel->countryCode, $hotel->cityCode, $hotel->cityName, $hotel->category,
        ]);
        self::assertSame(['Package', 'OR', 'EUR'], [$hotel->offerType, $hotel->availabilityCode, $hotel->currency]);
        self::assertSame(['2012-09-21', '2012-09-28'], [$hotel->checkIn, $hotel->checkOut]);
        self::assertSame([1321.6, 1417.0, 1529.0], [$hotel->price, $hotel->gross, $hotel->priceNoRedd]);
        self::assertSame('Reducere inscriere timpurie valabila pana la 31.10.2012', $hotel->offerDescription);
        self::assertSame('standard EB', $hotel->grila);
        // The room's own code (274) is what a booking sends; DB is its type.
        self::assertSame(['274', 'DB', '1', '448'], [
            $hotel->rooms[0]['code'], $hotel->rooms[0]['gcode'], $hotel->rooms[0]['quantity'], $hotel->rooms[0]['price'],
        ]);
        self::assertSame(['UAI', '0'], [$hotel->meals[0]['name'], $hotel->meals[0]['price']]);

        // What makes it a package.
        self::assertSame('4', $first->packageId);
        self::assertSame('1|1068746_669_1', $first->variantId());
        self::assertSame('EU', $first->tourOpCode);
        self::assertTrue($first->acceptRemarks);
        self::assertTrue($first->visible);
        self::assertTrue($first->isBookable());
        self::assertSame(['6', '7', '7s', '7', '8'], array_column($first->services, 'type'));

        self::assertSame(['1|1068746_669_0', 1422.4, 1529.0], [$second->variantId(), $second->hotel->price, $second->hotel->gross]);
    }

    /** The price is the room, the board and the services added up. */
    public function testThePriceIsItsPartsAddedUp(): void
    {
        foreach ($this->client(self::fixture('package_price_response.xml'))->searchPackages(self::search()) as $offer) {
            $parts = array_sum(array_map(static fn (array $r): float => (float) $r['price'], $offer->hotel->rooms))
                + array_sum(array_map(static fn (array $m): float => (float) $m['price'], $offer->hotel->meals))
                + array_sum(array_column($offer->services, 'price'));

            self::assertEqualsWithDelta($offer->hotel->price, $parts, 0.001);
        }
    }

    public function testTheServicesAndTheJourneys(): void
    {
        $offer = $this->client(self::fixture('package_price_response.xml'))->searchPackages(self::search())[0];

        self::assertSame([
            'type' => '7',
            'code' => '90',
            'name' => 'Transport Avion OTOPENI - ANTALYA , vineri Bucuresti - Antalya',
            'transport' => 'plane',
            'availability_code' => 'IM',
            'start' => '2012-09-21 07:05:00',
            'end' => '2012-09-21 08:50:00',
            'seats' => 27,
            'price' => 210.0,
        ], $offer->outbound());
        $back = $offer->inbound();
        self::assertNotNull($back);
        self::assertSame(['91', '2012-09-28 09:40:00', '2012-09-28 11:25:00', 23], [$back['code'], $back['start'], $back['end'], $back['seats']]);
        self::assertSame(['90', '91'], array_column($offer->legs(), 'code'));

        $transfer = $offer->servicesOfType(PackageOffer::TYPE_OTHER);
        self::assertSame(['transfer aeroport - hotel - aeroport', 'OR', null, 54.0], [
            $transfer[0]['name'], $transfer[0]['availability_code'], $transfer[0]['seats'], $transfer[0]['price'],
        ]);
        // The airport tax travels "plane" too, yet is no journey.
        $tax = $offer->servicesOfType(PackageOffer::TYPE_AIRPORT_TAX);
        self::assertSame(['TAXA DE AEROPORT', 'plane', '2012-09-21', '2012-09-28', 285.0], [
            $tax[0]['name'], $tax[0]['transport'], $tax[0]['start'], $tax[0]['end'], $tax[0]['price'],
        ]);
        self::assertSame(['Comision pachet', 114.6], [
            $offer->servicesOfType(PackageOffer::TYPE_COMMISSION)[0]['name'], $offer->servicesOfType(PackageOffer::TYPE_COMMISSION)[0]['price'],
        ]);

        $row = $offer->toArray();
        self::assertSame(['4', '1|1068746_669_1', 'EU', true, true], [
            $row['package_id'], $row['variant_id'], $row['tourop_code'], $row['accept_remarks'], $row['visible'],
        ]);
        self::assertSame('TR0012', $row['hotel']['product_code']);
        self::assertCount(5, $row['services']);
    }

    /**
     * The way back is the later journey whatever the order they come in; one
     * journey has no way back; a name the layout wrapped reads on one line.
     */
    public function testTheJourneysGoByTheirTimes(): void
    {
        $leg = static fn (string $code, string $start, string $name = 'Transport Autocar'): string => '<Service><Type>7</Type>'
            . '<Code>' . $code . '</Code><Name>' . $name . '</Name><Transport>bus</Transport>'
            . '<PeriodOfStay><CheckIn>' . $start . '</CheckIn><CheckOut>' . $start . '</CheckOut></PeriodOfStay>'
            . '<ServicePrice>60</ServicePrice></Service>';

        $both = $this->offerWith($leg('B', '2026-07-10 20:00:00') . $leg('A', '2026-07-03 06:00:00', "Transport Autocar\n   BUCURESTI - THASSOS"));
        self::assertSame(['A', 'B'], array_column($both->legs(), 'code'));
        self::assertSame('A', $both->outbound()['code'] ?? '');
        self::assertSame('B', $both->inbound()['code'] ?? '');
        self::assertSame('Transport Autocar BUCURESTI - THASSOS', $both->legs()[0]['name']);
        self::assertSame('bus', $both->legs()[0]['transport']);

        $one = $this->offerWith($leg('A', '2026-07-03 06:00:00'));
        self::assertSame('A', $one->outbound()['code'] ?? '');
        self::assertNull($one->inbound());

        $none = $this->offerWith('');
        self::assertNull($none->outbound());
        self::assertSame([], $none->legs());
    }

    /** A stop sale, or an offer without its ids, cannot be booked; Visible false is kept. */
    public function testWhatCannotBeBooked(): void
    {
        $offer = static fn (string $availability, string $ids, string $visible = ''): string => '<Offer CurrencyCode="EUR">'
            . '<OfferType>Package</OfferType><Availability Code="' . $availability . '">x</Availability>'
            . '<PeriodOfStay><CheckIn>2026-07-03</CheckIn><CheckOut>2026-07-10</CheckOut></PeriodOfStay>'
            . $visible . '<ProductPrice>900</ProductPrice>' . $ids . '</Offer>';
        $ids = '<PackageId>7</PackageId><PackageVariantId>1|1_2_3</PackageVariantId>';

        $offers = $this->client(self::wrap(
            'getPackageNVPriceResponse',
            '<Hotel><Product><ProductCode>GR0001</ProductCode></Product><Offers>'
            . $offer('IM', $ids)
            . $offer('ST', $ids)
            . $offer('IM', '<PackageVariantId>1|1_2_3</PackageVariantId>')
            . $offer('IM', '<PackageId>7</PackageId>')
            . $offer('OR', $ids, '<Visible>false</Visible>')
            . '</Offers></Hotel>',
        ))->searchPackages(self::search());

        self::assertSame([true, false, false, false, true], array_map(static fn (PackageOffer $o): bool => $o->isBookable(), $offers));
        self::assertSame([true, true, true, true, false], array_map(static fn (PackageOffer $o): bool => $o->visible, $offers));
        // No operator or remarks flag in the answer: none assumed.
        self::assertSame(['', false], [$offers[0]->tourOpCode, $offers[0]->acceptRemarks]);
    }

    public function testAnErrorAnswerIsAnErrorNotNoOffers(): void
    {
        $client = $this->client('<?xml version="1.0" encoding="utf-8"?><Response ResponseType="Error">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><Errors><Error><ErrorId>-1000</ErrorId>'
            . '<ErrorText>You are not authorised to access this server!</ErrorText></Error></Errors></ResponseDetails></Response>');

        $this->expectException(EurositeApiException::class);
        $this->expectExceptionMessage('getPackageNVPriceRequest failed');
        $client->searchPackages(self::search());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function client(string $answer): EurositeApiClient
    {
        $this->transport = new CannedTransport($answer);

        return new EurositeApiClient($this->transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA');
    }

    /** What the client sent last ('' for nothing). */
    private function sent(): string
    {
        return $this->transport?->lastRequest ?? '';
    }

    private function offerWith(string $services): PackageOffer
    {
        return $this->client(self::wrap(
            'getPackageNVPriceResponse',
            '<Hotel><Product><TourOpCode>EU</TourOpCode><ProductCode>GR0001</ProductCode></Product><Offers><Offer CurrencyCode="EUR">'
            . '<Availability Code="IM">Immediate</Availability><ProductPrice>900</ProductPrice>'
            . '<PackageId>7</PackageId><PackageVariantId>1|1_2_3</PackageVariantId>'
            . '<PriceDetails><Services>' . $services . '</Services></PriceDetails>'
            . '</Offer></Offers></Hotel>',
        ))->searchPackages(self::search())[0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function search(): array
    {
        return [
            'country_code' => 'TR',
            'city_code' => 'TRALN',
            'dep_country_code' => 'RO',
            'dep_city_code' => 'ROBCH1',
            'transport' => 'plane',
            'check_in' => '2012-09-21',
            'check_out' => '2012-09-28',
            'rooms' => [['code' => 'DB', 'adults' => 2, 'children' => []]],
        ];
    }

    /** The request's payload, as sent: the element named, without the envelope. */
    private static function details(string $request, string $element): string
    {
        self::assertSame(1, preg_match('#<' . $element . '>.*</' . $element . '>#s', $request, $m), 'No <' . $element . '> in the request.');

        return $m[0];
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/' . $name);
    }

    private static function wrap(string $responseType, string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<Response ResponseType="' . $responseType . '">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><' . $responseType . '>' . $inner . '</' . $responseType . '></ResponseDetails>'
            . '</Response>';
    }
}
