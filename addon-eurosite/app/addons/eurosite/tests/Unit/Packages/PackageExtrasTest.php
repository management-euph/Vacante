<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Services\PackageExtras;
use Tygh\Addons\Eurosite\Tests\Support\ScriptedTransport;

/**
 * "Servicii adiționale" on a package's booking page: what Eurosite offers
 * for the variant, priced for the travellers, what the guest may tick, and
 * how the chosen ones reach Eurosite with the booking.
 */
final class PackageExtrasTest extends TestCase
{
    private const string ADDON = __DIR__ . '/../../..';

    private const array SNAPSHOT = [
        'country_code' => 'TR', 'city_code' => 'TRALN', 'tourop_code' => 'EU', 'product_code' => 'TR0012',
        'variant_id' => '1|1068741_669_1', 'check_in' => '2027-07-03', 'check_out' => '2027-07-10',
    ];

    private const array OCCUPANCY = [['adults' => 2, 'children_ages' => [3]]];

    /** @var list<string> */
    private array $sent = [];

    /**
     * @param list<array{type: string, code: string, name: string, has_price: string, charter_id?: string}> $offered
     * @param array<string, string> $prices service code => the price answered ('' = an error)
     */
    private function api(array $offered, array $prices): EurositeApiClient
    {
        $this->sent = [];
        $types = '';
        foreach ($offered as $s) {
            $types .= '<Service><Type>' . $s['type'] . '</Type><TypeName>' . ($s['type_name'] ?? 'Masa') . '</TypeName>'
                . '<Code>' . $s['code'] . '</Code><Name>' . $s['name'] . '</Name>'
                . '<HasPrice>' . $s['has_price'] . '</HasPrice><CharterId>' . ($s['charter_id'] ?? '') . '</CharterId>'
                . '<Provider>P</Provider><VATNumber>1</VATNumber></Service>';
        }
        $transport = new ScriptedTransport(function (string $xml) use ($types, $prices): string {
            $this->sent[] = $xml;
            if (str_contains($xml, 'getHotelServiceTypesRequest')) {
                return self::wrap('getHotelServiceTypesResponse', '<Services>' . $types . '</Services>');
            }
            preg_match('#<ServiceCode>([^<]*)</ServiceCode>#', $xml, $m);
            $price = $prices[$m[1] ?? ''] ?? '';
            if ($price === '') {
                return self::error();
            }

            return self::wrap('getHotelServicePriceResponse', '<Services><Service CurrencyCode="EUR"><Type>2</Type>'
                . '<Code>' . ($m[1] ?? '') . '</Code><Name>x</Name><Price>' . $price . '</Price></Service></Services>');
        });

        return new EurositeApiClient($transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA');
    }

    public function testOnlyPricedExtrasAreOfferedCheapestFirst(): void
    {
        $extras = (new PackageExtras($this->api([
            ['type' => '2', 'code' => '12', 'name' => 'DEMIPENSIUNE', 'has_price' => 'true', 'type_name' => 'Masa'],
            ['type' => '6', 'code' => '548', 'name' => 'transfer privat aeroport - hotel', 'has_price' => 'true', 'type_name' => 'Alte servicii'],
            // No price of its own: not shown.
            ['type' => '6', 'code' => '9', 'name' => 'WI-FI GRATUIT', 'has_price' => 'false'],
            // Answers a price of 0: nothing to charge, so not shown.
            ['type' => '6', 'code' => '7', 'name' => 'NIMIC', 'has_price' => 'true'],
        ], ['12' => '196', '548' => '66', '7' => '0'])))->forPackage(self::SNAPSHOT, self::OCCUPANCY, 'EUR', 'RO');

        self::assertSame([
            ['code' => '548', 'type' => '6', 'charter_id' => '', 'title' => 'Transfer privat aeroport - hotel', 'note' => 'Alte servicii', 'price' => 66.0, 'currency' => 'EUR'],
            ['code' => '12', 'type' => '2', 'charter_id' => '', 'title' => 'Demipensiune', 'note' => 'Masa', 'price' => 196.0, 'currency' => 'EUR'],
        ], $extras);
    }

    public function testTheVariantAndEveryTravellerAreAsked(): void
    {
        (new PackageExtras($this->api(
            [['type' => '7', 'code' => '3', 'name' => 'Transfer CONSTANTA - BUCURESTI', 'has_price' => 'true', 'charter_id' => '90', 'type_name' => 'Supliment charter']],
            ['3' => '40'],
        )))->forPackage(self::SNAPSHOT, self::OCCUPANCY, 'EUR', 'RO');

        self::assertCount(2, $this->sent, 'the list, then the one price');
        self::assertStringContainsString('<VariantId>1|1068741_669_1</VariantId>', $this->sent[0]);
        self::assertStringContainsString('<CheckIn>2027-07-03</CheckIn><CheckOut>2027-07-10</CheckOut>', $this->sent[0]);

        $price = $this->sent[1];
        self::assertStringContainsString('<ServiceType>7</ServiceType><ServiceCode>3</ServiceCode><CharterId>90</CharterId>', $price, 'a charter supplement carries its charter');
        self::assertStringContainsString('<CurrencyCode>EUR</CurrencyCode>', $price);
        // Every traveller, children with their age (the spec asks for all of them).
        self::assertStringContainsString(
            '<PaxNames><PaxName PaxType="adult">Adult 1</PaxName><PaxName PaxType="adult">Adult 2</PaxName><PaxName PaxType="child" ChildAge="3">Copil 1</PaxName></PaxNames>',
            $price,
        );
    }

    /** An extra that cannot be priced is left out; the others still show. */
    public function testOneExtraFailingDoesNotTakeTheOthersDown(): void
    {
        $extras = (new PackageExtras($this->api([
            ['type' => '2', 'code' => '12', 'name' => 'Demipensiune', 'has_price' => 'true'],
            ['type' => '6', 'code' => '99', 'name' => 'Ceva', 'has_price' => 'true'],
        ], ['12' => '196'])))->forPackage(self::SNAPSHOT, self::OCCUPANCY, 'EUR', 'RO');

        self::assertSame(['12'], array_column($extras, 'code'));
    }

    /** Eurosite not answering the list at all: no extras, and the page still works. */
    public function testNoListIsNoExtras(): void
    {
        $transport = new ScriptedTransport(static fn (string $xml): string => self::error());
        $api = new EurositeApiClient($transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA');

        self::assertSame([], (new PackageExtras($api))->forPackage(self::SNAPSHOT, self::OCCUPANCY, 'EUR', 'RO'));
    }

    /** A long list does not keep the page waiting. */
    public function testAtMostEightArePriced(): void
    {
        $offered = [];
        $prices = [];
        for ($i = 1; $i <= 12; $i++) {
            $offered[] = ['type' => '6', 'code' => (string) $i, 'name' => 'Serviciu ' . $i, 'has_price' => 'true'];
            $prices[(string) $i] = (string) (10 * $i);
        }
        $extras = (new PackageExtras($this->api($offered, $prices)))->forPackage(self::SNAPSHOT, self::OCCUPANCY, 'EUR', 'RO');

        self::assertSame(PackageExtras::MAX_PRICED, count($extras));
        self::assertSame(1 + PackageExtras::MAX_PRICED, count($this->sent));
    }

    // ── What the guest ticks ────────────────────────────────────────────

    public function testOnlyWhatThePageOfferedCanBeCharged(): void
    {
        $offered = [
            ['code' => '12', 'type' => '2', 'charter_id' => '', 'title' => 'Demipensiune', 'note' => '', 'price' => 196.0, 'currency' => 'EUR'],
            ['code' => '548', 'type' => '6', 'charter_id' => '', 'title' => 'Transfer privat', 'note' => '', 'price' => 66.0, 'currency' => 'EUR'],
        ];

        self::assertSame(['12'], array_column(PackageExtras::chosen($offered, ['12']), 'code'));
        self::assertSame(['12', '548'], array_column(PackageExtras::chosen($offered, ['548', '12']), 'code'), 'in the order they were offered');
        // A code the page never offered, and nothing ticked.
        self::assertSame([], PackageExtras::chosen($offered, ['999']));
        self::assertSame([], PackageExtras::chosen($offered, null));
        self::assertSame([], PackageExtras::chosen([], ['12']));
        // A code sent twice is charged once.
        self::assertCount(1, PackageExtras::chosen($offered, ['12', '12']));

        self::assertSame(262.0, PackageExtras::total($offered));
        self::assertSame(0.0, PackageExtras::total([]));
    }

    public function testTheChosenExtrasReachEurositeWithTheBooking(): void
    {
        $extras = [['code' => '12', 'type' => '2'], ['code' => '548', 'type' => '6']];
        $booking = ['check_in' => '2027-07-03', 'check_out' => '2027-07-10'];

        self::assertSame([
            ['code' => '12', 'type' => '2', 'check_in' => '2027-07-03', 'check_out' => '2027-07-10', 'pax_ids' => [1, 2, 3]],
            ['code' => '548', 'type' => '6', 'check_in' => '2027-07-03', 'check_out' => '2027-07-10', 'pax_ids' => [1, 2, 3]],
        ], PackageExtras::suppServices($extras, $booking, 3));
        self::assertSame([], PackageExtras::suppServices([], $booking, 3));
        self::assertSame([], PackageExtras::suppServices($extras, $booking, 0)[0]['pax_ids']);
    }

    /** AddBookingRequest carries them where the spec's sample has them. */
    public function testAddBookingSendsSuppServicesAfterTheVariant(): void
    {
        $transport = new ScriptedTransport(static fn (string $xml): string => self::wrap(
            'AddBookingResponse',
            '<BookingReferences><BookingReference Source="api">EU1</BookingReference></BookingReferences>',
        ));
        $api = new EurositeApiClient($transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA');

        $api->addBooking([
            'country_code' => 'TR', 'city_code' => 'TRALN', 'product_code' => 'TR0012', 'variant_id' => '1|1_2_3',
            'package_id' => '4', 'check_in' => '2027-07-03', 'check_out' => '2027-07-10',
            'rooms' => [['code' => '274', 'adults' => 2, 'children' => [], 'pax' => [['type' => 'adult', 'name' => 'Pop / Ana']]]],
            'supp_services' => [
                ['code' => '12', 'type' => '2', 'check_in' => '2027-07-03', 'check_out' => '2027-07-10', 'pax_ids' => [1, 2]],
            ],
        ]);

        self::assertStringContainsString(
            '<VariantId>1|1_2_3</VariantId><SuppServices><Service><Code>12</Code><Type>2</Type>'
            . '<PeriodOfStay><CheckIn>2027-07-03</CheckIn><CheckOut>2027-07-10</CheckOut></PeriodOfStay>'
            . '<PaxIds><PaxId>1</PaxId><PaxId>2</PaxId></PaxIds></Service></SuppServices><Rooms>',
            $transport->requests[0],
        );

        // None chosen: no SuppServices element at all.
        $api->addBooking(['product_code' => 'TR0012', 'variant_id' => '1|1_2_3', 'rooms' => []]);
        self::assertStringNotContainsString('<SuppServices>', $transport->requests[1]);
    }

    // ── The page and the cart ───────────────────────────────────────────

    public function testThePageShowsThemAndTheCartChargesOnlyThose(): void
    {
        $controller = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/package_booking_form.php');
        $template = (string) file_get_contents(self::ADDON . '/../../../design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/package_booking_form.tpl');
        $cart = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/package_add_to_cart.php');
        $submit = (string) file_get_contents(self::ADDON . '/src/Services/BookingSubmissionService.php');

        // The page prices them and keeps them with the snapshot.
        self::assertStringContainsString('(new PackageExtras(Container::getApi()))->forPackage(', $controller);
        self::assertStringContainsString('PackageSnapshotStore::remember($offer, $token, $occupancy, $cartProductId, $extras)', $controller);
        // The shared script moves every total when one is ticked.
        self::assertStringContainsString('data-travel-extras data-base="{$es_money.base}"', $template);
        self::assertStringContainsString('name="services[]"', $template);
        self::assertStringContainsString('data-amount="{$es_extra.display_amount}"', $template);
        self::assertStringContainsString('data-travel-extras-live', $template);

        // The cart charges the snapshot's price for the ticked ones, never the POST's.
        self::assertStringContainsString("PackageExtras::chosen(TypeCoerce::toRowList(\$snapshot['extras'] ?? null), \$_POST['services'] ?? null)", $cart);
        self::assertStringContainsString("round(TypeCoerce::toFloat(\$snapshot['price']) + \$extrasTotal, 2)", $cart);
        self::assertStringContainsString("'extras_json' => (string) json_encode(\$extras, JSON_UNESCAPED_UNICODE),", $cart);
        // And they go to Eurosite with the booking.
        self::assertStringContainsString("'supp_services' => PackageExtras::suppServices(", $submit);

        $lang = require self::ADDON . '/lang_keys.php';
        self::assertArrayHasKey('eurosite.additional_services', $lang);
    }

    private static function wrap(string $type, string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><Response ResponseType="' . $type . '">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><' . $type . '>' . $inner . '</' . $type . '></ResponseDetails></Response>';
    }

    private static function error(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><Response ResponseType="Error">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><Errors><Error><ErrorId>-7</ErrorId><ErrorText>No price</ErrorText></Error></Errors></ResponseDetails></Response>';
    }
}
