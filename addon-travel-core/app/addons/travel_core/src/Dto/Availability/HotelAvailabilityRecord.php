<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Availability;

use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the records say about one supplier hotel: a row of
 * ?:travel_hotel_availability, as the availability rules read it.
 *
 * Dates are null when nothing was ever seen. Times keep the hour (when we
 * saw something); stay and price dates are calendar days.
 */
final readonly class HotelAvailabilityRecord
{
    public const string OVERRIDE_CLOSED = 'closed';

    public function __construct(
        public string $provider,
        public string $hotelId,
        public int $productId = 0,
        public string $destinationKey = '',
        /** When the supplier first listed the hotel. */
        public ?\DateTimeImmutable $knownSince = null,
        /** When recording started for this hotel (the row's creation). */
        public ?\DateTimeImmutable $recordedSince = null,
        /** Past seasons were filled in from stored data, so the records reach back before $recordedSince. */
        public bool $backfilled = false,
        public int $listedMissingRuns = 0,
        public ?\DateTimeImmutable $contractTo = null,
        public ?\DateTimeImmutable $contractLastDate = null,
        /** Last bookable offer, on request included. */
        public ?\DateTimeImmutable $offerSeenAt = null,
        public ?\DateTimeImmutable $offerStayDate = null,
        /** Last offer that could be booked at once. */
        public ?\DateTimeImmutable $immediateSeenAt = null,
        public ?\DateTimeImmutable $offerCheckedAt = null,
        public ?\DateTimeImmutable $lastBookingCheckIn = null,
        /** '', keep_visible, keep_hidden or closed. */
        public string $override = '',
        public ?HotelAvailabilityState $state = null,
        public ?\DateTimeImmutable $stateSince = null,
    ) {
    }

    /** @param array<string, mixed> $row a ?:travel_hotel_availability row */
    public static function fromRow(array $row): self
    {
        return new self(
            provider: TypeCoerce::toString($row['provider'] ?? ''),
            hotelId: TypeCoerce::toString($row['hotel_id'] ?? ''),
            productId: TypeCoerce::toInt($row['product_id'] ?? 0),
            destinationKey: TypeCoerce::toString($row['destination_key'] ?? ''),
            knownSince: self::date($row['known_since'] ?? null),
            recordedSince: self::date($row['created_at'] ?? null),
            backfilled: self::date($row['backfilled_at'] ?? null) !== null,
            listedMissingRuns: TypeCoerce::toInt($row['listed_missing_runs'] ?? 0),
            contractTo: self::date($row['contract_to'] ?? null),
            contractLastDate: self::date($row['contract_last_date'] ?? null),
            offerSeenAt: self::date($row['offer_seen_at'] ?? null),
            offerStayDate: self::date($row['offer_stay_date'] ?? null),
            immediateSeenAt: self::date($row['immediate_seen_at'] ?? null),
            offerCheckedAt: self::date($row['offer_checked_at'] ?? null),
            lastBookingCheckIn: self::date($row['last_booking_check_in'] ?? null),
            override: TypeCoerce::toString($row['override'] ?? ''),
            state: HotelAvailabilityState::tryFrom(TypeCoerce::toString($row['state'] ?? '')),
            stateSince: self::date($row['state_since'] ?? null),
        );
    }

    /** A DATE or DATETIME column; NULL, empty and MySQL's zero dates read as never. */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $text = TypeCoerce::toString($value);
        if ($text === '' || str_starts_with($text, '0000-00-00')) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date ?: null;
    }
}
