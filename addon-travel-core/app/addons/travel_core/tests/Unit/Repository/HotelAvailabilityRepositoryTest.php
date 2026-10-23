<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\ListCompleteness;
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
            if (!str_starts_with(ltrim($query), 'CREATE TABLE')) {
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
            ['hotel_id' => '476', 'product_id' => 1203, 'destination_key' => 'BULGARIA|Sunny Beach', 'known_since' => '2023-05-01'],
            ['hotel_id' => '477', 'product_id' => 0, 'destination_key' => 'BULGARIA|Sunny Beach', 'known_since' => null],
        ], ['480'], '2026-10-26 02:00:00');

        self::assertCount(2, $this->sql);
        self::assertStringContainsString("('novoton', '476', 1203, 'BULGARIA|Sunny Beach', '2023-05-01', '2026-10-26 02:00:00', 0)", $this->sql[0]);
        self::assertStringContainsString("('novoton', '477', 0, 'BULGARIA|Sunny Beach', NULL, '2026-10-26 02:00:00', 0)", $this->sql[0]);
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
            'hotel_id' => '476', 'product_id' => 0, 'destination_key' => '', 'known_since' => '2023-05-01',
            'listed_last_seen_at' => null, 'contract_last_date' => '2025-09-30', 'last_booking_check_in' => '2025-08-01',
        ]], '2026-10-23 03:30:00');

        $sql = $this->sql[0];
        self::assertStringContainsString("('novoton', '476', 0, '', '2023-05-01', NULL, '2025-09-30', '2025-08-01', '2026-10-23 03:30:00')", $sql);
        self::assertStringContainsString('listed_last_seen_at = COALESCE(listed_last_seen_at, VALUES(listed_last_seen_at))', $sql);
        self::assertStringContainsString('last_booking_check_in = GREATEST(', $sql);
        self::assertStringContainsString('product_id = IF(VALUES(product_id) > 0, VALUES(product_id), product_id)', $sql);
    }

    public function testAListAnswerCountsOnlyWhenNotEmptyAndNearlyAsLongAsTheLast(): void
    {
        self::assertTrue(ListCompleteness::complete(950, 1000));
        self::assertTrue(ListCompleteness::complete(900, 1000));
        self::assertFalse(ListCompleteness::complete(899, 1000), 'a short list may be an outage');
        self::assertFalse(ListCompleteness::complete(0, 0), 'an empty answer is never complete');
        self::assertTrue(ListCompleteness::complete(12, 0), 'the first answer');
    }
}
