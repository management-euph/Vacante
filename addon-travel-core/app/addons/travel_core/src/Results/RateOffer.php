<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

/**
 * One bookable rate for the hotel page's room-first results (Design A,
 * docs/hotel-page-design-a-plan.md), as a supplier's mapper hands it over.
 *
 * Customer-facing text only: no supplier name, code or net price. Amounts
 * are stay totals in the store's primary currency for the guests searched;
 * dates are ISO (Y-m-d). RoomResultsBuilder groups the rates into room cards.
 */
final class RateOffer
{
    // Meal plan order in the meal strip, lightest first.
    public const MEAL_ROOM_ONLY = 0;
    public const MEAL_BREAKFAST = 1;
    public const MEAL_HALF_BOARD = 2;
    public const MEAL_FULL_BOARD = 3;
    public const MEAL_ALL_INCLUSIVE = 4;
    public const MEAL_ULTRA_ALL_INCLUSIVE = 5;
    public const MEAL_OTHER = 9;

    /**
     * @param string $roomKey groups rates into one room card (the supplier's room type)
     * @param string $roomName "Cameră dublă"
     * @param string $mealPlan "Ultra All Inclusive"
     * @param float $price the stay total
     * @param string $bookUrl where "Book" goes (the supplier's booking form)
     * @param string $roomDetail what tells this room from the hotel's others ("Deluxe · fără balcon")
     * @param int $mealRank one of the MEAL_* constants
     * @param string $rateName '' for the standard rate
     * @param float $standardPrice the stay total before this rate's discount; 0 = no discount
     * @param string $discountLabel "Early Booking −30%"
     * @param list<string> $perks what the rate adds ("Beach included")
     * @param string $freeCancellationUntil the last day to cancel free of charge, while still ahead
     * @param bool $nonRefundable no refund once booked
     * @param bool $onRequest the hotel confirms within 48 h; nothing is paid before
     * @param int $roomsLeft rooms of this type left; -1 = unknown
     * @param float $depositRatio share payable on booking when a deposit may be paid (DepositPlan); 0 = in full
     * @param string $balanceDue when the rest is due, with a deposit
     * @param list<array{title: string, lines: list<string>}> $terms payment and cancellation terms as prose, for a supplier that sends them as text
     * @param list<string> $facilities the room's facilities
     * @param array<string, mixed> $timeline structured terms: TermsTimelineFactory's cancel_steps / payment_steps, shown as in the booking sidebar
     */
    public function __construct(
        public readonly string $roomKey,
        public readonly string $roomName,
        public readonly string $mealPlan,
        public readonly float $price,
        public readonly string $bookUrl,
        public readonly string $roomDetail = '',
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
    ) {
    }

    /** A rate cheaper than the hotel's standard one for the same stay. */
    public function isSpecial(): bool
    {
        return $this->discountLabel !== '' || $this->standardPrice > $this->price;
    }
}
