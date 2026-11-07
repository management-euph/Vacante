<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The packages/book request as the Sphinx doc writes it:
 *
 *   offer_id, reference_code (the store's order), price, currency,
 *   occupancy: [{room_code, guests: [{first_name, last_name, birth_date, gender}]}],
 *   additional_services: the optional services the customer chose.
 *
 * All but additional_services is the body circuits share (OfferBookBody).
 * The price and the rooms come from the offer as Sphinx quotes it now:
 * verify, or customize when services were chosen (quote()). The checkout
 * may have absorbed a small increase, or the price may have moved since
 * the cart. Without a quote, they come from what add-to-cart stored.
 *
 * The package line used to send the hotel line's body ({offer_id, guests
 * map, contact}): no price, no currency, no room codes, no gender.
 */
final class PackageBookRequest
{
    /**
     * The package as Sphinx prices it now: verified, then customized with
     * the chosen services. Null when it cannot be asked, or when the chosen
     * services cannot be priced (the verified price would leave them out).
     *
     * @param SphinxApi $api the API
     * @param string $offerId the booked offer
     * @param list<string> $services the chosen optional services' codes
     *
     * @return array<string, mixed>|null the offer (hotel.rooms, pricing)
     */
    public static function quote(SphinxApi $api, string $offerId, array $services): ?array
    {
        try {
            $offer = $api->verifyPackageOffer($offerId);
            if ($services !== [] && $offer !== null) {
                $offer = $api->customizePackage(['offer_id' => $offerId, 'service_codes' => $services]);
            }
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
     * @return array<string, mixed> the packages/book body
     */
    public static function build(array $row, ?array $quote, int $orderId): array
    {
        $quote ??= [];

        return OfferBookBody::build(
            $row,
            TypeCoerce::toStringMap($quote['pricing'] ?? null),
            TypeCoerce::toRowList(TypeCoerce::toStringMap($quote['hotel'] ?? null)['rooms'] ?? []),
            $orderId,
        ) + ['additional_services' => self::chosenServices($row)];
    }
}
