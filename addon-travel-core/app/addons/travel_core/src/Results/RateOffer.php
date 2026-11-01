<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

/**
 * One bookable rate for the hotel page's room-first results (Design A,
 * docs/hotel-page-design-a-plan.md), as a supplier's mapper hands it over.
 *
 * Customer-facing text only: no supplier name or net price. The room code
 * and the package are shown as the supplier sends them (the owner's call).
 * Amounts are stay totals in the store's primary currency for the guests
 * searched; dates are ISO (Y-m-d). RoomResultsBuilder groups the rates into
 * room cards, one option per meal plan and terms.
 */
final class RateOffer
{
    // Meal plan order in the meal filter and in a room's options, lightest first.
    public const MEAL_ROOM_ONLY = 0;
    public const MEAL_BREAKFAST = 1;
    public const MEAL_HALF_BOARD = 2;
    public const MEAL_FULL_BOARD = 3;
    public const MEAL_ALL_INCLUSIVE = 4;
    public const MEAL_ULTRA_ALL_INCLUSIVE = 5;
    public const MEAL_OTHER = 9;

    /**
     * @param string $roomKey groups rates into one room card (the supplier's room type)
     * @param string $roomName the room's type: "Cameră Dublă"
     * @param string $mealPlan "Ultra All Inclusive"
     * @param float $price the stay total
     * @param string $bookUrl where "Rezervă" goes (the supplier's booking form)
     * @param string $roomTitle the card's title, the type and what tells it from the hotel's others
     *                          ("Cameră Dublă Deluxe, fără balcon"); '' = the room's type
     * @param int $mealRank one of the MEAL_* constants
     * @param string $rateName what the rate is called, when it has a name of its own ("Tarif nerambursabil")
     * @param float $standardPrice the stay total before this rate's discount (the struck price); 0 = no discount
     * @param string $discountLabel the discount's name, without its percent ("Early Booking")
     * @param list<string> $perks what the rate adds ("Acces la plajă inclus")
     * @param string $freeCancellationUntil the last day to cancel free of charge, while still ahead
     * @param bool $nonRefundable no refund once booked
     * @param bool $onRequest the hotel confirms within 48 h; nothing is paid before
     * @param int $roomsLeft rooms of this type left; -1 = unknown
     * @param float $depositRatio share payable on booking when a deposit may be paid (DepositPlan); 0 = in full
     * @param string $balanceDue when the rest is due, with a deposit
     * @param list<array{title: string, lines: list<string>}> $terms payment and cancellation terms as prose, for a supplier that sends them as text
     * @param list<string> $facilities the room's facilities
     * @param array<string, mixed> $timeline structured terms: TermsTimelineFactory's cancel_steps / payment_steps, shown as in the booking sidebar
     * @param string $roomCode the supplier's room code, shown under the title ("DBL 2+0 DELUXE NO BALCONY"); '' = none worth showing
     * @param int $capacity the most guests the room takes; 0 = unknown
     * @param string $packageName the supplier's package, shown as sent ("ADMIRAL ***** +BEACH 2027")
     * @param string $discountUntil the last day the discount can be booked
     * @param bool $freeCancellation free to cancel today, the last free day not known yet (it comes with the terms)
     * @param string $termsUrl where the terms load from when they are not in the search (JSON {sections}); '' = in $terms / $timeline
     * @param string $paymentText how payment works, as the store words it for a supplier without payment terms in the search
     * @param array<string, scalar> $roomData what a booking of several rooms (RoomGroupsBuilder) posts for one room at this
     *                                        rate: the supplier's room entry without its guests; [] = one room, by $bookUrl
     */
    public function __construct(
        public readonly string $roomKey,
        public readonly string $roomName,
        public readonly string $mealPlan,
        public readonly float $price,
        public readonly string $bookUrl,
        public readonly string $roomTitle = '',
        public readonly int $mealRank = self::MEAL_OTHER,
        public readonly string $rateName = '',
        public readonly float $standardPrice = 0.0,
        public readonly string $discountLabel = '',
        public readonly array $perks = [],
        public readonly string $freeCancellationUntil = '',
        public readonly bool $nonRefundable = false,
        public readonly bool $onRequest = false,
        public readonly int $roomsLeft = -1,
        public readonly float $depositRatio = 0.0,
        public readonly string $balanceDue = '',
        public readonly array $terms = [],
        public readonly array $facilities = [],
        public readonly array $timeline = [],
        public readonly string $roomCode = '',
        public readonly int $capacity = 0,
        public readonly string $packageName = '',
        public readonly string $discountUntil = '',
        public readonly bool $freeCancellation = false,
        public readonly string $termsUrl = '',
        public readonly string $paymentText = '',
        public readonly array $roomData = [],
    ) {
    }

    /** Free to cancel today: until a known day, or (Sphinx) until a day the terms will say. */
    public function isFree(): bool
    {
        return !$this->nonRefundable && ($this->freeCancellationUntil !== '' || $this->freeCancellation);
    }
}
