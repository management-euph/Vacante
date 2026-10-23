<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\Availability\AvailabilityVerdict;
use Tygh\Addons\TravelCore\Dto\Availability\DestinationPeers;
use Tygh\Addons\TravelCore\Dto\Availability\HotelAvailabilityRecord;
use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityThresholds;

/**
 * Turns one hotel's availability records into a state
 * (docs/novoton-availability-gaps-plan.md §5), the same way for every supplier.
 *
 * A hotel with no offer today is usually healthy: seaside hotels sell
 * May–October and next season's prices arrive between November and February.
 * So the rules look for evidence over months, and compare a hotel with the
 * rest of its destination before calling it "not offered". The first rule
 * that matches wins:
 *
 *  1. an admin marked it closed → closed;
 *  2. an offer within the last 8 days → bookable;
 *  3. missing from the supplier's complete hotel list 3 times in a row →
 *     left the feed (after 6 months there, proposed as closed);
 *  4. no availability for the admin's N months (12), with the hotel known and
 *     recorded that long, and its destination's latest check answered → dormant;
 *  5. its destination sells (at least 25% of at least 5 checked hotels had
 *     offers) and the hotel was checked recently → bookable while its last
 *     offer is under 14 days old (one empty weekly check is not enough), else
 *     not offered ("sold out" when it had offers within the last 30 days);
 *  6. an offer, a published price or a booked stay within the last 13 months,
 *     or prices published for a future date → between seasons;
 *  7. anything else → no data yet.
 *
 * "Availability" for rule 4 is the latest of: the last offer, its stay date,
 * the latest priced date and the latest booked stay. A hotel priced for next
 * summer is therefore never dormant.
 *
 * Pure: no database, no clock (today is passed in), so every rule is tested.
 */
final class AvailabilityPolicy
{
    public static function classify(
        HotelAvailabilityRecord $record,
        DestinationPeers $peers,
        AvailabilityThresholds $limits,
        \DateTimeImmutable $today,
    ): AvailabilityVerdict {
        $today = $today->setTime(0, 0);
        // On request counts unless the admin said otherwise; an immediate
        // offer's stay date is not kept, so it then has none.
        [$lastOffer, $offerStay] = $limits->onRequestCounts
            ? [$record->offerSeenAt, $record->offerStayDate]
            : [$record->immediateSeenAt, null];

        if ($record->override === HotelAvailabilityRecord::OVERRIDE_CLOSED) {
            return new AvailabilityVerdict(HotelAvailabilityState::Closed, AvailabilityVerdict::REASON_OVERRIDE_CLOSED);
        }

        if (self::since($lastOffer, self::daysAgo($today, $limits->freshDays))) {
            return new AvailabilityVerdict(HotelAvailabilityState::Bookable, AvailabilityVerdict::REASON_OFFER_SEEN);
        }

        if ($record->listedMissingRuns >= max(1, $limits->delistedRuns)) {
            $leftSince = $record->state === HotelAvailabilityState::LeftFeed ? $record->stateSince : null;

            return new AvailabilityVerdict(
                HotelAvailabilityState::LeftFeed,
                AvailabilityVerdict::REASON_LEFT_FEED,
                proposeClosed: $leftSince !== null && $leftSince <= self::monthsAgo($today, $limits->closedProposalMonths),
            );
        }

        $dormantFrom = self::monthsAgo($today, $limits->dormantMonths);
        $lastAvailability = self::latest($lastOffer, $offerStay, $record->contractLastDate, $record->lastBookingCheckIn);
        $knownSince = $record->knownSince ?? $record->recordedSince;
        if (($lastAvailability === null || $lastAvailability < $dormantFrom)
            && $knownSince !== null && $knownSince <= $dormantFrom
            && ($record->backfilled || ($record->recordedSince !== null && $record->recordedSince <= $dormantFrom))
            && $peers->lastCheckAnswered
        ) {
            return new AvailabilityVerdict(HotelAvailabilityState::Dormant, AvailabilityVerdict::REASON_NO_AVAILABILITY);
        }

        if ($peers->selling($limits->minPeers, $limits->peerSharePercent)
            && self::since($record->offerCheckedAt, self::daysAgo($today, $limits->freshDays))
        ) {
            $notOfferedFrom = self::daysAgo($today, $limits->notOfferedDays);
            if (self::since($lastOffer, $notOfferedFrom)) {
                return new AvailabilityVerdict(HotelAvailabilityState::Bookable, AvailabilityVerdict::REASON_OFFER_RECENT);
            }
            if ($record->recordedSince !== null && $record->recordedSince <= $notOfferedFrom) {
                return new AvailabilityVerdict(
                    HotelAvailabilityState::NotOffered,
                    AvailabilityVerdict::REASON_PEERS_SELLING,
                    soldOut: self::since($lastOffer, self::daysAgo($today, $limits->soldOutDays)),
                );
            }
        }

        $published = self::since($record->contractTo, $today) || self::since($offerStay, $today);
        $memoryFrom = self::monthsAgo($today, $limits->seasonMemoryMonths);
        if ($published || self::since(self::latest($lastOffer, $offerStay, $record->contractLastDate, $record->lastBookingCheckIn), $memoryFrom)) {
            return new AvailabilityVerdict(
                HotelAvailabilityState::BetweenSeasons,
                AvailabilityVerdict::REASON_SEASON_MEMORY,
                seasonPublished: $published,
            );
        }

        return new AvailabilityVerdict(HotelAvailabilityState::NoData, AvailabilityVerdict::REASON_NO_EVIDENCE);
    }

    /** $date is on or after $from; never-seen is not. */
    private static function since(?\DateTimeImmutable $date, \DateTimeImmutable $from): bool
    {
        return $date !== null && $date >= $from;
    }

    private static function latest(?\DateTimeImmutable ...$dates): ?\DateTimeImmutable
    {
        $known = array_filter($dates, static fn (?\DateTimeImmutable $d): bool => $d !== null);

        return $known === [] ? null : max($known);
    }

    private static function daysAgo(\DateTimeImmutable $today, int $days): \DateTimeImmutable
    {
        return $today->modify('-' . max(0, $days) . ' days');
    }

    private static function monthsAgo(\DateTimeImmutable $today, int $months): \DateTimeImmutable
    {
        return $today->modify('-' . max(0, $months) . ' months');
    }
}
