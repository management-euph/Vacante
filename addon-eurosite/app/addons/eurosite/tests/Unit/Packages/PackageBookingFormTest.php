<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\Dto\PackageOffer;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Services\PackageOfferToken;
use Tygh\Addons\Eurosite\Services\PackageQuote;
use Tygh\Addons\Eurosite\Services\PackageSnapshotStore;
use Tygh\Addons\Eurosite\Services\PackageTripSummary;
use Tygh\Addons\Eurosite\Tests\Support\CannedTransport;

/**
 * The booking page of a package: the offer asked of Eurosite again, kept
 * server-side, summarised, and its cancellation schedule asked with its
 * PackageId; the page, its form and its labels wired.
 */
final class PackageBookingFormTest extends TestCase
{
    private const string ADDON = __DIR__ . '/../../..';

    /** The spec sample's first offer, as its token names it. */
    private const array TOKEN = [
        'product_code' => 'TR0012', 'tourop_code' => 'EU', 'country_code' => 'TR', 'city_code' => 'TRALN',
        'dep_country_code' => 'RO', 'dep_city_code' => 'ROBCH1', 'transport' => 'plane', 'departure_date' => '2012-09-21',
        'nights' => '7', 'package_id' => '4', 'variant_id' => '1|1068746_669_1', 'room_codes' => '274',
        'check_in' => '2012-09-21', 'check_out' => '2012-09-28', 'price' => '1321.6', 'currency' => 'EUR',
    ];

    private ?CannedTransport $transport = null;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\Tygh\Tygh::class)) {
            eval('namespace Tygh; final class Tygh { /** @var array<string, mixed> */ public static $app = []; }');
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        \Tygh\Tygh::$app['session'] = new \ArrayObject();
    }

    private function api(string $answer): EurositeApiClient
    {
        $this->transport = new CannedTransport($answer);

        return new EurositeApiClient($this->transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'LA');
    }

    private function found(): PackageOffer
    {
        $offer = (new PackageQuote($this->api(self::sample()), new PackageOfferToken('k')))->find(self::TOKEN, [['adults' => 2, 'children_ages' => []]], 'RO');
        self::assertNotNull($offer);

        return $offer;
    }

    private static function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/package_price_response.xml');
    }

    // ── The offer asked again ───────────────────────────────────────────

    public function testTheTokensOfferIsFoundAgainForTheSameDeparture(): void
    {
        $offer = $this->found();

        self::assertSame(['4', '1|1068746_669_1', 1321.6], [$offer->packageId, $offer->variantId(), $offer->hotel->price]);
        $sent = $this->transport?->lastRequest ?? '';
        foreach ([
            '<DepCountryCode>RO</DepCountryCode><DepCityCode>ROBCH1</DepCityCode><Transport>plane</Transport><TourOpCode>EU</TourOpCode>',
            '<CheckIn>2012-09-21</CheckIn><CheckOut>2012-09-28</CheckOut></PeriodOfStay><Days>1</Days>',
            '<Room Code="DB" NoAdults="2"/>',
        ] as $part) {
            self::assertStringContainsString($part, $sent);
        }
    }

    public function testAnOfferEurositeNoLongerHasIsNotFound(): void
    {
        $quote = new PackageQuote($this->api(self::sample()), new PackageOfferToken('k'));
        $rooms = [['adults' => 2, 'children_ages' => []]];

        self::assertNull($quote->find(['variant_id' => '1|gone'] + self::TOKEN, $rooms, 'RO'));
        self::assertNull($quote->find(['product_code' => 'TR0099'] + self::TOKEN, $rooms, 'RO'));

        // Not a package the store could have quoted: nothing is asked.
        $this->transport = null;
        $quiet = new PackageQuote($this->api(self::sample()), new PackageOfferToken('k'));
        self::assertNull($quiet->find(['transport' => 'flight'] + self::TOKEN, $rooms, 'RO'));
        self::assertNull($quiet->find(['nights' => '0', 'departure_date' => 'soon'] + self::TOKEN, $rooms, 'RO'));
        self::assertNull($quiet->find(['package_id' => ''] + self::TOKEN, $rooms, 'RO'));
        self::assertSame('', $this->transport?->lastRequest ?? '');
    }

    // ── Kept server-side ────────────────────────────────────────────────

    public function testTheSnapshotHasTheHotelsKeysAndThePackagesOwn(): void
    {
        $snapshot = PackageSnapshotStore::snapshot($this->found(), self::TOKEN, [['adults' => 2, 'children_ages' => [5]]], 77);

        self::assertSame('package', $snapshot['kind']);
        foreach ([
            'product_code' => 'TR0012', 'cart_product_id' => 77, 'country_code' => 'TR', 'city_code' => 'TRALN',
            'check_in' => '2012-09-21', 'check_out' => '2012-09-28', 'variant_id' => '1|1068746_669_1', 'price' => 1321.6,
            'currency' => 'EUR', 'availability_code' => 'OR', 'adults' => 2, 'children_ages' => [5],
            'package_id' => '4', 'tourop_code' => 'EU', 'transport' => 'plane', 'dep_city_code' => 'ROBCH1',
            'departure_date' => '2012-09-21', 'nights' => 7, 'accept_remarks' => true,
        ] as $key => $value) {
            self::assertSame($value, $snapshot[$key], $key);
        }
        self::assertSame([['adults' => 2, 'children_ages' => [5]]], $snapshot['rooms_occupancy']);
        self::assertSame('274', $snapshot['rooms'][0]['code'] ?? '');
        self::assertCount(5, $snapshot['services']);
    }

    /** The form posts only a key; another package opened later does not take this one away. */
    public function testTheSnapshotIsKeptUnderItsKey(): void
    {
        $offer = $this->found();
        $first = PackageSnapshotStore::remember($offer, self::TOKEN, [['adults' => 2, 'children_ages' => []]], 77);
        $second = PackageSnapshotStore::remember($offer, self::TOKEN, [['adults' => 2, 'children_ages' => [5]]], 77);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        self::assertNotSame($first, $second);
        self::assertSame(2, PackageSnapshotStore::get($first)['adults'] ?? null);
        self::assertSame([5], PackageSnapshotStore::get($second)['children_ages'] ?? null);
        self::assertNull(PackageSnapshotStore::get(str_repeat('0', 32)));
    }

    // ── The summary's trip ──────────────────────────────────────────────

    public function testTheFlightAndWhatThePriceIncludes(): void
    {
        $snapshot = PackageSnapshotStore::snapshot($this->found(), self::TOKEN, [['adults' => 2, 'children_ages' => []]], 77);
        $words = ['out' => 'Dus', 'back' => 'Retur', 'bus' => 'Autocar'];

        self::assertSame([
            ['title' => '✈ Dus · 21.09.2012', 'line' => '07:05 Otopeni → 08:50 Antalya', 'note' => ''],
            ['title' => '✈ Retur · 28.09.2012', 'line' => '09:40 Antalya → 11:25 Otopeni', 'note' => ''],
        ], PackageTripSummary::legs($snapshot, $words, static fn (int $ts): string => date('d.m.Y', $ts)));
        // The transfer and the airport tax; not the package's commission.
        self::assertSame(['Transfer aeroport - hotel - aeroport', 'Taxa de aeroport'], PackageTripSummary::includes($snapshot));
    }

    public function testACoachTripListsEachJourney(): void
    {
        $snapshot = ['transport' => 'bus', 'check_in' => '2027-06-12', 'check_out' => '2027-06-19', 'services' => [
            ['type' => '7', 'code' => '6', 'name' => 'Transport Autocar THASSOS - BUCURESTI', 'start' => '2027-06-19 10:00:00', 'end' => '2027-06-19 22:00:00'],
            ['type' => '7', 'code' => '5', 'name' => 'Transport Autocar BUCURESTI - THASSOS', 'start' => '2027-06-11 20:00:00', 'end' => '2027-06-12 08:00:00'],
            ['type' => '7', 'code' => '7', 'name' => 'Autocar de noapte', 'start' => '2027-06-20', 'end' => '2027-06-20'],
            ['type' => '8', 'code' => '1', 'name' => 'Comision pachet', 'start' => '', 'end' => ''],
        ]];

        self::assertSame([
            ['title' => 'Autocar · 11.06.2027', 'line' => 'Bucuresti → Thassos', 'note' => ''],
            ['title' => 'Autocar · 19.06.2027', 'line' => 'Thassos → Bucuresti', 'note' => ''],
            ['title' => 'Autocar · 20.06.2027', 'line' => 'Autocar de noapte', 'note' => ''],
        ], PackageTripSummary::legs($snapshot, ['out' => 'Dus', 'back' => 'Retur', 'bus' => 'Autocar'], static fn (int $ts): string => date('d.m.Y', $ts)));
        self::assertSame([], PackageTripSummary::includes($snapshot));
        self::assertSame([], PackageTripSummary::legs(['services' => []], ['out' => '', 'back' => '', 'bus' => ''], static fn (int $ts): string => ''));
    }

    public function testEurositesCapitalsReadAsASentence(): void
    {
        self::assertSame('Taxa de aeroport', PackageTripSummary::sentence('TAXA DE AEROPORT'));
        self::assertSame('Transfer aeroport', PackageTripSummary::sentence('  transfer   aeroport '));
        self::assertSame('Asigurare Medicală', PackageTripSummary::sentence('Asigurare Medicală'));
        self::assertSame('', PackageTripSummary::sentence(' '));
    }

    // ── The cancellation schedule ───────────────────────────────────────

    /** As the spec's sample: the PackageId between the stay and the VariantId; a hotel sends none. */
    public function testThePackagesFeesAreAskedWithItsPackageId(): void
    {
        $fees = $this->api('<?xml version="1.0" encoding="utf-8"?><Response ResponseType="getItemFeesResponse">'
            . '<AuditInfo><ResponseId>1</ResponseId><RequestId>1</RequestId><ResponseTime>t</ResponseTime></AuditInfo>'
            . '<ResponseDetails><getItemFeesResponse><ItemFees><ItemFee><Fees>'
            . '<Fee Type="cancellation"><FromDate>2012-09-06</FromDate><ToDate>2012-09-11</ToDate><Value Procent="false">708.5</Value></Fee>'
            . '</Fees></ItemFee></ItemFees></getItemFeesResponse></ResponseDetails></Response>')->getItemFees([
                'currency' => 'EUR', 'country_code' => 'TR', 'city_code' => 'TRALN', 'product_code' => 'TR0012',
                'package_id' => '4', 'variant_id' => '1|1068741_669_1', 'check_in' => '2012-09-21', 'check_out' => '2012-09-28',
                'tourop_code' => 'EU', 'rooms' => [['code' => '274', 'adults' => 2, 'children' => [3]]],
            ]);

        self::assertSame([['type' => 'cancellation', 'from_date' => '2012-09-06', 'to_date' => '2012-09-11', 'value' => 708.5, 'is_percent' => false]], $fees);
        self::assertStringContainsString(
            '<CheckOut>2012-09-28</CheckOut></PeriodOfStay><PackageId>4</PackageId><VariantId>1|1068741_669_1</VariantId><Rooms><Room Code="274" NoAdults="2" NoChildren="1">',
            $this->transport?->lastRequest ?? '',
        );

        $this->api('<?xml version="1.0" encoding="utf-8"?><Response ResponseType="getItemFeesResponse"><AuditInfo/><ResponseDetails><getItemFeesResponse/></ResponseDetails></Response>')
            ->getItemFees(['product_code' => 'RO0099', 'variant_id' => '0|1', 'rooms' => [['code' => '40', 'adults' => 2]]]);
        self::assertStringNotContainsString('<PackageId>', $this->transport?->lastRequest ?? '');
    }

    // ── The page ────────────────────────────────────────────────────────

    public function testThePageIsWiredAndAsksBeforeItShows(): void
    {
        $router = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking.php');
        $controller = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/package_booking_form.php');
        $template = (string) file_get_contents(self::ADDON . '/../../../design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/package_booking_form.tpl');

        self::assertStringContainsString("'package_booking_form' => 'package_booking_form.php',", $router);
        // Eurosite is asked again before anything is kept or shown.
        $finds = (int) strpos($controller, '->find($token, $occupancy');
        self::assertGreaterThan(0, $finds);
        self::assertLessThan((int) strpos($controller, 'PackageSnapshotStore::remember('), $finds);
        self::assertStringContainsString("'package_id' => \$offer->packageId,", $controller, 'the fees are a package\'s');
        self::assertStringContainsString('->withTrip(', $controller);

        // The form posts the key only, to the package's add-to-cart; every adult's birth date is required.
        self::assertStringContainsString('action="{"eurosite_booking.package_add_to_cart"|fn_url}"', $template);
        self::assertStringContainsString('<input type="hidden" name="package_key" value="{$eurosite_package_key}" />', $template);
        self::assertStringContainsString('gb_adult_dob_required=true', $template);
        self::assertStringContainsString('gh_airline=$eurosite_package_flight', $template);
        self::assertStringContainsString('mb_form_id="eurosite-package-booking-form"', $template);
    }

    public function testEveryLabelTheControllerUsesExists(): void
    {
        $lang = require self::ADDON . '/lang_keys.php';
        $controller = (string) file_get_contents(self::ADDON . '/controllers/frontend/eurosite_booking/package_booking_form.php');
        preg_match_all("/__\\('(eurosite\\.[a-z0-9_]+)'/", $controller, $m);

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $key) {
            self::assertArrayHasKey($key, $lang, $key);
        }
    }
}
