<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * A supplier's indicative "from" price for a hotel page before dates are
 * chosen — the "de la X € / noapte" of the Design A title row
 * (travelcore_design_a.tpl): the lowest price it knows of for ONE night for
 * TWO adults, in the store's PRIMARY currency with commission, the basis
 * add_to_cart prices in (MoneyFormatter then shows it in the shopper's
 * currency).
 *
 * It runs on every hotel page, so it reads data the supplier already stores
 * (no API call) and never throws.
 */
interface IndicativePriceSourceInterface
{
    /** The provider name it is registered under. */
    public function provider(): string;

    /**
     * @param string $hotelId the provider's own hotel id (HotelSeoData::$hotelId)
     *
     * @return float|null null when the supplier has no such figure for the hotel
     */
    public function nightlyFromPrice(string $hotelId): ?float;
}
