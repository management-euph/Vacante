<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Enums;

/**
 * What a hotel's availability records mean (docs/novoton-availability-gaps-plan.md §2, §5).
 *
 * Values are stored in ?:travel_hotel_availability.state and the state log.
 */
enum HotelAvailabilityState: string
{
    /** An offer was seen recently. */
    case Bookable = 'bookable';

    /** No offer now, but the hotel sold, was priced or was booked within the season memory. */
    case BetweenSeasons = 'between_seasons';

    /** Its destination sells the same dates and this hotel has had no offer for two weekly checks. */
    case NotOffered = 'not_offered';

    /** No availability for the admin's N months, with records covering them. */
    case Dormant = 'dormant';

    /** Missing from the supplier's complete hotel list for several runs in a row. */
    case LeftFeed = 'left_feed';

    /** An admin marked it closed. */
    case Closed = 'closed';

    /** Too little evidence to say. */
    case NoData = 'no_data';
}
