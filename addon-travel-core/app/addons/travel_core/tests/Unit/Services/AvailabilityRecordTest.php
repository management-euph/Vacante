<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Availability\DestinationPeers;
use Tygh\Addons\TravelCore\Dto\Availability\HotelAvailabilityRecord;
use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityThresholds;

/**
 * Reading a ?:travel_hotel_availability row and the admin's settings into
 * what the availability rules take.
 */
final class AvailabilityRecordTest extends TestCase
{
    public function testARowIsReadWithItsDatesAndState(): void
    {
        $record = HotelAvailabilityRecord::fromRow([
            'provider' => 'novoton',
            'hotel_id' => '476',
            'product_id' => '1203',
            'destination_key' => 'SUNNY BEACH',
            'known_since' => '2023-05-01',
            'created_at' => '2026-10-25 04:01:02',
            'backfilled_at' => '2026-10-25 04:01:09',
            'listed_missing_runs' => '2',
            'contract_to' => '2027-09-30',
            'offer_seen_at' => '2026-08-20 04:10:00',
            'offer_stay_date' => '2026-08-25',
            'immediate_seen_at' => null,
            'offer_checked_at' => '0000-00-00 00:00:00',
            'override' => 'keep_visible',
            'state' => 'between_seasons',
            'state_since' => '2026-10-26 05:00:00',
        ]);

        self::assertSame('476', $record->hotelId);
        self::assertSame(1203, $record->productId);
        self::assertSame('SUNNY BEACH', $record->destinationKey);
        self::assertSame('2023-05-01', $record->knownSince?->format('Y-m-d'));
        self::assertSame('2026-10-25 04:01:02', $record->recordedSince?->format('Y-m-d H:i:s'));
        self::assertTrue($record->backfilled);
        self::assertSame(2, $record->listedMissingRuns);
        self::assertSame('2026-08-20 04:10:00', $record->offerSeenAt?->format('Y-m-d H:i:s'));
        self::assertNull($record->immediateSeenAt);
        self::assertNull($record->offerCheckedAt, 'a zero date is never');
        self::assertSame('keep_visible', $record->override);
        self::assertSame(HotelAvailabilityState::BetweenSeasons, $record->state);
    }

    public function testAnUnknownStateOrJunkDateReadsAsNothing(): void
    {
        $record = HotelAvailabilityRecord::fromRow(['provider' => 'sphinx', 'hotel_id' => 'x', 'state' => 'gone', 'contract_to' => 'soon']);

        self::assertNull($record->state);
        self::assertNull($record->contractTo);
        self::assertFalse($record->backfilled);
    }

    public function testTheAdminsSettingsAreReadWithSaneFallbacks(): void
    {
        $limits = AvailabilityThresholds::fromSettings(['availability_dormant_months' => '18', 'availability_on_request' => 'N']);

        self::assertSame(18, $limits->dormantMonths);
        self::assertFalse($limits->onRequestCounts);
        self::assertSame(8, $limits->freshDays);

        self::assertSame(12, AvailabilityThresholds::fromSettings(['availability_dormant_months' => '0'])->dormantMonths);
        self::assertSame(12, AvailabilityThresholds::fromSettings(['availability_dormant_months' => 'twelve'])->dormantMonths);
        self::assertSame(12, AvailabilityThresholds::fromSettings([])->dormantMonths);
        self::assertTrue(AvailabilityThresholds::fromSettings([])->onRequestCounts);
    }

    public function testADestinationSellsWithEnoughPeersWithOffers(): void
    {
        self::assertTrue((new DestinationPeers(8, 2))->selling(5, 25));
        self::assertFalse((new DestinationPeers(8, 1))->selling(5, 25));
        self::assertFalse((new DestinationPeers(4, 4))->selling(5, 25));
        self::assertFalse((new DestinationPeers())->selling(0, 0), 'nothing checked is not selling');
    }
}
