<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * A supplier's indicative prices for a hotel page before dates are chosen —
 * the "de la X € / noapte" of the Design A title row
 * (travelcore_design_a.tpl) and the prices in its calendar: what ONE night
 * for TWO adults costs, in the store's PRIMARY currency with commission, the
 * basis add_to_cart prices in (MoneyFormatter then shows it in the shopper's
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

    /**
     * The same figure per check-in date, for the Design A calendar: what one
     * night for two adults costs from that date (store primary currency,
     * commission included). Dates before today are left out.
     *
     * @param string $hotelId the provider's own hotel id (HotelSeoData::$hotelId)
     *
     * @return array<string, float> 'Y-m-d' => price; [] when the supplier has none
     */
    public function nightlyCalendar(string $hotelId): array;
}
