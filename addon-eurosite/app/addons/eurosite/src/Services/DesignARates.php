<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Results\DesignA;
use Tygh\Addons\TravelCore\Results\MealRank;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Services\DateHelper;

/**
 * Eurosite's offers for one hotel → the rates of the Design A room cards
 * (travel_core Results\RateOffer; docs/hotel-page-design-a-plan.md), for a
 * one-room search: each offer is then one room at one meal plan for the
 * whole stay.
 *
 *  - the room as the hotel names it ("Double Room"); its code is the
 *    hotel's internal number, so none shows;
 *  - the meal plan(s) as sent ("Mic dejun"), ranked by name (MealRank);
 *    an offer without one is room only;
 *  - the price on the cart line's scale (the offer's currency through the
 *    store's coefficient, as add_to_cart charges it), the price before the
 *    reduction (PriceNoRedd, HotelOffer::oldPrice) and the offer's own text
 *    for it ("Reducere Oferta Speciala 15% …"), as the booking sidebar says;
 *  - IM = instant confirmation, OR = on request, ST (stop sale) left out:
 *    it cannot be booked;
 *  - no terms in the search: the card loads them when opened
 *    (eurosite_booking.offer_terms, design=a), and says how payment works in
 *    the store's words (the admin's payment terms, their first line);
 *  - "Rezervă" goes to the booking form with the offer's snapshot key, as
 *    today's card.
 */
final class DesignARates
{
    /**
     * @param \Closure(string): float $coefficient a currency's coefficient to the store's primary one
     * @param \Closure(string): string $url a storefront URL (fn_url)
     */
    public function __construct(
        private readonly \Closure $coefficient,
        private readonly \Closure $url,
        private readonly string $paymentText,
        private readonly int $returnProductId,
        private readonly string $roomOnly,
    ) {
    }

    /** The store's: its currencies, fn_url, the admin's payment terms. */
    public static function forStore(int $returnProductId): self
    {
        $coefficients = [];

        return new self(
            static function (string $currency) use (&$coefficients): float {
                return $coefficients[$currency] ??= TypeCoerce::toFloat(db_get_field(
                    'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
                    $currency,
                ));
            },
            static fn (string $url): string => TypeCoerce::toString(fn_url($url)),
            self::firstLine(ConfigProvider::getPaymentTermsText()),
            $returnProductId,
            TypeCoerce::toString(__('travel_core.pdpa_r_room_only')),
        );
    }

    /**
     * The Design A results view of a one-room search (design_a_results.tpl).
     *
     * @param list<array{offer: HotelOffer, key: string}> $offers this hotel's, each with its snapshot key
     * @param list<int> $childrenAges
     * @return array<string, mixed>
     */
    public static function view(array $offers, int $returnProductId, string $checkIn, string $checkOut, int $adults, array $childrenAges): array
    {
        return DesignA::builder()->build(
            self::forStore($returnProductId)->rates($offers),
            max(1, DateHelper::calculateNights($checkIn, $checkOut)),
            max(1, $adults),
            count($childrenAges),
        );
    }

    /**
     * @param list<array{offer: HotelOffer, key: string}> $offers
     * @return list<RateOffer>
     */
    public function rates(array $offers): array
    {
        $rates = [];
        foreach ($offers as ['offer' => $offer, 'key' => $key]) {
            $rate = $this->rate($offer, $key);
            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    private function rate(HotelOffer $offer, string $key): ?RateOffer
    {
        $room = $offer->rooms[0] ?? null;
        $roomName = trim(TypeCoerce::toString($room['name'] ?? ''));
        if ($key === '' || !$offer->isBookable() || $offer->price <= 0 || $roomName === '') {
            return null;
        }

        $meals = [];
        foreach ($offer->meals as $meal) {
            $name = trim(TypeCoerce::toString($meal['name'] ?? ''));
            if ($name !== '' && !in_array($name, $meals, true)) {
                $meals[] = $name;
            }
        }
        $ranks = array_values(array_filter(
            array_map(static fn (string $name): int => MealRank::of($name), $meals),
            static fn (int $rank): bool => $rank !== RateOffer::MEAL_OTHER,
        ));
        if ($meals === []) {
            $meals = [$this->roomOnly];
            $ranks = [RateOffer::MEAL_ROOM_ONLY];
        }

        $coefficient = ($this->coefficient)($offer->currency);
        $price = EurositeProductFactory::toStorePrice($offer->price, $coefficient);
        $old = $offer->oldPrice();
        $onRequest = $offer->availabilityCode === 'OR';

        return new RateOffer(
            roomKey: TypeCoerce::toString($room['code'] ?? '') . '|' . mb_strtolower($roomName),
            roomName: $roomName,
            mealPlan: implode(' + ', $meals),
            price: $price,
            bookUrl: ($this->url)('eurosite_booking.booking_form?' . http_build_query([
                'offer_key' => $key,
                'return_product_id' => $this->returnProductId,
            ])),
            mealRank: $ranks !== [] ? max($ranks) : RateOffer::MEAL_OTHER,
            standardPrice: $old > 0 ? EurositeProductFactory::toStorePrice($old, $coefficient) : 0.0,
            discountLabel: $old > 0 ? $offer->offerDescription : '',
            onRequest: $onRequest,
            termsUrl: ($this->url)('eurosite_booking.offer_terms?' . http_build_query([
                'offer_key' => $key,
                DesignA::PARAM => DesignA::VALUE,
            ])),
            paymentText: $this->paymentText,
        );
    }

    /**
     * The terms a card loads when opened (eurosite_booking.offer_terms,
     * design=a): the cancellation fees (getItemFees) as the booking sidebar
     * words them — or that they are confirmed at booking, when Eurosite gave
     * none — and the admin's payment terms.
     *
     * @param list<array{type: string, from_date: string, to_date: string, value: float, is_percent: bool}> $fees
     * @param list<string> $paymentLines
     * @param array{cancel: string, payment: string, fee_line: string, unavailable: string} $texts
     * @return list<array{title: string, lines: list<string>}>
     */
    public static function termsSections(array $fees, array $paymentLines, string $currency, array $texts, string $dateFormat): array
    {
        $cancelLines = BookingSidebarBuilder::cancelLines($fees, $currency, $texts['fee_line'], $dateFormat);
        $sections = [['title' => $texts['cancel'], 'lines' => $cancelLines !== [] ? $cancelLines : [$texts['unavailable']]]];
        if ($paymentLines !== []) {
            $sections[] = ['title' => $texts['payment'], 'lines' => $paymentLines];
        }

        return $sections;
    }

    /** The admin's payment terms, their first line: the card's one line about paying. */
    public static function firstLine(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }
}
