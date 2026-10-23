<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Enums;

/**
 * How complete a supplier's hotel list is, which decides whether a hotel
 * missing from it can count as "left the feed".
 */
enum HotelListCoverage: string
{
    /** One answer lists every hotel of a country (Novoton's hotel_list). */
    case PerCountry = 'per_country';

    /** One answer lists every hotel of a city (Eurosite's own hotels). */
    case PerCity = 'per_city';

    /** Only a full sync lists everything; deltas carry no removals (Sphinx). */
    case FullSyncOnly = 'full_sync_only';
}
