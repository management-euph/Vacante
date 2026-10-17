<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\PriceCheckFlags;

/**
 * A room_price check may only write what an answer proved. "No prices" for a
 * hotel the API never answered about (a failed call, an open breaker, a resort
 * that errored) made whole countries read as unsellable.
 */
#[CoversClass(PriceCheckFlags::class)]
final class PriceCheckFlagsTest extends TestCase
{
    public function testOnlyAReplyDecides(): void
    {
        self::assertTrue(PriceCheckFlags::hasPrices(new \SimpleXMLElement('<room_price><Room><Price>120</Price></Room></room_price>')));
        self::assertTrue(PriceCheckFlags::hasPrices(new \SimpleXMLElement('<r><hotel><Room><Price>1</Price></Room></hotel></r>')), 'nested shape too');
        self::assertFalse(PriceCheckFlags::hasPrices(new \SimpleXMLElement('<room_price></room_price>')));
        self::assertNull(PriceCheckFlags::hasPrices(false));
        self::assertNull(PriceCheckFlags::hasPrices(null));
    }

    public function testAResortCheckLeavesHotelsOfResortsThatDidNotAnswerAlone(): void
    {
        $hotels = [
            '101' => ['city' => 'SUNNY BEACH'],
            '102' => ['city' => 'Sunny Beach '],
            '201' => ['city' => 'GOLDEN SANDS'],
            '301' => ['city' => 'NESSEBAR'],
            '401' => ['city' => ''],
        ];
        // 101 and 301 were found in some answer; Sunny Beach and Golden Sands
        // answered; Nessebar's call failed; 401 has no city.
        $split = PriceCheckFlags::splitByResort($hotels, ['101' => 'SUNNY BEACH', '301' => 'NESSEBAR'], ['sunny beach', 'GOLDEN SANDS']);

        self::assertSame(['101', '301'], $split['with'], 'found in an answer: prices, whatever its own resort did');
        self::assertSame(['102', '201'], $split['without'], 'its resort answered without it: no prices (city trimmed, any case)');
        self::assertSame(['401'], $split['unanswered'], 'nothing is known about it: its flag stays');
    }

    public function testAResortWhoseCallFailedChangesNothing(): void
    {
        $split = PriceCheckFlags::splitByResort(['7' => ['city' => 'NESSEBAR'], '8' => ['city' => 'NESSEBAR']], [], []);

        self::assertSame([], $split['with']);
        self::assertSame([], $split['without']);
        self::assertSame(['7', '8'], $split['unanswered']);
    }

    public function testTheAdminChecksDefaultToTheNextSeventhOfJuly(): void
    {
        self::assertSame('2027-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2026-10-16')), 'October: next summer, not the July just gone');
        self::assertSame('2027-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2026-07-07')));
        self::assertSame('2026-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2026-07-06')));
        self::assertSame('2027-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2027-01-15')));
    }
}
