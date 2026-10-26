<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * Rates → the room-first results of the Design A hotel page: one card per
 * room type with its rates under it, by the mockup's rules (Design A board,
 * frames 3, 10 and 12; docs/hotel-page-design-a-plan.md):
 *
 *  - R1: the meal strip (each meal plan with its lowest price, also a
 *    filter) only when the hotel has two or more meal plans; each room then
 *    groups its rates by meal plan.
 *  - R2: one meal plan: no strip and no meal sub-headers, rows titled by
 *    their rate, "every rate includes <meal plan>" said once in the fact line.
 *  - R3: a filter chip only when it splits the list (free cancellation,
 *    instant confirmation, special offers); what holds for every rate joins
 *    the fact line instead and is not repeated on the rooms.
 *  - A room says once what all its rates share (cancellation, payment,
 *    terms); otherwise each rate says its own.
 *  - Many rates: a room shows its best BEST (the cheapest of each meal plan
 *    first), then "see all N rates · min – max"; above COLLAPSE_OVER rates
 *    in all, the rooms after the first show only their cheapest rate.
 *
 * Rooms come cheapest first, a room's rates cheapest first. Amounts and
 * dates are formatted here, so the template only lays them out.
 *
 * @phpstan-type Payment array{on_request: bool, deposit_percent: int, balance_due: string}
 * @phpstan-type Terms list<array{title: string, lines: list<string>}>
 * @phpstan-type RateView array{
 *     meal: string, meal_key: string, name: string, amount: float, price: string,
 *     per_night: string, saving: string, discount: string, perks: list<string>,
 *     cheapest: bool, free_until: string, non_refundable: bool, on_request: bool,
 *     free: bool, special: bool, payment: Payment, terms: Terms, book_url: string,
 *     shown: bool
 * }
 * @phpstan-type GroupView array{meal: string, meal_key: string, count: int, shown: bool, rates: list<RateView>}
 * @phpstan-type RoomView array{
 *     key: string, name: string, detail: string, facilities: string, from: float,
 *     status: string, left: int, cancellation: string, free_until: string,
 *     payment: Payment|null, terms: Terms, groups: list<GroupView>, cheapest_rate: RateView,
 *     rate_count: int, rate_form: string, hidden: int, range: string, collapsed: bool
 * }
 * @phpstan-type ResultsView array{
 *     rate_count: int, rate_form: string, room_count: int, room_form: string,
 *     nights: int, cheapest: string, grouped: bool,
 *     meal_plans: list<array{key: string, name: string, from: string, count: int}>,
 *     facts: array{meal: string, immediate: bool, free_until: string},
 *     chips: list<array{key: string, count: int}>,
 *     rooms: list<RoomView>
 * }
 */
final class RoomResultsBuilder
{
    /** Rates a room shows before "see all". */
    public const BEST = 4;
    /** Above this many rates in all, the rooms after the first start collapsed. */
    public const COLLAPSE_OVER = 12;
    /** This many rooms left or fewer: the room says so. */
    public const FEW_LEFT = 3;

    // What a room says about confirmation ('' = nothing: the fact line said
    // it for every rate, or its rates differ and each says its own).
    public const STATUS_IMMEDIATE = 'immediate';
    public const STATUS_ON_REQUEST = 'on_request';

    // What a room says about cancellation.
    public const CANCEL_FREE = 'free';
    public const CANCEL_NON_REFUNDABLE = 'non_refundable';
    public const CANCEL_DIFFERS = 'differs';
    public const CANCEL_SAID = 'said';

    public const CHIP_FREE = 'free';
    public const CHIP_IMMEDIATE = 'immediate';
    public const CHIP_SPECIAL = 'special';

    /** @param \Closure(string): string $formatDate an ISO date in the store's format */
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly \Closure $formatDate,
    ) {
    }

    /**
     * The plural form a count takes ("1 tarif", "8 tarife", "30 de tarife"):
     * Romanian's three (CLDR), which English reads as one / other.
     */
    public static function form(int $n): string
    {
        if ($n === 1) {
            return 'one';
        }
        $tail = $n % 100;

        return $n === 0 || ($tail >= 1 && $tail <= 19) ? 'few' : 'many';
    }

    /**
     * @param list<RateOffer> $rates
     * @return ResultsView
     */
    public function build(array $rates, int $nights): array
    {
        $rates = array_values(array_filter($rates, static fn (RateOffer $r): bool => $r->price > 0));
        $total = count($rates);

        $meals = $this->mealPlans($rates);
        $mealKeys = [];
        foreach ($meals as $meal) {
            $mealKeys[$meal['name']] = $meal['key'];
        }
        $grouped = count($meals) > 1;

        $freeDays = array_values(array_unique(array_map(
            static fn (RateOffer $r): string => $r->nonRefundable ? '' : $r->freeCancellationUntil,
            $rates,
        )));
        $facts = [
            'meal' => count($meals) === 1 ? $meals[0]['name'] : '',
            'immediate' => $total > 0 && self::every($rates, static fn (RateOffer $r): bool => !$r->onRequest),
            'free_until' => count($freeDays) === 1 && $freeDays[0] !== '' ? ($this->formatDate)($freeDays[0]) : '',
        ];

        $chips = [];
        foreach ([
            self::CHIP_FREE => static fn (RateOffer $r): bool => self::isFree($r),
            self::CHIP_IMMEDIATE => static fn (RateOffer $r): bool => !$r->onRequest,
            self::CHIP_SPECIAL => static fn (RateOffer $r): bool => $r->isSpecial(),
        ] as $key => $test) {
            $count = count(array_filter($rates, $test));
            if ($count > 0 && $count < $total) {
                $chips[] = ['key' => $key, 'count' => $count];
            }
        }

        $cheapest = null;
        foreach ($rates as $rate) {
            if ($cheapest === null || $rate->price < $cheapest->price) {
                $cheapest = $rate;
            }
        }

        $byRoom = [];
        foreach ($rates as $rate) {
            $byRoom[$rate->roomKey][] = $rate;
        }
        foreach ($byRoom as $key => $roomRates) {
            usort($roomRates, static fn (RateOffer $a, RateOffer $b): int => $a->price <=> $b->price);
            $byRoom[$key] = $roomRates;
        }
        $roomLists = array_values($byRoom);
        usort($roomLists, static fn (array $a, array $b): int => $a[0]->price <=> $b[0]->price);

        $rooms = [];
        foreach ($roomLists as $index => $roomRates) {
            $rooms[] = $this->room(
                $index,
                $roomRates,
                $facts,
                $grouped,
                $mealKeys,
                $cheapest,
                $nights,
                $index > 0 && $total > self::COLLAPSE_OVER,
            );
        }

        return [
            'rate_count' => $total,
            'rate_form' => self::form($total),
            'room_count' => count($rooms),
            'room_form' => self::form(count($rooms)),
            'nights' => $nights,
            'cheapest' => $cheapest !== null ? $this->money->format($cheapest->price) : '',
            'grouped' => $grouped,
            'meal_plans' => $meals,
            'facts' => $facts,
            'chips' => $chips,
            'rooms' => $rooms,
        ];
    }

    /**
     * The hotel's meal plans, lightest first, each with its lowest price.
     *
     * @param list<RateOffer> $rates
     * @return list<array{key: string, name: string, from: string, count: int}>
     */
    private function mealPlans(array $rates): array
    {
        $plans = [];
        foreach ($rates as $order => $rate) {
            $plan = $plans[$rate->mealPlan] ?? ['rank' => $rate->mealRank, 'order' => $order, 'min' => $rate->price, 'count' => 0];
            $plan['min'] = min($plan['min'], $rate->price);
            $plan['count']++;
            $plans[$rate->mealPlan] = $plan;
        }
        uasort($plans, static fn (array $a, array $b): int => [$a['rank'], $a['order']] <=> [$b['rank'], $b['order']]);

        $list = [];
        foreach ($plans as $name => $plan) {
            $list[] = [
                'key' => 'm' . count($list),
                'name' => (string) $name,
                'from' => $this->money->format($plan['min']),
                'count' => $plan['count'],
            ];
        }

        return $list;
    }

    /**
     * @param list<RateOffer> $rates one room's, cheapest first
     * @param array{meal: string, immediate: bool, free_until: string} $facts
     * @param array<string, string> $mealKeys
     * @return RoomView
     */
    private function room(
        int $index,
        array $rates,
        array $facts,
        bool $grouped,
        array $mealKeys,
        ?RateOffer $cheapest,
        int $nights,
        bool $collapsed,
    ): array {
        $first = $rates[0];
        $count = count($rates);

        $status = '';
        if (self::every($rates, static fn (RateOffer $r): bool => $r->onRequest)) {
            $status = self::STATUS_ON_REQUEST;
        } elseif (!$facts['immediate'] && self::every($rates, static fn (RateOffer $r): bool => !$r->onRequest)) {
            $status = self::STATUS_IMMEDIATE;
        }

        $left = 0;
        foreach ($rates as $rate) {
            if ($rate->roomsLeft > 0 && ($left === 0 || $rate->roomsLeft < $left)) {
                $left = $rate->roomsLeft;
            }
        }

        $freeDays = array_values(array_unique(array_map(
            static fn (RateOffer $r): string => $r->nonRefundable ? '' : $r->freeCancellationUntil,
            $rates,
        )));
        $cancellation = self::CANCEL_DIFFERS;
        $freeUntil = '';
        if ($facts['free_until'] !== '') {
            $cancellation = self::CANCEL_SAID;
        } elseif (count($freeDays) === 1 && $freeDays[0] !== '') {
            $cancellation = self::CANCEL_FREE;
            $freeUntil = ($this->formatDate)($freeDays[0]);
        } elseif (self::every($rates, static fn (RateOffer $r): bool => $r->nonRefundable)) {
            $cancellation = self::CANCEL_NON_REFUNDABLE;
        } elseif (count($freeDays) === 1) {
            $cancellation = '';
        }

        $payments = array_map(fn (RateOffer $r): array => $this->payment($r), $rates);
        $payment = count(array_unique(array_map('serialize', $payments))) === 1 ? $payments[0] : null;
        $termsSets = array_map(static fn (RateOffer $r): string => serialize($r->terms), $rates);
        $terms = count(array_unique($termsSets)) === 1 ? $first->terms : [];

        $shown = $this->shownRates($rates, $collapsed);
        $views = [];
        foreach ($rates as $i => $rate) {
            $views[$i] = $this->rate($rate, $mealKeys, $rate === $cheapest, $nights, isset($shown[$i]));
        }

        $groups = [];
        foreach ($views as $i => $view) {
            $meal = $grouped ? $rates[$i]->mealPlan : '';
            $groups[$meal] ??= ['meal' => $meal, 'meal_key' => $grouped ? $view['meal_key'] : '', 'count' => 0, 'shown' => false, 'rates' => []];
            $groups[$meal]['count']++;
            $groups[$meal]['shown'] = $groups[$meal]['shown'] || $view['shown'];
            $groups[$meal]['rates'][] = $view;
        }

        $facilities = [];
        foreach ($rates as $rate) {
            if ($rate->facilities !== []) {
                $facilities = $rate->facilities;
                break;
            }
        }
        $hidden = $count - count($shown);
        $last = $rates[$count - 1];

        return [
            'key' => 'room-' . $index,
            'name' => $first->roomName,
            'detail' => $first->roomDetail,
            'facilities' => implode(' · ', $facilities),
            'from' => $first->price,
            'status' => $status,
            'left' => $left <= self::FEW_LEFT ? $left : 0,
            'cancellation' => $cancellation,
            'free_until' => $freeUntil,
            'payment' => $payment,
            'terms' => $terms,
            'groups' => array_values($groups),
            'cheapest_rate' => $views[0],
            'rate_count' => $count,
            'rate_form' => self::form($count),
            'hidden' => $hidden,
            'range' => $hidden > 0
                ? $this->money->format($first->price) . ' – ' . $this->money->format($last->price)
                : '',
            'collapsed' => $collapsed && $hidden > 0,
        ];
    }

    /**
     * The rates a room shows before "see all", by index: all of them up to
     * BEST; else the cheapest of each meal plan, then the next cheapest, up
     * to BEST; a collapsed room, its cheapest only.
     *
     * @param list<RateOffer> $rates cheapest first
     * @return array<int, true>
     */
    private function shownRates(array $rates, bool $collapsed): array
    {
        if ($collapsed) {
            return [0 => true];
        }
        if (count($rates) <= self::BEST) {
            return array_fill_keys(array_keys($rates), true);
        }
        $shown = [];
        $meals = [];
        foreach ($rates as $i => $rate) {
            if (!isset($meals[$rate->mealPlan]) && count($shown) < self::BEST) {
                $meals[$rate->mealPlan] = true;
                $shown[$i] = true;
            }
        }
        foreach ($rates as $i => $rate) {
            if (count($shown) >= self::BEST) {
                break;
            }
            $shown[$i] = true;
        }
        ksort($shown);

        return $shown;
    }

    /**
     * @param array<string, string> $mealKeys
     * @return RateView
     */
    private function rate(RateOffer $rate, array $mealKeys, bool $cheapest, int $nights, bool $shown): array
    {
        $perNight = $nights > 0 ? $this->money->formatDisplay($this->money->toDisplay($rate->price) / $nights) : '';

        return [
            'meal' => $rate->mealPlan,
            'meal_key' => $mealKeys[$rate->mealPlan] ?? '',
            'name' => $rate->rateName,
            'amount' => $rate->price,
            'price' => $this->money->format($rate->price),
            'per_night' => $perNight,
            'saving' => $rate->standardPrice > $rate->price ? $this->money->format($rate->standardPrice - $rate->price) : '',
            'discount' => $rate->discountLabel,
            'perks' => $rate->perks,
            'cheapest' => $cheapest,
            'free_until' => self::isFree($rate) ? ($this->formatDate)($rate->freeCancellationUntil) : '',
            'non_refundable' => $rate->nonRefundable,
            'on_request' => $rate->onRequest,
            'free' => self::isFree($rate),
            'special' => $rate->isSpecial(),
            'payment' => $this->payment($rate),
            'terms' => $rate->terms,
            'book_url' => $rate->bookUrl,
            'shown' => $shown,
        ];
    }

    /** @return Payment */
    private function payment(RateOffer $rate): array
    {
        $deposit = !$rate->onRequest && $rate->depositRatio > 0 && $rate->depositRatio < 1 && $rate->balanceDue !== '';

        return [
            'on_request' => $rate->onRequest,
            'deposit_percent' => $deposit ? (int) round($rate->depositRatio * 100) : 0,
            'balance_due' => $deposit ? ($this->formatDate)($rate->balanceDue) : '',
        ];
    }

    private static function isFree(RateOffer $rate): bool
    {
        return !$rate->nonRefundable && $rate->freeCancellationUntil !== '';
    }

    /**
     * @param list<RateOffer> $rates
     * @param \Closure(RateOffer): bool $test
     */
    private static function every(array $rates, \Closure $test): bool
    {
        foreach ($rates as $rate) {
            if (!$test($rate)) {
                return false;
            }
        }

        return true;
    }
}
