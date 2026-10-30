<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * What a provider adds to a booking's own page (travel_bookings.view) and
 * never to the bookings list, which asks getDisplayData() of
 * BookingAdminProviderInterface for every row: whatever costs a lookup per
 * booking or is too big for a list row.
 *
 * Optional: an admin provider implements it next to
 * BookingAdminProviderInterface; the page asks only the ones that do.
 */
interface BookingPageDetailsProviderInterface
{
    /**
     * - supplier_rooms: each room as the supplier names it, in room order,
     *   for a booking no order holds yet (an order's booking card carries
     *   its own);
     * - technical: the booking's exchange with the supplier (what was sent,
     *   what came back), rows of the page's technical details, preformatted
     *   when is_pre.
     *
     * @param string $providerBookingId The provider_booking_id from travel_bookings
     * @return array{supplier_rooms?: list<string>, technical?: list<array{label: string, value: string, is_pre: bool}>}
     */
    public function getPageDetails(string $providerBookingId): array;
}
