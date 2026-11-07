<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The circuits/book request as the Sphinx doc writes it:
 *
 *   offer_id (the quote's), reference_code (the store's order), price,
 *   currency, occupancy: [{room_code, guests: [{first_name, last_name, birth_date, gender}]}].
 *
 * The body packages share (OfferBookBody), without additional_services:
 * the chosen services are on the offer itself, added by customize.
 *
 * Circuits have no verify call, and a new quote is a new offer (a new
 * offer_id). So the price sent is the one Sphinx gave this offer: its
 * quote's, or its customized one with the services, which quote() asks
 * again at booking time. Without services nothing is asked: add-to-cart
 * stored the quote's price (pricing_json).
 *
 * The circuit line used to send the hotel line's body ({offer_id, guests
 * map, contact}): no price, no currency, no room codes, no gender.
 */
final class CircuitBookRequest
{
    /**
     * The circuit offer with the chosen services, as Sphinx prices it now
     * (customize). Null without services, or when it cannot be asked.
     *
     * @param SphinxApi $api the API
     * @param string $offerId the booked offer
     * @param list<string> $services the chosen optional services' codes
     *
     * @return array<string, mixed>|null the offer (rooms, pricing)
     */
    public static function quote(SphinxApi $api, string $offerId, array $services): ?array
    {
        if ($services === []) {
            return null;
        }
        try {
            $offer = $api->customizeCircuit(['offer_id' => $offerId, 'service_codes' => $services]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($offer) && $offer !== [] ? $offer : null;
    }

    /**
     * The optional services the customer chose, as add-to-cart stored them.
     *
     * @param array<string, mixed> $row the sphinx_bookings row
     *
     * @return list<string> their codes
     */
    public static function chosenServices(array $row): array
    {
        return OfferBookBody::chosenServices($row);
    }

    /**
     * @param array<string, mixed> $row the sphinx_bookings row
     * @param array<string, mixed>|null $quote quote(): the offer as Sphinx prices it now
     * @param int $orderId the store's order, sent as the reference code
     *
     * @return array<string, mixed> the circuits/book body
     */
    public static function build(array $row, ?array $quote, int $orderId): array
    {
        $quote ??= [];

        return OfferBookBody::build(
            $row,
            TypeCoerce::toStringMap($quote['pricing'] ?? null),
            TypeCoerce::toRowList($quote['rooms'] ?? []),
            $orderId,
        );
    }
}
