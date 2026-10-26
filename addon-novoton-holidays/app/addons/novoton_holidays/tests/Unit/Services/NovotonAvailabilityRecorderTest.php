<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilityRecorder;
use Tygh\Addons\NovotonHolidays\Services\NovotonAvailabilitySource;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;

/**
 * What Novoton's existing jobs write into Travel Core's availability records:
 * the hotel list (seen / missing, only from a complete answer), the calendar's
 * price window and the offers customers' searches find.
 */
final class NovotonAvailabilityRecorderTest extends TestCase
{
    /** @var list<string> statements run, placeholders filled */
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
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** Novoton has listed three hotels in Bulgaria; $previous of them were in the last complete list. */
    private function bulgaria(int $previous): void
    {
        DbStub::$getArray = static fn (): array => [
            ['hotel_id' => '476', 'product_id' => '1203', 'hotel_name' => 'Sol Nessebar', 'city' => 'Sunny Beach', 'country' => 'BULGARIA', 'created_at' => '2023-05-01 10:00:00'],
            ['hotel_id' => '477', 'product_id' => null, 'hotel_name' => 'Kuban', 'city' => 'Sunny Beach', 'country' => 'BULGARIA', 'created_at' => '2024-02-02 10:00:00'],
            ['hotel_id' => '480', 'product_id' => '1300', 'city' => 'Albena', 'country' => 'BULGARIA', 'created_at' => '2023-05-01 10:00:00'],
        ];
        DbStub::$getField = static fn (): int => $previous;
    }

    /** @return list<array<string, mixed>> */
    private static function answer(string ...$ids): array
    {
        return array_map(static fn (string $id): array => ['hotel_id' => $id, 'country' => 'BULGARIA'], $ids);
    }

    public function testACompleteListMarksItsHotelsSeenAndTheOthersMissing(): void
    {
        $this->bulgaria(2);

        $result = (new NovotonAvailabilityRecorder())->hotelList('BULGARIA', self::answer('476', '477'), '2026-10-26 02:00:00');

        self::assertSame(['complete' => true, 'seen' => 2, 'missing' => 1], $result);
        self::assertCount(2, $this->sql);
        self::assertStringContainsString("('novoton', '476', 1203, 'BULGARIA|Sunny Beach', 'Sol Nessebar', '2023-05-01', '2026-10-26 02:00:00', 0)", $this->sql[0]);
        self::assertStringContainsString("('novoton', '477', 0, 'BULGARIA|Sunny Beach', 'Kuban', '2024-02-02', '2026-10-26 02:00:00', 0)", $this->sql[0]);
        self::assertStringContainsString("hotel_id IN ('480')", $this->sql[1]);
    }

    public function testAShortListCountsNobodyMissing(): void
    {
        $this->bulgaria(10);

        $result = (new NovotonAvailabilityRecorder())->hotelList('BULGARIA', self::answer('476'), '2026-10-26 02:00:00');

        self::assertFalse($result['complete']);
        self::assertSame(0, $result['missing']);
        self::assertCount(1, $this->sql, 'the hotels in it were still seen');
        self::assertStringNotContainsString('listed_missing_runs + 1', implode("\n", $this->sql));
    }

    public function testAFailedRecordNeverBreaksTheJob(): void
    {
        DbStub::$getArray = static function (): array {
            throw new \RuntimeException('Table is marked as crashed');
        };

        $result = (new NovotonAvailabilityRecorder())->hotelList('BULGARIA', self::answer('476'), '2026-10-26 02:00:00');

        self::assertSame(['complete' => false, 'seen' => 0, 'missing' => 0], $result);
    }

    public function testTheCalendarsFirstAndLastDateAreThePriceWindow(): void
    {
        (new NovotonAvailabilityRecorder())->calendarComputed('476', ['2027-07-01' => 80.0, '2027-05-15' => 60.0, '2027-09-30' => 70.0]);
        (new NovotonAvailabilityRecorder())->calendarComputed('477', []);

        self::assertStringContainsString("VALUES ('novoton', '476', '2027-05-15', '2027-09-30', '2027-09-30')", $this->sql[0]);
        self::assertStringContainsString("VALUES ('novoton', '477', NULL, NULL, NULL)", $this->sql[1]);
    }

    public function testASearchWithOffersRecordsThemAndSaysWhetherAnyCouldBeBookedAtOnce(): void
    {
        $recorder = new NovotonAvailabilityRecorder();
        $recorder->searchFound('476', '2027-07-10', [['is_on_request' => true], ['is_on_request' => '0']], '2026-10-23 10:00:00');
        $recorder->searchFound('477', '2027-07-10', [['is_on_request' => true]], '2026-10-23 10:00:00');

        self::assertStringContainsString("'2027-07-10', '2026-10-23 10:00:00', JSON_OBJECT", $this->sql[0]);
        self::assertStringContainsString("'2027-07-10', NULL, JSON_OBJECT", $this->sql[1]);
    }

    public function testAnEmptySearchProvesNothing(): void
    {
        (new NovotonAvailabilityRecorder())->searchFound('476', '2027-07-10', [], '2026-10-23 10:00:00');

        self::assertSame([], $this->sql);
    }

    public function testAHotelIsKeyedByItsResortWithinItsCountry(): void
    {
        self::assertSame('BULGARIA|Sunny Beach', NovotonAvailabilityRecorder::destinationKey('Bulgaria', ' Sunny Beach '));
        self::assertSame('', NovotonAvailabilityRecorder::destinationKey('BULGARIA', ''));
        self::assertSame(128, mb_strlen(NovotonAvailabilityRecorder::destinationKey('BULGARIA', str_repeat('x', 200))));

        $identity = NovotonAvailabilityRecorder::identity(['hotel_id' => '9', 'product_id' => null, 'created_at' => '0000-00-00 00:00:00']);
        self::assertSame(0, $identity['product_id']);
        self::assertNull($identity['known_since']);
    }

    /** Numeric hotel ids come back as int array keys: the last stays must still reach their hotels. */
    public function testTheBackfillReadsPastSeasonsAndConfirmedStays(): void
    {
        $past = ['seasons' => ['season' => [['Season' => '1', 'FromDate' => '2025-06-01', 'ToDate' => '2025-09-30']]],
            'season_price' => [['IdRoom' => 'DBL', 'IdAge' => '1', 'IdAcc' => 'RB', 'RoomPrice' => 'Yes', 'Price1' => '100']]];
        DbStub::$getArray = static fn (string $query): array => str_contains($query, 'novoton_hotel_packages')
            ? [['hotel_id' => '100', 'priceinfo_data' => (string) json_encode($past)], ['hotel_id' => '200', 'priceinfo_data' => null]]
            : [
                ['hotel_id' => '100', 'product_id' => '1203', 'hotel_name' => 'Past Hotel', 'city' => 'Sunny Beach', 'country' => 'BULGARIA', 'created_at' => '2023-05-01 10:00:00', 'hotel_list_synced_at' => '2026-10-20 02:00:00'],
                ['hotel_id' => '200', 'product_id' => null, 'city' => 'Albena', 'country' => 'BULGARIA', 'created_at' => '2024-02-02 10:00:00', 'hotel_list_synced_at' => null],
            ];
        DbStub::$getHashSingleArray = static fn (): array => [100 => '2025-08-01'];

        $step = (new NovotonAvailabilityRecorder())->backfill(200, '2026-10-23 03:30:00');

        self::assertSame(['hotels' => 2, 'done' => false], $step);
        self::assertStringContainsString(
            "('novoton', '100', 1203, 'BULGARIA|Sunny Beach', 'Past Hotel', '2023-05-01', '2026-10-20 02:00:00', '2025-09-30', '2025-08-01', '2026-10-23 03:30:00')",
            $this->sql[0],
        );
        self::assertStringContainsString("('novoton', '200', 0, 'BULGARIA|Albena', '', '2024-02-02', NULL, NULL, NULL, '2026-10-23 03:30:00')", $this->sql[0]);
    }

    /** The weekly check: a whole answer checked every hotel, part of one only counts its offers. */
    public function testTheWeeklyCheckRecordsAWholeAnswerAsACheckAndPartOfOneAsOffersSeen(): void
    {
        $hotels = [
            ['hotel_id' => '476', 'product_id' => '1203', 'hotel_name' => 'Sol Nessebar', 'city' => 'Sunny Beach', 'country' => 'BULGARIA', 'created_at' => '2023-05-01 10:00:00'],
            ['hotel_id' => '477', 'product_id' => null, 'hotel_name' => 'Kuban', 'city' => 'Sunny Beach', 'country' => 'BULGARIA'],
        ];
        $offers = ['476' => ['stay_date' => '2027-07-15', 'next_date' => '2026-11-09', 'immediate' => false, 'months' => ['2026-11', '2027-07']]];
        $recorder = new NovotonAvailabilityRecorder();

        $recorder->checkAnswered($hotels, $offers, true, '2026-10-26 04:00:00');
        $recorder->checkAnswered($hotels, $offers, false, '2026-10-26 04:00:00');
        $recorder->checkAnswered([], $offers, true, '2026-10-26 04:00:00');

        self::assertCount(2, $this->sql);
        self::assertStringContainsString("('novoton', '476', 1203, 'BULGARIA|Sunny Beach', 'Sol Nessebar', '2026-10-26 04:00:00', '2026-11-09', '2026-10-26 04:00:00', '2027-07-15', NULL,", $this->sql[0]);
        self::assertStringContainsString("('novoton', '477', 0, 'BULGARIA|Sunny Beach', 'Kuban', '2026-10-26 04:00:00', NULL, NULL, NULL, NULL, NULL)", $this->sql[0]);
        self::assertStringNotContainsString('offer_checked_at', $this->sql[1]);
        self::assertStringNotContainsString("'477'", $this->sql[1], 'no offer, nothing to record');
    }

    public function testHotelsWhosePricesStartLaterAreReadFromTheRecords(): void
    {
        $query = '';
        DbStub::$getArray = static function (string $sql, mixed ...$params) use (&$query): array {
            $query = db_quote($sql, ...$params);

            return [['hotel_id' => '476', 'contract_from' => '2026-12-20'], ['hotel_id' => '477', 'contract_from' => null]];
        };

        $starts = (new NovotonAvailabilityRecorder())->awaitingFirstOffer(['476', '477', '476'], '2026-10-26');

        self::assertSame([476 => '2026-12-20'], $starts);
        self::assertStringContainsString("provider = 'novoton' AND hotel_id IN ('476', '477') AND contract_from > '2026-10-26' AND offer_next_date IS NULL", $query);
        self::assertSame([], (new NovotonAvailabilityRecorder())->awaitingFirstOffer([], '2026-10-26'));
    }

    public function testNovotonDeclaresWhatItCanTell(): void
    {
        $source = new NovotonAvailabilitySource();

        self::assertSame('novoton', $source->provider());
        self::assertSame(HotelListCoverage::PerCountry, $source->capabilities()->hotelList);
        self::assertTrue($source->capabilities()->pastPrices);
        self::assertContains('hotel_list', $source->recordingModes());
    }
}
