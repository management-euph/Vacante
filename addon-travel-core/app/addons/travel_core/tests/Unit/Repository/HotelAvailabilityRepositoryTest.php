<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\ListCompleteness;
use Tygh\Addons\TravelCore\Services\OfferTally;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * How suppliers write availability records. The statements were also run on
 * MariaDB 10.11 when written; these tests pin the rules they carry.
 */
final class HotelAvailabilityRepositoryTest extends TestCase
{
    /** @var list<string> the statements run, placeholders filled */
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

    public function testAListAnswerMarksItsHotelsSeenAndCountsTheOthersMissing(): void
    {
        (new HotelAvailabilityRepository())->recordList('novoton', [
            ['hotel_id' => '476', 'product_id' => 1203, 'destination_key' => 'BULGARIA|Sunny Beach', 'hotel_name' => 'Sol Nessebar', 'known_since' => '2023-05-01'],
            ['hotel_id' => '477', 'product_id' => 0, 'destination_key' => 'BULGARIA|Sunny Beach', 'hotel_name' => 'Kuban', 'known_since' => null],
        ], ['480'], '2026-10-26 02:00:00');

        self::assertCount(2, $this->sql);
        self::assertStringContainsString("('novoton', '476', 1203, 'BULGARIA|Sunny Beach', 'Sol Nessebar', '2023-05-01', '2026-10-26 02:00:00', 0)", $this->sql[0]);
        self::assertStringContainsString("('novoton', '477', 0, 'BULGARIA|Sunny Beach', 'Kuban', NULL, '2026-10-26 02:00:00', 0)", $this->sql[0]);
        self::assertStringContainsString('listed_missing_runs = 0', $this->sql[0]);
        self::assertStringContainsString('known_since = COALESCE(known_since, VALUES(known_since))', $this->sql[0], 'the first listing date is kept');
        self::assertStringContainsString('SET listed_missing_runs = listed_missing_runs + 1', $this->sql[1]);
        self::assertStringContainsString("hotel_id IN ('480')", $this->sql[1]);
    }

    public function testAnEmptyAnswerWritesNothing(): void
    {
        (new HotelAvailabilityRepository())->recordList('novoton', [], [], '2026-10-26 02:00:00');

        self::assertSame([], $this->sql);
    }

    public function testThePriceWindowCanEmptyButTheLatestPricedDateOnlyMovesForward(): void
    {
        (new HotelAvailabilityRepository())->recordPriceWindow('novoton', '476', null, null);

        self::assertStringContainsString("VALUES ('novoton', '476', NULL, NULL, NULL)", $this->sql[0]);
        self::assertStringContainsString(
            'contract_last_date = GREATEST(COALESCE(contract_last_date, VALUES(contract_last_date)), COALESCE(VALUES(contract_last_date), contract_last_date))',
            $this->sql[0],
        );
    }

    public function testAnOfferSeenIsWrittenAtMostOnceAnHourAndAfterTheOtherColumns(): void
    {
        (new HotelAvailabilityRepository())->recordOfferSeen('novoton', '476', '2027-07-10', true, '2026-10-23 10:00:00');

        $sql = $this->sql[0];
        self::assertStringContainsString("VALUES ('novoton', '476', '2026-10-23 10:00:00', '2027-07-10', '2026-10-23 10:00:00', JSON_OBJECT('2027-07', '2026-10-23'))", $sql);
        self::assertSame(4, substr_count($sql, 'offer_seen_at < VALUES(offer_seen_at) - INTERVAL 1 HOUR'));
        self::assertGreaterThan(strpos($sql, 'offer_months = IF('), strpos($sql, 'offer_seen_at = IF('), 'offer_seen_at is assigned last');
    }

    public function testAnOnRequestOfferLeavesTheImmediateDateAlone(): void
    {
        (new HotelAvailabilityRepository())->recordOfferSeen('novoton', '476', '2027-07-10', false, '2026-10-23 10:00:00');

        self::assertStringContainsString("'2027-07-10', NULL, JSON_OBJECT", $this->sql[0]);
        self::assertStringContainsString('COALESCE(VALUES(immediate_seen_at), immediate_seen_at)', $this->sql[0]);
    }

    public function testTheBackfillNeverMovesAValueBack(): void
    {
        (new HotelAvailabilityRepository())->recordBackfill('novoton', [[
            'hotel_id' => '476', 'product_id' => 0, 'destination_key' => '', 'hotel_name' => '', 'known_since' => '2023-05-01',
            'listed_last_seen_at' => null, 'contract_last_date' => '2025-09-30', 'last_booking_check_in' => '2025-08-01',
        ]], '2026-10-23 03:30:00');

        $sql = $this->sql[0];
        self::assertStringContainsString("('novoton', '476', 0, '', '', '2023-05-01', NULL, '2025-09-30', '2025-08-01', '2026-10-23 03:30:00')", $sql);
        self::assertStringContainsString("hotel_name = IF(VALUES(hotel_name) <> '', VALUES(hotel_name), hotel_name)", $sql, 'an empty name never erases one');
        self::assertStringContainsString('listed_last_seen_at = COALESCE(listed_last_seen_at, VALUES(listed_last_seen_at))', $sql);
        self::assertStringContainsString('last_booking_check_in = GREATEST(', $sql);
        self::assertStringContainsString('product_id = IF(VALUES(product_id) > 0, VALUES(product_id), product_id)', $sql);
    }

    /** @return list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}> */
    private static function mamaia(): array
    {
        return [
            ['hotel_id' => 'RO1001', 'product_id' => 15, 'destination_key' => 'RO|Mamaia (RO0363)', 'hotel_name' => 'Hotel Rex'],
            ['hotel_id' => 'RO1002', 'product_id' => 0, 'destination_key' => 'RO|Mamaia (RO0363)', 'hotel_name' => 'Vila Doina'],
        ];
    }

    public function testAWholeAnswerChecksEveryHotelAndRecordsTheOffers(): void
    {
        $offers = new OfferTally();
        $offers->add('RO1001', '2027-07-15', false);
        $offers->add('RO1001', '2026-11-06', true);

        (new HotelAvailabilityRepository())->recordCheck('eurosite', self::mamaia(), $offers->offers(), '2026-10-24 04:30:00');

        self::assertCount(1, $this->sql);
        self::assertStringContainsString(
            "('eurosite', 'RO1001', 15, 'RO|Mamaia (RO0363)', 'Hotel Rex', '2026-10-24 04:30:00', '2026-11-06', '2026-10-24 04:30:00', '2027-07-15', '2026-10-24 04:30:00', '{\"2026-11\":\"2026-10-24\",\"2027-07\":\"2026-10-24\"}')",
            $this->sql[0],
            'the latest stay, the earliest as the next date, any immediate offer, every month',
        );
        self::assertStringContainsString(
            "('eurosite', 'RO1002', 0, 'RO|Mamaia (RO0363)', 'Vila Doina', '2026-10-24 04:30:00', NULL, NULL, NULL, NULL, NULL)",
            $this->sql[0],
            'checked, no offer',
        );
        self::assertStringContainsString('offer_seen_at = COALESCE(VALUES(offer_seen_at), offer_seen_at)', $this->sql[0], 'no offer now keeps the last one seen');
        self::assertStringContainsString('JSON_MERGE_PATCH(COALESCE(offer_months', $this->sql[0], 'months add up');
        self::assertStringEndsWith('offer_seen_at = COALESCE(VALUES(offer_seen_at), offer_seen_at)', trim($this->sql[0]), 'offer_seen_at last: the IF() before it reads the old value');
    }

    public function testOffersSeenWritePositivesOnlyAndAtMostOnceAnHour(): void
    {
        $offers = new OfferTally();
        $offers->add('RO1002', '2026-12-20', false);

        (new HotelAvailabilityRepository())->recordOffersSeen('eurosite', self::mamaia(), $offers->offers(), '2026-10-24 18:00:00');

        self::assertCount(1, $this->sql);
        self::assertStringNotContainsString("'RO1001'", $this->sql[0], 'a hotel without an offer is left alone');
        self::assertStringContainsString("('eurosite', 'RO1002', 0, 'RO|Mamaia (RO0363)', 'Vila Doina', '2026-10-24 18:00:00', '2026-12-20', NULL, '{\"2026-12\":\"2026-10-24\"}')", $this->sql[0]);
        self::assertStringContainsString('offer_seen_at = IF(offer_seen_at IS NULL OR offer_seen_at < VALUES(offer_seen_at) - INTERVAL 1 HOUR, VALUES(offer_seen_at), offer_seen_at)', $this->sql[0]);
        self::assertStringContainsString('hotel_name = IF(hotel_name = \'\', VALUES(hotel_name), hotel_name)', $this->sql[0], 'fills only what is missing');
        self::assertStringNotContainsString('offer_checked_at', $this->sql[0], 'a search is no check');

        $this->sql = [];
        (new HotelAvailabilityRepository())->recordOffersSeen('eurosite', self::mamaia(), [], '2026-10-24 18:00:00');
        self::assertSame([], $this->sql, 'no offers, no statement');
    }

    public function testATallyKeepsTheStaysOfEachHotel(): void
    {
        $tally = new OfferTally();
        $tally->add('476', '2027-07-10', false);
        $tally->add('476', '2027-06-20', false);
        $tally->add('', '2027-06-20', true);
        $tally->add('477', 'soon', true);

        self::assertSame(1, $tally->count(), 'no hotel or no date: left out');
        self::assertTrue($tally->has('476'));
        self::assertSame(
            [476 => ['stay_date' => '2027-07-10', 'next_date' => '2027-06-20', 'immediate' => false, 'months' => ['2027-06', '2027-07']]],
            $tally->offers(),
            'a numeric hotel id comes back as an int key',
        );
    }

    public function testAListAnswerCountsOnlyWhenNotEmptyAndNearlyAsLongAsTheLast(): void
    {
        self::assertTrue(ListCompleteness::complete(950, 1000));
        self::assertTrue(ListCompleteness::complete(900, 1000));
        self::assertFalse(ListCompleteness::complete(899, 1000), 'a short list may be an outage');
        self::assertFalse(ListCompleteness::complete(0, 0), 'an empty answer is never complete');
        self::assertTrue(ListCompleteness::complete(12, 0), 'the first answer');
        self::assertTrue(ListCompleteness::complete(2, 3), 'a city of three hotels can lose one');
        self::assertFalse(ListCompleteness::complete(1, 3), 'but not two at once');
        self::assertFalse(ListCompleteness::complete(0, 1), 'nor its only hotel: an empty answer proves nothing');
    }

    public function testAGroupIsMeasuredByWhatItListedThisPastMonth(): void
    {
        $asked = [];
        DbStub::$getField = static function (string $query, mixed ...$params) use (&$asked): int {
            $asked[] = db_quote($query, ...$params);

            return 4;
        };

        $count = (new HotelAvailabilityRepository())->listedCount('eurosite', ['RO1001', 'RO1002'], '2026-10-25 03:00:00');

        self::assertSame(4, $count);
        $query = (string) end($asked);
        self::assertStringContainsString("hotel_id IN ('RO1001', 'RO1002')", $query);
        self::assertStringContainsString('listed_missing_runs = 0', $query, 'a hotel a complete list missed is out');
        self::assertStringContainsString("listed_last_seen_at >= '2026-09-25 03:00:00'", $query, 'so is one no list had for 30 days: a group that really shrank is measured by its new size');
    }
}
