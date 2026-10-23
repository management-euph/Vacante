<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Availability;

use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;

/**
 * What the availability rules make of one hotel's records.
 *
 * $reason is a short code the report translates (REASON_*), stored in
 * state_reason and the state log.
 */
final readonly class AvailabilityVerdict
{
    public const string REASON_OVERRIDE_CLOSED = 'override_closed';
    public const string REASON_OFFER_SEEN = 'offer_seen';
    public const string REASON_OFFER_RECENT = 'offer_recent';
    public const string REASON_LEFT_FEED = 'left_feed';
    public const string REASON_NO_AVAILABILITY = 'no_availability';
    public const string REASON_PEERS_SELLING = 'peers_selling';
    public const string REASON_SEASON_MEMORY = 'season_memory';
    public const string REASON_NO_EVIDENCE = 'no_evidence';

    /**
     * @param bool $seasonPublished between seasons, and next season is already priced or offered
     * @param bool $soldOut not offered, but it had offers within the last month
     * @param bool $proposeClosed it left the feed long enough ago to propose marking it closed
     */
    public function __construct(
        public HotelAvailabilityState $state,
        public string $reason,
        public bool $seasonPublished = false,
        public bool $soldOut = false,
        public bool $proposeClosed = false,
    ) {
    }
}
