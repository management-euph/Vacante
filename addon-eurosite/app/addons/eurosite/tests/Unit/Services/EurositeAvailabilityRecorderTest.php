<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\Eurosite\Services\EurositeAvailabilityRecorder;
use Tygh\Addons\Eurosite\Services\EurositeAvailabilitySource;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\Services\OfferTally;

/**
 * What Eurosite writes into Travel Core's hotel availability records
 * (docs/novoton-availability-gaps-plan.md §15.3). The statements are Travel
 * Core's, run on MariaDB 10.11 when written; these tests pin what Eurosite
 * hands them.
 */
final class EurositeAvailabilityRecorderTest extends TestCase
{
    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->sql = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            // Not the tables heal, which runs once per request (process).
            if (preg_match('/^\s*(CREATE|ALTER) TABLE/', $query) !== 1) {
                $this->sql[] = db_quote($query, ...$params);
            }

            return 1;
        };
        // Every city is Mamaia, and the heal finds its column there.
        DbStub::$getField = static fn (string $query): string|int => match (true) {
            str_contains($query, 'eurosite_cities') => 'Mamaia',
            str_contains($query, 'SHOW COLUMNS') => 'hotel_name',
            default => 0,
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private static function offer(string $product, string $code): HotelOffer
    {
        return new HotelOffer(
            productCode: $product,
            productName: 'Hotel',
            countryCode: 'RO',
            cityCode: 'RO0363',
            cityName: 'Mamaia',
            category: 3,
            class: 'Hotel',
            firstImage: '',
            latitude: '',
            longitude: '',
            currency: 'EUR',
            offerType: 'CAZARE',
            availability: '',
            checkIn: '',
            checkOut: '',
            price: 100.0,
            gross: 110.0,
            net: 100.0,
            commission: 0.0,
            variantId: 'v',
            grila: '',
            availabilityCode: $code,
        );
    }

    /** @param list<string> $codes */
    private static function mamaia(array $codes, string $status = 'active'): void
    {
        DbStub::$getArray = static fn (): array => array_map(static fn (string $code): array => [
            'product_code' => $code,
            'product_id' => $code === 'H1' ? '15' : null,
            'name' => 'Hotel ' . $code,
            'country_code' => 'RO',
            'city_code' => 'RO0363',
            'sync_status' => $status,
        ], $codes);
    }

    public function testImmediateAndOnRequestCountAStopSaleDoesNot(): void
    {
        $tally = new OfferTally();
        EurositeAvailabilityRecorder::addOffer($tally, self::offer('H1', 'IM'), '2027-07-15');
        EurositeAvailabilityRecorder::addOffer($tally, self::offer('H2', 'OR'), '2027-07-15');
        EurositeAvailabilityRecorder::addOffer($tally, self::offer('H3', ''), '2027-07-15');
        EurositeAvailabilityRecorder::addOffer($tally, self::offer('H4', 'ST'), '2027-07-15');

        $offers = $tally->offers();
        self::assertSame(['H1', 'H2', 'H3'], array_keys($offers));
        self::assertTrue($offers['H1']['immediate']);
        self::assertFalse($offers['H2']['immediate']);
        self::assertFalse($offers['H3']['immediate'], 'no code reads as On request, as the gate reads it');
    }

    public function testACompleteCityListMarksTheOthersMissing(): void
    {
        self::mamaia(['H1', 'H2', 'H3']);

        $result = (new EurositeAvailabilityRecorder())->hotelList('RO0363', [['code' => 'H1'], ['code' => ' H2 ']], '2026-10-24 03:00:00');

        self::assertSame(['complete' => true, 'seen' => 2, 'missing' => 1], $result, 'nothing listed before: any list is complete');
        self::assertStringContainsString("('eurosite', 'H1', 15, 'RO|Mamaia (RO0363)', 'Hotel H1', NULL, '2026-10-24 03:00:00', 0)", $this->sql[0]);
        self::assertStringContainsString("hotel_id IN ('H3')", $this->sql[1]);
    }

    public function testAShortOrEmptyCityListMarksNobodyMissing(): void
    {
        self::mamaia(['H1', 'H2', 'H3']);
        DbStub::$getField = static fn (string $query): string|int => str_contains($query, 'COUNT(*)') ? 3 : 'Mamaia';

        $short = (new EurositeAvailabilityRecorder())->hotelList('RO0363', [['code' => 'H1']], '2026-10-24 03:00:00');
        self::assertSame(['complete' => false, 'seen' => 1, 'missing' => 0], $short, '1 of the 3 listed last time');
        self::assertCount(1, $this->sql, 'seen written, nobody missing');

        $this->sql = [];
        $empty = (new EurositeAvailabilityRecorder())->hotelList('RO0363', [], '2026-10-24 03:00:00');
        self::assertSame(['complete' => false, 'seen' => 0, 'missing' => 0], $empty);
        self::assertSame([], $this->sql);
    }

    public function testAWholeCityAnswerChecksEveryListedHotel(): void
    {
        self::mamaia(['H1', 'H2']);
        $offers = new OfferTally();
        EurositeAvailabilityRecorder::addOffer($offers, self::offer('H1', 'IM'), '2026-11-06');

        $result = (new EurositeAvailabilityRecorder())->runChecked(
            [['tourop' => 'LA', 'city' => 'RO0363', 'offers' => $offers, 'complete' => true]],
            '2026-10-24 04:30:00',
        );

        self::assertSame(['recorded' => true, 'cities' => 1, 'hotels' => 2], $result);
        self::assertCount(1, $this->sql);
        self::assertStringContainsString('offer_checked_at', $this->sql[0]);
        self::assertStringContainsString("('eurosite', 'H2', 0, 'RO|Mamaia (RO0363)', 'Hotel H2', '2026-10-24 04:30:00', NULL, NULL, NULL, NULL, NULL)", $this->sql[0], 'checked, no offer');
    }

    public function testAPartAnswerRecordsOnlyItsOffers(): void
    {
        self::mamaia(['H1', 'H2']);
        $offers = new OfferTally();
        EurositeAvailabilityRecorder::addOffer($offers, self::offer('H2', 'OR'), '2027-08-15');

        (new EurositeAvailabilityRecorder())->runChecked(
            [['tourop' => 'LA', 'city' => 'RO0363', 'offers' => $offers, 'complete' => false]],
            '2026-10-24 04:30:00',
        );

        self::assertCount(1, $this->sql);
        self::assertStringNotContainsString('offer_checked_at', $this->sql[0], 'a date that did not answer may have held an offer');
        self::assertStringNotContainsString("'H1'", $this->sql[0]);
        self::assertStringContainsString("'H2'", $this->sql[0]);
    }

    public function testARunWithNoOfferAnywhereIsNoAnswer(): void
    {
        self::mamaia(['H1', 'H2']);

        $result = (new EurositeAvailabilityRecorder())->runChecked([
            ['tourop' => 'LA', 'city' => 'RO0363', 'offers' => new OfferTally(), 'complete' => true],
            ['tourop' => 'LA', 'city' => 'RO0400', 'offers' => new OfferTally(), 'complete' => true],
        ], '2026-10-24 04:30:00');

        self::assertFalse($result['recorded']);
        self::assertSame([], $this->sql, 'an account with nothing connected must not read as every hotel checked');
    }

    public function testASearchRecordsTheOffersOfListedHotelsOnly(): void
    {
        // The search found H1 and H9; only H1 is listed in this store.
        self::mamaia(['H1']);

        (new EurositeAvailabilityRecorder())->searchFound(
            [self::offer('H1', 'IM'), self::offer('H9', 'IM'), self::offer('H1', 'ST')],
            '2026-12-20',
            '2026-10-24 18:00:00',
        );

        self::assertCount(1, $this->sql);
        self::assertStringContainsString("('eurosite', 'H1', 15, 'RO|Mamaia (RO0363)', 'Hotel H1', '2026-10-24 18:00:00', '2026-12-20', '2026-10-24 18:00:00'", $this->sql[0]);
        self::assertStringNotContainsString("'H9'", $this->sql[0]);

        $this->sql = [];
        (new EurositeAvailabilityRecorder())->searchFound([self::offer('H1', 'IM')], 'soon', '2026-10-24 18:00:00');
        self::assertSame([], $this->sql, 'no stay date, nothing recorded');
    }

    public function testAFailedWriteNeverStopsTheJob(): void
    {
        self::mamaia(['H1']);
        DbStub::$query = static fn (): never => throw new \RuntimeException('database down');
        $GLOBALS['eurosite_logged_events'] = [];

        $result = (new EurositeAvailabilityRecorder())->hotelList('RO0363', [['code' => 'H1']], '2026-10-24 03:00:00');

        self::assertSame(['complete' => false, 'seen' => 0, 'missing' => 0], $result);
        self::assertStringContainsString('[EurositeAvailability] hotels RO0363: database down', (string) json_encode($GLOBALS['eurosite_logged_events']));
    }

    public function testTheDestinationIsTheCityReadableAndUnique(): void
    {
        self::assertSame('RO|Mamaia (RO0363)', EurositeAvailabilityRecorder::destinationKey('ro', ' Mamaia ', 'RO0363'));
        self::assertSame('RO|RO0363', EurositeAvailabilityRecorder::destinationKey('RO', '', 'RO0363'), 'the code alone while the name is unknown');
        $long = EurositeAvailabilityRecorder::destinationKey('RO', str_repeat('Ș', 300), 'RO0363');
        self::assertSame(128, mb_strlen($long));
        self::assertStringEndsWith(' (RO0363)', $long, 'a long name is cut, never the code');
    }

    public function testEurositeDeclaresWhatItCanTell(): void
    {
        $source = new EurositeAvailabilitySource();
        $caps = $source->capabilities();

        self::assertSame('eurosite', $source->provider());
        self::assertSame(HotelListCoverage::PerCity, $caps->hotelList);
        self::assertFalse($caps->publishedPrices, 'Eurosite publishes no season prices ahead');
        self::assertFalse($caps->pastPrices, 'nothing older to fill in');
        self::assertSame('city', $caps->liveCheckScope);
        self::assertSame(['hotels', 'availability', 'full'], $source->recordingModes());

        $init = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');
        self::assertStringContainsString("setAvailabilitySource(\n        'eurosite',\n        new \\Tygh\\Addons\\Eurosite\\Services\\EurositeAvailabilitySource(),", $init);
    }
}
