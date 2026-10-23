<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\HotelAvailabilityGate;
use Tygh\Addons\SphinxHolidays\Services\SphinxAvailabilityRecorder;
use Tygh\Addons\SphinxHolidays\Services\SphinxAvailabilitySource;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\Services\OfferTally;

/**
 * What Sphinx writes into Travel Core's hotel availability records
 * (docs/novoton-availability-gaps-plan.md §15.3). The statements are Travel
 * Core's, run on MariaDB 10.11 when written; these tests pin what Sphinx
 * hands them.
 */
final class SphinxAvailabilityRecorderTest extends TestCase
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
        DbStub::$getField = static fn (string $query): string|int => str_contains($query, 'SHOW COLUMNS') ? 'hotel_name' : 0;
        // Halkidiki holds H1 (product 42), H2 and H3.
        DbStub::$getArray = static function (string $query, mixed ...$params): array {
            $ids = is_array($params[0] ?? null) ? $params[0] : [];

            return array_values(array_map(static fn (string $id): array => [
                'hotel_id' => $id,
                'product_id' => $id === 'H1' ? '42' : null,
                'name' => 'Hotel ' . $id,
                'country_code' => 'GR',
                'destination_id' => '5',
                'destination_name' => 'Halkidiki',
                'created_at' => '2024-03-01 10:00:00',
            ], array_intersect(array_map('strval', $ids), ['H1', 'H2', 'H3'])));
        };
        DbStub::$getFields = static fn (): array => ['H1', 'H2', 'H3'];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testEveryOfferCountsImmediateWhenItsConfirmationSaysSo(): void
    {
        $tally = new OfferTally();
        SphinxAvailabilityRecorder::addOffers($tally, [
            ['hotel_id' => 'H1', 'confirmation' => 'immediate'],
            ['hotel_id' => 'H2', 'confirmation' => 'on_request'],
            ['id' => 'H3'],
            ['confirmation' => 'immediate'],
        ], '2026-11-07');

        $offers = $tally->offers();
        self::assertSame(['H1', 'H2', 'H3'], array_map('strval', array_keys($offers)), 'the id key falls back to id; no hotel, no offer');
        self::assertTrue($offers['H1']['immediate']);
        self::assertFalse($offers['H2']['immediate'], 'on request counts as availability, not as immediate');
    }

    public function testADailySyncMarksItsHotelsSeenAndNobodyMissing(): void
    {
        $result = (new SphinxAvailabilityRecorder())->hotelsListed('GR', [5], ['H1'], false, '2026-10-24 02:00:00');

        self::assertSame(['complete' => false, 'seen' => 1, 'missing' => 0], $result);
        self::assertCount(1, $this->sql);
        self::assertStringContainsString("('sphinx', 'H1', 42, 'GR|Halkidiki (5)', 'Hotel H1', '2024-03-01', '2026-10-24 02:00:00', 0)", $this->sql[0]);
    }

    public function testAWholeFullListMarksTheDestinationsOtherHotelsMissing(): void
    {
        $result = (new SphinxAvailabilityRecorder())->hotelsListed('GR', [5], ['H1', 'H2'], true, '2026-10-24 02:00:00');

        self::assertSame(['complete' => true, 'seen' => 2, 'missing' => 1], $result);
        self::assertStringContainsString("hotel_id IN ('H3')", $this->sql[1]);
    }

    public function testAShortFullListMarksNobodyMissing(): void
    {
        // All three were listed last time; this list has one.
        DbStub::$getField = static fn (string $query): string|int => str_contains($query, 'COUNT(*)') ? 3 : 'hotel_name';

        $result = (new SphinxAvailabilityRecorder())->hotelsListed('GR', [5], ['H1'], true, '2026-10-24 02:00:00');

        self::assertSame(['complete' => false, 'seen' => 1, 'missing' => 0], $result);
        self::assertCount(1, $this->sql);
    }

    public function testADestinationThatAnsweredChecksTheHotelsTheGateDecides(): void
    {
        $offers = new OfferTally();
        SphinxAvailabilityRecorder::addOffers($offers, [['hotel_id' => 'H1', 'confirmation' => 'immediate'], ['hotel_id' => 'H3']], '2026-11-07');

        $result = (new SphinxAvailabilityRecorder())->gateChecked([5 => ['H1' => true, 'H2' => true]], [5 => true], [5 => $offers], '2026-10-24 02:30:00');

        self::assertSame(['recorded' => true, 'destinations' => 1, 'hotels' => 2], $result);
        self::assertCount(2, $this->sql);
        self::assertStringContainsString('offer_checked_at', $this->sql[0]);
        self::assertStringContainsString("('sphinx', 'H2', 0, 'GR|Halkidiki (5)', 'Hotel H2', '2026-10-24 02:30:00', NULL, NULL, NULL, NULL, NULL)", $this->sql[0], 'decided, no offer: checked');
        self::assertStringNotContainsString("'H3'", $this->sql[0], 'not one the gate decides');
        self::assertStringNotContainsString('offer_checked_at', $this->sql[1]);
        self::assertStringContainsString("('sphinx', 'H3',", $this->sql[1], 'its offer still counts');
    }

    public function testADestinationThatDidNotAnswerInFullRecordsOnlyItsOffers(): void
    {
        $offers = new OfferTally();
        SphinxAvailabilityRecorder::addOffers($offers, [['hotel_id' => 'H1']], '2026-11-07');

        (new SphinxAvailabilityRecorder())->gateChecked([5 => ['H1' => true, 'H2' => true]], [], [5 => $offers], '2026-10-24 02:30:00');

        self::assertCount(1, $this->sql);
        self::assertStringNotContainsString('offer_checked_at', $this->sql[0]);
        self::assertStringNotContainsString("'H2'", $this->sql[0]);
    }

    public function testACountryWithNoOfferAnywhereIsNoAnswer(): void
    {
        $result = (new SphinxAvailabilityRecorder())->gateChecked([5 => ['H1' => true]], [5 => true], [5 => new OfferTally()], '2026-10-24 02:30:00');

        self::assertFalse($result['recorded']);
        self::assertSame([], $this->sql, 'an account with no supplier connected must not read as every hotel checked');
    }

    public function testTheGateHandsOverEveryOfferItsSearchesFound(): void
    {
        $http = $this->createMock(SphinxHttpClient::class);
        $http->method('isCircuitOpen')->willReturn(false);
        $api = $this->createMock(SphinxApi::class);
        $api->method('getHttpClient')->willReturn($http);
        // Every window: H1 on request inline, H3 immediate once polled.
        $api->method('searchHotels')->willReturn(['results' => [['hotel_id' => 'H1', 'confirmation' => 'on_request']], 'cursor' => 'c1']);
        $api->method('getHotelResults')->willReturn(['results' => [['hotel_id' => 'H3', 'confirmation' => 'immediate']], 'cursor' => null]);
        $skip = $this->createMock(HotelSkipRepository::class);
        $skip->method('findAvailabilityGateCandidates')->willReturn([
            ['hotel_id' => 'H1', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => ''],
            ['hotel_id' => 'H2', 'destination_id' => 5, 'product_id' => 0, 'product_skip_reason' => ''],
        ]);
        $skip->method('findGateStates')->willReturn([]);

        (new HotelAvailabilityGate($api, $skip, 0, 0))->apply('GR', [5], [], static function (): void {
        });

        $checked = $this->sql[0];
        self::assertStringContainsString('offer_checked_at', $checked);
        self::assertStringContainsString("'H1', 42, 'GR|Halkidiki (5)', 'Hotel H1',", $checked, 'on request is an offer here, though the gate counts immediate only');
        self::assertStringContainsString("'H2', 0, 'GR|Halkidiki (5)', 'Hotel H2',", $checked);
        self::assertStringContainsString("('sphinx', 'H3',", $this->sql[1], 'a polled offer of a hotel the gate does not decide');
    }

    public function testASearchRecordsTheOffersOfListedHotelsOnly(): void
    {
        (new SphinxAvailabilityRecorder())->searchFound(
            [['hotel_id' => 'H2', 'confirmation' => 'immediate'], ['hotel_id' => 'H9', 'confirmation' => 'immediate']],
            '2026-12-20',
            '2026-10-24 18:00:00',
        );

        self::assertCount(1, $this->sql);
        self::assertStringContainsString("('sphinx', 'H2', 0, 'GR|Halkidiki (5)', 'Hotel H2', '2026-10-24 18:00:00', '2026-12-20', '2026-10-24 18:00:00'", $this->sql[0]);
        self::assertStringNotContainsString("'H9'", $this->sql[0], 'not a hotel this store lists');

        $this->sql = [];
        (new SphinxAvailabilityRecorder())->searchFound([['hotel_id' => 'H2']], '', '2026-10-24 18:00:00');
        self::assertSame([], $this->sql, 'an expired search has no stay date: nothing recorded');
    }

    public function testTheDestinationIsReadableAndUnique(): void
    {
        self::assertSame('GR|Halkidiki (5)', SphinxAvailabilityRecorder::destinationKey('gr', 'Halkidiki', 5));
        self::assertSame('GR|5', SphinxAvailabilityRecorder::destinationKey('GR', '', 5));
        self::assertSame('GR|Greece', SphinxAvailabilityRecorder::destinationKey('GR', 'Greece', 0));
        $long = SphinxAvailabilityRecorder::destinationKey('GR', str_repeat('Α', 300), 12345);
        self::assertSame(128, mb_strlen($long));
        self::assertStringEndsWith(' (12345)', $long);
    }

    public function testSphinxDeclaresWhatItCanTell(): void
    {
        $source = new SphinxAvailabilitySource();
        $caps = $source->capabilities();

        self::assertSame('sphinx', $source->provider());
        self::assertSame(HotelListCoverage::FullSyncOnly, $caps->hotelList);
        self::assertFalse($caps->publishedPrices);
        self::assertTrue($caps->liveCheckSlow);
        self::assertSame(['hotels', 'full'], $source->recordingModes());

        $init = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');
        self::assertStringContainsString("setAvailabilitySource(\n        'sphinx',\n        new \\Tygh\\Addons\\SphinxHolidays\\Services\\SphinxAvailabilitySource(),", $init);
    }

    public function testTheGiataIdReachesNewAndInstalledStores(): void
    {
        $root = dirname(__DIR__, 3);
        $xml = (string) file_get_contents($root . '/addon.xml');
        $migrator = (string) file_get_contents($root . '/src/Install/SchemaMigrator.php');

        self::assertStringContainsString("`giata_id`          INT UNSIGNED DEFAULT NULL COMMENT 'GIATA id (external_ids): the same hotel at another supplier',", $xml);
        self::assertStringContainsString('KEY `idx_giata` (`giata_id`)', $xml);
        self::assertStringContainsString("'giata_id' => \"ADD COLUMN `giata_id` INT UNSIGNED DEFAULT NULL COMMENT 'GIATA id (external_ids): the same hotel at another supplier' AFTER `name`\"", $migrator);
        self::assertStringContainsString("'idx_giata' => 'ADD KEY `idx_giata` (`giata_id`)'", $migrator);
    }
}
