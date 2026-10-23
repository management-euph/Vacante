<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Availability\AvailabilityVerdict;
use Tygh\Addons\TravelCore\Dto\Availability\DestinationPeers;
use Tygh\Addons\TravelCore\Dto\Availability\HotelAvailabilityRecord;
use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Services\AvailabilityPolicy;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityThresholds;

/**
 * The availability rules of docs/novoton-availability-gaps-plan.md §5, on
 * the cases the plan names: October, July, sold out, left the feed, dormant
 * and the admin's overrides.
 */
#[CoversClass(AvailabilityPolicy::class)]
final class AvailabilityPolicyTest extends TestCase
{
    private static function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /**
     * A hotel known and recorded for years (backfilled, as Novoton's are), in
     * a destination whose last check answered. Each test changes what it needs.
     *
     * @param array<string, mixed> $changes HotelAvailabilityRecord constructor arguments
     */
    private static function hotel(array $changes = []): HotelAvailabilityRecord
    {
        /** @var array{provider: string, hotelId: string} $args */
        $args = [
            'provider' => 'novoton',
            'hotelId' => '476',
            'knownSince' => self::d('2023-05-01'),
            'recordedSince' => self::d('2026-10-01'),
            'backfilled' => true,
            ...$changes,
        ];

        return new HotelAvailabilityRecord(...$args);
    }

    private static function quiet(): DestinationPeers
    {
        return new DestinationPeers(checkedHotels: 80, hotelsWithOffers: 0, lastCheckAnswered: true);
    }

    private static function selling(): DestinationPeers
    {
        return new DestinationPeers(checkedHotels: 80, hotelsWithOffers: 60, lastCheckAnswered: true);
    }

    private static function judge(HotelAvailabilityRecord $hotel, DestinationPeers $peers, string $today, ?AvailabilityThresholds $limits = null): AvailabilityVerdict
    {
        return AvailabilityPolicy::classify($hotel, $peers, $limits ?? new AvailabilityThresholds(), self::d($today));
    }

    public function testInOctoberASeasideHotelThatSoldInAugustIsBetweenSeasons(): void
    {
        $hotel = self::hotel(['offerSeenAt' => self::d('2026-08-20 04:10:00'), 'offerStayDate' => self::d('2026-08-25'), 'offerCheckedAt' => self::d('2026-10-12')]);

        $verdict = self::judge($hotel, self::quiet(), '2026-10-16');

        self::assertSame(HotelAvailabilityState::BetweenSeasons, $verdict->state);
        self::assertFalse($verdict->seasonPublished);
    }

    public function testNextSeasonsPublishedPricesAreFlagged(): void
    {
        $hotel = self::hotel(['contractTo' => self::d('2027-09-30'), 'contractLastDate' => self::d('2027-09-30')]);

        $verdict = self::judge($hotel, self::quiet(), '2026-11-20');

        self::assertSame(HotelAvailabilityState::BetweenSeasons, $verdict->state);
        self::assertTrue($verdict->seasonPublished);
    }

    public function testAnOfferSeenWithinEightDaysIsBookable(): void
    {
        $hotel = self::hotel(['offerSeenAt' => self::d('2027-07-12 04:05:00')]);

        self::assertSame(HotelAvailabilityState::Bookable, self::judge($hotel, self::quiet(), '2027-07-20')->state);
        self::assertSame(HotelAvailabilityState::BetweenSeasons, self::judge($hotel, self::quiet(), '2027-07-21')->state);
    }

    public function testInJulyAHotelWithoutOffersWhileItsResortSellsIsNotOffered(): void
    {
        // Booked last August, so within the 12 months: not dormant.
        $hotel = self::hotel(['offerCheckedAt' => self::d('2027-07-17'), 'lastBookingCheckIn' => self::d('2026-08-10')]);

        $verdict = self::judge($hotel, self::selling(), '2027-07-20');

        self::assertSame(HotelAvailabilityState::NotOffered, $verdict->state);
        self::assertSame(AvailabilityVerdict::REASON_PEERS_SELLING, $verdict->reason);
        self::assertFalse($verdict->soldOut);
    }

    public function testOneEmptyWeeklyCheckIsNotEnoughToCallItNotOffered(): void
    {
        $hotel = self::hotel(['offerSeenAt' => self::d('2027-07-10 04:00:00'), 'offerCheckedAt' => self::d('2027-07-17')]);

        $verdict = self::judge($hotel, self::selling(), '2027-07-20');

        self::assertSame(HotelAvailabilityState::Bookable, $verdict->state);
        self::assertSame(AvailabilityVerdict::REASON_OFFER_RECENT, $verdict->reason);
    }

    public function testAHotelWithOffersWithinAMonthIsSoldOut(): void
    {
        $hotel = self::hotel(['offerSeenAt' => self::d('2027-06-28 04:00:00'), 'offerCheckedAt' => self::d('2027-07-17')]);

        $verdict = self::judge($hotel, self::selling(), '2027-07-20');

        self::assertSame(HotelAvailabilityState::NotOffered, $verdict->state);
        self::assertTrue($verdict->soldOut);
    }

    public function testAResortIsSellingOnlyWithEnoughCheckedPeers(): void
    {
        $hotel = self::hotel(['offerCheckedAt' => self::d('2027-07-17')]);
        $fourOfFour = new DestinationPeers(checkedHotels: 4, hotelsWithOffers: 4, lastCheckAnswered: true);
        $tenOfFifty = new DestinationPeers(checkedHotels: 50, hotelsWithOffers: 10, lastCheckAnswered: true);

        self::assertNotSame(HotelAvailabilityState::NotOffered, self::judge($hotel, $fourOfFour, '2027-07-20')->state);
        self::assertNotSame(HotelAvailabilityState::NotOffered, self::judge($hotel, $tenOfFifty, '2027-07-20')->state, '20% is under 25%');
    }

    public function testNotOfferedNeedsTheHotelCheckedRecentlyAndRecordedTwoWeeks(): void
    {
        $notChecked = self::hotel(['offerCheckedAt' => self::d('2027-06-01')]);
        $justRecorded = self::hotel(['offerCheckedAt' => self::d('2027-07-17'), 'recordedSince' => self::d('2027-07-15'), 'backfilled' => false]);

        self::assertNotSame(HotelAvailabilityState::NotOffered, self::judge($notChecked, self::selling(), '2027-07-20')->state);
        self::assertNotSame(HotelAvailabilityState::NotOffered, self::judge($justRecorded, self::selling(), '2027-07-20')->state);
    }

    public function testThreeCompleteListsWithoutTheHotelMeanItLeftTheFeed(): void
    {
        self::assertSame(HotelAvailabilityState::LeftFeed, self::judge(self::hotel(['listedMissingRuns' => 3]), self::quiet(), '2026-11-20')->state);
        self::assertNotSame(HotelAvailabilityState::LeftFeed, self::judge(self::hotel(['listedMissingRuns' => 2]), self::quiet(), '2026-11-20')->state);
    }

    public function testSixMonthsOutOfTheFeedProposesClosing(): void
    {
        $left = ['listedMissingRuns' => 9, 'state' => HotelAvailabilityState::LeftFeed];

        self::assertTrue(self::judge(self::hotel([...$left, 'stateSince' => self::d('2026-05-01')]), self::quiet(), '2026-11-20')->proposeClosed);
        self::assertFalse(self::judge(self::hotel([...$left, 'stateSince' => self::d('2026-09-01')]), self::quiet(), '2026-11-20')->proposeClosed);
        self::assertFalse(self::judge(self::hotel(['listedMissingRuns' => 9]), self::quiet(), '2026-11-20')->proposeClosed, 'just left');
    }

    public function testTheAdminsClosedOverridesEverything(): void
    {
        $hotel = self::hotel(['override' => 'closed', 'offerSeenAt' => self::d('2027-07-19 04:00:00')]);

        self::assertSame(HotelAvailabilityState::Closed, self::judge($hotel, self::selling(), '2027-07-20')->state);
    }

    public function testKeepVisibleAndKeepHiddenDoNotChangeTheState(): void
    {
        $hotel = self::hotel(['override' => 'keep_visible', 'offerSeenAt' => self::d('2027-07-19 04:00:00')]);

        self::assertSame(HotelAvailabilityState::Bookable, self::judge($hotel, self::quiet(), '2027-07-20')->state);
    }

    /** The plan's example: sold in August 2026, it stays between seasons until August 2027 at the earliest. */
    public function testAHotelThatSoldInAugustCannotBeDormantThisWinter(): void
    {
        $hotel = self::hotel(['offerSeenAt' => self::d('2026-08-20 04:00:00'), 'offerStayDate' => self::d('2026-08-25'), 'offerCheckedAt' => self::d('2027-07-15')]);

        self::assertSame(HotelAvailabilityState::BetweenSeasons, self::judge($hotel, self::quiet(), '2027-03-01')->state);
        self::assertSame(HotelAvailabilityState::BetweenSeasons, self::judge($hotel, self::quiet(), '2027-08-20')->state);
        self::assertSame(HotelAvailabilityState::Dormant, self::judge($hotel, self::quiet(), '2027-08-26')->state, '12 months after the stay date');
    }

    public function testNoAvailabilityForTwelveMonthsIsDormant(): void
    {
        $hotel = self::hotel(['lastBookingCheckIn' => self::d('2025-08-01'), 'contractLastDate' => self::d('2025-09-30')]);

        $verdict = self::judge($hotel, self::quiet(), '2026-10-20');

        self::assertSame(HotelAvailabilityState::Dormant, $verdict->state);
        self::assertSame(AvailabilityVerdict::REASON_NO_AVAILABILITY, $verdict->reason);
    }

    public function testTheAdminsMonthsDecideWhenAHotelIsDormant(): void
    {
        $hotel = self::hotel(['contractLastDate' => self::d('2025-09-30')]);

        self::assertSame(HotelAvailabilityState::Dormant, self::judge($hotel, self::quiet(), '2026-10-20')->state);
        self::assertNotSame(HotelAvailabilityState::Dormant, self::judge($hotel, self::quiet(), '2026-10-20', new AvailabilityThresholds(dormantMonths: 18))->state);
    }

    public function testNotDormantWhileItsDestinationsCheckDoesNotAnswer(): void
    {
        $hotel = self::hotel(['contractLastDate' => self::d('2025-01-31')]);
        $silent = new DestinationPeers(lastCheckAnswered: false);

        self::assertSame(HotelAvailabilityState::NoData, self::judge($hotel, $silent, '2026-10-20')->state);
    }

    /** Eurosite and Sphinx cannot backfill: dormant starts once their records cover N months. */
    public function testNotDormantBeforeTheRecordsCoverTheMonths(): void
    {
        $hotel = self::hotel(['provider' => 'eurosite', 'recordedSince' => self::d('2026-11-15'), 'backfilled' => false]);

        self::assertSame(HotelAvailabilityState::NoData, self::judge($hotel, self::quiet(), '2027-06-01')->state);
        self::assertSame(HotelAvailabilityState::Dormant, self::judge($hotel, self::quiet(), '2027-11-15')->state);
    }

    public function testNotDormantBeforeTheHotelIsKnownForTheMonths(): void
    {
        $hotel = self::hotel(['knownSince' => self::d('2026-05-01')]);

        self::assertSame(HotelAvailabilityState::NoData, self::judge($hotel, self::quiet(), '2026-10-20')->state);
    }

    public function testOnRequestOffersCountUnlessTheAdminSaysOtherwise(): void
    {
        $hotel = self::hotel(['offerSeenAt' => self::d('2027-07-18 04:00:00'), 'offerStayDate' => self::d('2027-08-01')]);
        $immediateOnly = new AvailabilityThresholds(onRequestCounts: false);

        self::assertSame(HotelAvailabilityState::Bookable, self::judge($hotel, self::quiet(), '2027-07-20')->state);
        self::assertNotSame(HotelAvailabilityState::Bookable, self::judge($hotel, self::quiet(), '2027-07-20', $immediateOnly)->state);
        self::assertSame(
            HotelAvailabilityState::Bookable,
            self::judge(self::hotel(['immediateSeenAt' => self::d('2027-07-18 04:00:00')]), self::quiet(), '2027-07-20', $immediateOnly)->state,
        );
    }

    public function testABookedStayWithinThirteenMonthsKeepsItBetweenSeasons(): void
    {
        $hotel = self::hotel(['lastBookingCheckIn' => self::d('2025-11-10')]);

        self::assertSame(HotelAvailabilityState::BetweenSeasons, self::judge($hotel, new DestinationPeers(), '2026-10-20')->state);
    }

    public function testWithoutAnyEvidenceThereIsNoDataYet(): void
    {
        $verdict = self::judge(self::hotel(['backfilled' => false, 'recordedSince' => self::d('2026-10-01')]), self::quiet(), '2026-10-20');

        self::assertSame(HotelAvailabilityState::NoData, $verdict->state);
        self::assertSame(AvailabilityVerdict::REASON_NO_EVIDENCE, $verdict->reason);
    }
}
