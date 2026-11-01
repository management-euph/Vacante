<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * Rates → the room-first results of the Design A hotel page, by the owner's
 * board 13b (docs/hotel-page-design-a-plan.md): one card per room type, on
 * three columns — the room | its options and what they share | the "till",
 * the chosen option's total with the room's only button.
 *
 *  - One option per meal plan and terms: the same meal on the same terms
 *    (cancellation, payment, confirmation, inclusions) from two packages is
 *    one choice for the guest, its cheapest. A room's options come lightest
 *    meal first; the cheapest one is chosen when the card opens.
 *  - What all of a room's options share is said once, under them: the
 *    package with its discount (one unit), the cancellation, the payment,
 *    the terms. What differs stays on its option. A room with one option
 *    keeps its package on the option, like any other.
 *  - The meal filter only with two or more meal plans, each with how many
 *    room types have it and its lowest price; a chip only when it splits the
 *    options (free cancellation, instant confirmation).
 *  - The first ROOMS_SHOWN room types, then "see all" naming the others with
 *    their lowest price; "Cel mai mic preț" marks the cheapest room.
 *  - The discount's percent once, on the badge by the struck price; the
 *    discount's own line says until when and how much it saves.
 *
 * Rooms come cheapest first. Amounts and dates are formatted here, so the
 * template only lays them out. Keys (rooms, options, meal plans) are stable
 * for the same rates, so a list rendered again as more rates arrive (Sphinx
 * polls) keeps what the guest chose.
 *
 * @phpstan-type Payment array{on_request: bool, deposit_percent: int, balance_due: string, text: string}
 * @phpstan-type Terms list<array{title: string, lines: list<string>}>
 * @phpstan-type Deal array{name: string, until: string, saving: string}
 * @phpstan-type Cancel array{kind: string, until: string, time: string, rel: string, amount: string, nights: int, approx: bool, percent: string}
 * @phpstan-type TermsView array{for: int, terms: Terms, timeline: array<string, mixed>, url: string}
 * @phpstan-type OptionView array{
 *     index: int, key: string, meal: string, meal_key: string, name: string, package: string,
 *     deal: Deal|null, perks: list<string>, cancel: Cancel|null, payment: Payment|null,
 *     amount: float, price: string, was: string, off: string, per_night: string,
 *     on_request: bool, free: bool, book_url: string, chosen: bool, room_json: string,
 *     unit: float, unit_was: float, qty_price: string, qty_was: string
 * }
 * @phpstan-type RoomView array{
 *     key: string, quota: string, name: string, title: string, code: string, capacity: int, capacity_form: string,
 *     facilities: list<string>, from: float, from_text: string, cheapest: bool,
 *     on_request: bool, immediate: bool, left: int, left_form: string,
 *     single: bool, options: list<OptionView>, chosen: int,
 *     package: string, deal: Deal|null, cancel: Cancel|null, payment: Payment|null,
 *     terms: list<TermsView>, meals: string, more: bool, qty: int, qty_form: string
 * }
 * @phpstan-type ResultsView array{
 *     rate_count: int, rate_form: string, room_count: int, room_form: string,
 *     nights: int, nights_form: string, adults: int, adults_form: string,
 *     children: int, children_form: string, meals_included: bool,
 *     cheapest: string, single_meal: string,
 *     meal_plans: list<array{key: string, name: string, from: string, count: int, form: string}>,
 *     chips: list<array{key: string, count: int}>,
 *     struck: bool, struck_deal: string,
 *     rooms: list<RoomView>,
 *     more: array{count: int, names: string, from: string}|null
 * }
 */
final class RoomResultsBuilder
{
    /** Room types shown before "see all". */
    public const ROOMS_SHOWN = 4;
    /** Room names the "see all" hint lists before "…". */
    public const HINT_NAMES = 3;

    // What is known about cancelling, per option or for all of a room's.
    public const CANCEL_FREE = 'free';
    public const CANCEL_FREE_OPEN = 'free_open';
    public const CANCEL_NON_REFUNDABLE = 'non_refundable';
    // Cancelling already costs something today (no free cancellation any more): said with its amount
    public const CANCEL_PENALTY = 'penalty';

    public const CHIP_FREE = 'free';
    public const CHIP_IMMEDIATE = 'immediate';

    /** A TermsView's `for` when it holds for every option of the room. */
    public const TERMS_ALL = -1;

    /** @param \Closure(string): string $formatDate an ISO date in the store's format */
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly \Closure $formatDate,
    ) {
    }

    /** The formatter the amounts are printed with (RoomGroupsBuilder prints its totals the same way). */
    public function money(): MoneyFormatter
    {
        return $this->money;
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
     * @param int $nights the stay searched: the prices are for it and these guests
     * @return ResultsView
     */
    public function build(array $rates, int $nights, int $adults = 2, int $children = 0): array
    {
        $rates = array_values(array_filter($rates, static fn (RateOffer $r): bool => $r->price > 0));

        $byRoom = [];
        foreach ($rates as $rate) {
            $byRoom[$rate->roomKey][] = $rate;
        }
        $roomOptions = [];
        foreach ($byRoom as $roomRates) {
            $roomOptions[] = $this->options($roomRates);
        }
        usort($roomOptions, static fn (array $a, array $b): int => self::cheapestOf($a)->price <=> self::cheapestOf($b)->price);
        $all = $roomOptions === [] ? [] : array_merge(...$roomOptions);
        $total = count($all);

        $meals = $this->mealPlans($roomOptions);
        $mealKeys = array_column($meals, 'key', 'name');

        $chips = [];
        foreach ([
            self::CHIP_FREE => static fn (RateOffer $r): bool => $r->isFree(),
            self::CHIP_IMMEDIATE => static fn (RateOffer $r): bool => !$r->onRequest,
        ] as $key => $test) {
            $count = count(array_filter($all, $test));
            if ($count > 0 && $count < $total) {
                $chips[] = ['key' => $key, 'count' => $count];
            }
        }

        $struckLabels = [];
        foreach ($all as $rate) {
            if ($rate->standardPrice > $rate->price) {
                $struckLabels[$rate->discountLabel] = true;
            }
        }
        $struckDeal = count($struckLabels) === 1 ? (string) array_key_first($struckLabels) : '';

        $rooms = [];
        $used = [];
        foreach ($roomOptions as $index => $options) {
            $rooms[] = $this->room($index, $options, $mealKeys, $nights, $used);
        }

        $more = null;
        $hidden = array_slice($rooms, self::ROOMS_SHOWN);
        if ($hidden !== []) {
            $names = array_values(array_unique(array_column($hidden, 'name')));
            $more = [
                'count' => count($hidden),
                'names' => implode(', ', array_slice($names, 0, self::HINT_NAMES)) . (count($names) > self::HINT_NAMES ? '…' : ''),
                'from' => $hidden[0]['from_text'],
            ];
        }
        $cheapest = $roomOptions === [] ? null : self::cheapestOf($roomOptions[0]);

        return [
            'rate_count' => $total,
            'rate_form' => self::form($total),
            'room_count' => count($rooms),
            'room_form' => self::form(count($rooms)),
            'nights' => $nights,
            'nights_form' => self::form($nights),
            'adults' => $adults,
            'adults_form' => self::form($adults),
            'children' => $children,
            'children_form' => self::form($children),
            'meals_included' => $total > 0 && self::every($all, static fn (RateOffer $r): bool => $r->mealRank !== RateOffer::MEAL_ROOM_ONLY),
            'cheapest' => $cheapest !== null ? $this->money->format($cheapest->price) : '',
            'single_meal' => count($meals) === 1 ? $meals[0]['name'] : '',
            'meal_plans' => count($meals) > 1 ? $meals : [],
            'chips' => $chips,
            'struck' => $struckLabels !== [],
            'struck_deal' => $struckDeal,
            'rooms' => $rooms,
            'more' => $more,
        ];
    }

    /**
     * A room's options: one per meal plan and terms, the cheapest of the
     * rates that offer the same (two packages, one choice for the guest);
     * lightest meal first, then cheapest.
     *
     * @param list<RateOffer> $rates one room's
     * @return non-empty-list<RateOffer>
     */
    private function options(array $rates): array
    {
        usort($rates, static fn (RateOffer $a, RateOffer $b): int => $a->price <=> $b->price);
        $options = [];
        foreach ($rates as $rate) {
            $options[$this->signature($rate)] ??= $rate;
        }
        $options = array_values($options);
        usort($options, static fn (RateOffer $a, RateOffer $b): int => [$a->mealRank, $a->mealPlan, $a->price] <=> [$b->mealRank, $b->mealPlan, $b->price]);
        assert($options !== []);

        return $options;
    }

    /** What makes two rates of a room different choices for the guest. */
    private function signature(RateOffer $rate): string
    {
        $perks = $rate->perks;
        sort($perks);

        return serialize([$rate->mealPlan, $this->cancel($rate), $this->payment($rate), $perks, $rate->terms]);
    }

    /** @param non-empty-list<RateOffer> $options */
    private static function cheapestOf(array $options): RateOffer
    {
        $cheapest = $options[0];
        foreach ($options as $option) {
            if ($option->price < $cheapest->price) {
                $cheapest = $option;
            }
        }

        return $cheapest;
    }

    /**
     * The hotel's meal plans, lightest first, each with its lowest price and
     * how many room types have it.
     *
     * @param list<non-empty-list<RateOffer>> $roomOptions
     * @return list<array{key: string, name: string, from: string, count: int, form: string}>
     */
    private function mealPlans(array $roomOptions): array
    {
        $plans = [];
        $order = 0;
        foreach ($roomOptions as $options) {
            $seen = [];
            foreach ($options as $rate) {
                $plan = $plans[$rate->mealPlan] ?? ['rank' => $rate->mealRank, 'order' => $order++, 'min' => $rate->price, 'count' => 0];
                $plan['min'] = min($plan['min'], $rate->price);
                if (!isset($seen[$rate->mealPlan])) {
                    $seen[$rate->mealPlan] = true;
                    $plan['count']++;
                }
                $plans[$rate->mealPlan] = $plan;
            }
        }
        uasort($plans, static fn (array $a, array $b): int => [$a['rank'], $a['order']] <=> [$b['rank'], $b['order']]);

        $list = [];
        foreach ($plans as $name => $plan) {
            $list[] = [
                'key' => 'm' . substr(md5((string) $name), 0, 8),
                'name' => (string) $name,
                'from' => $this->money->format($plan['min']),
                'count' => $plan['count'],
                'form' => self::form($plan['count']),
            ];
        }

        return $list;
    }

    /**
     * @param non-empty-list<RateOffer> $options
     * @param array<string, string> $mealKeys
     * @param array<string, true> $used keys handed out so far
     * @return RoomView
     */
    private function room(int $index, array $options, array $mealKeys, int $nights, array &$used): array
    {
        $first = $options[0];
        $count = count($options);
        $cheapest = self::cheapestOf($options);
        $chosen = (int) array_search($cheapest, $options, true);
        $key = self::unique('room-' . substr(md5($first->roomKey), 0, 10), $used);

        // The package and its discount travel as one unit; shared only by
        // several options (a single option keeps its own, with the saving).
        $deals = array_map(static fn (RateOffer $r): string => serialize([$r->packageName, $r->discountLabel, $r->discountUntil]), $options);
        $sharedPackage = $count > 1 && count(array_unique($deals)) === 1;
        $cancels = array_map(fn (RateOffer $r): ?array => $this->cancel($r), $options);
        $sharedCancel = count(array_unique(array_map('serialize', $cancels))) === 1;
        $payments = array_map(fn (RateOffer $r): array => $this->payment($r), $options);
        $sharedPayment = count(array_unique(array_map('serialize', $payments))) === 1;

        $views = [];
        $optionKeys = [];
        foreach ($options as $i => $rate) {
            $views[] = $this->option(
                $i,
                $rate,
                $mealKeys,
                $nights,
                $i === $chosen,
                !$sharedPackage,
                $sharedCancel ? null : $cancels[$i],
                $sharedPayment ? null : $payments[$i],
                self::unique(substr(md5($this->signature($rate)), 0, 8), $optionKeys),
            );
        }

        $left = 0;
        foreach ($options as $rate) {
            if ($rate->roomsLeft > 0 && ($left === 0 || $rate->roomsLeft < $left)) {
                $left = $rate->roomsLeft;
            }
        }
        $onRequest = self::every($options, static fn (RateOffer $r): bool => $r->onRequest);
        $capacity = max(array_map(static fn (RateOffer $r): int => $r->capacity, $options));
        $facilities = [];
        foreach ($options as $rate) {
            if ($rate->facilities !== []) {
                $facilities = $rate->facilities;
                break;
            }
        }
        $roomMeals = [];
        foreach ($options as $rate) {
            if (isset($mealKeys[$rate->mealPlan])) {
                $roomMeals[$mealKeys[$rate->mealPlan]] = true;
            }
        }

        return [
            'key' => $key,
            // The room type across the groups of a search of several rooms (RoomGroupsBuilder): what one group
            // takes of it, the others have fewer of.
            'quota' => substr(md5($first->roomKey), 0, 10),
            'name' => $first->roomName,
            'title' => $first->roomTitle !== '' ? $first->roomTitle : $first->roomName,
            'code' => $first->roomCode,
            'capacity' => $capacity,
            'capacity_form' => self::form($capacity),
            'facilities' => $facilities,
            'from' => $cheapest->price,
            'from_text' => $this->money->format($cheapest->price),
            'cheapest' => $index === 0,
            'on_request' => $onRequest,
            'immediate' => self::every($options, static fn (RateOffer $r): bool => !$r->onRequest),
            'left' => $onRequest ? 0 : $left,
            'left_form' => self::form($left),
            'single' => $count === 1,
            'options' => $views,
            'chosen' => $chosen,
            'package' => $sharedPackage ? $first->packageName : '',
            'deal' => $sharedPackage ? $this->deal($first, false) : null,
            'cancel' => $sharedCancel ? $cancels[0] : null,
            'payment' => $sharedPayment ? $payments[0] : null,
            'terms' => $this->terms($options),
            'meals' => implode(' ', array_keys($roomMeals)),
            'more' => $index >= self::ROOMS_SHOWN,
            // How many rooms the till books: one, or a group's (RoomGroupsBuilder)
            'qty' => 1,
            'qty_form' => self::form(1),
        ];
    }

    /**
     * @param array<string, string> $mealKeys
     * @param Cancel|null $cancel the option's own, when the room's options differ
     * @param Payment|null $payment the option's own, when the room's options differ
     * @return OptionView
     */
    private function option(
        int $index,
        RateOffer $rate,
        array $mealKeys,
        int $nights,
        bool $chosen,
        bool $ownPackage,
        ?array $cancel,
        ?array $payment,
        string $key,
    ): array {
        $struck = $rate->standardPrice > $rate->price;
        $percent = $struck ? (int) round(($rate->standardPrice - $rate->price) / $rate->standardPrice * 100) : 0;

        return [
            'index' => $index,
            'key' => $key,
            'meal' => $rate->mealPlan,
            'meal_key' => $mealKeys[$rate->mealPlan] ?? '',
            'name' => $rate->rateName,
            'package' => $ownPackage ? $rate->packageName : '',
            'deal' => $ownPackage ? $this->deal($rate, true) : null,
            'perks' => $rate->perks,
            'cancel' => $cancel,
            'payment' => $payment,
            'amount' => $rate->price,
            'price' => $this->money->format($rate->price),
            'was' => $struck ? $this->money->format($rate->standardPrice) : '',
            'off' => $percent > 0 ? '−' . $percent . '%' : '',
            'per_night' => $nights > 0 ? $this->money->formatDisplay($this->money->toDisplay($rate->price) / $nights) : '',
            'on_request' => $rate->onRequest,
            'free' => $rate->isFree(),
            'book_url' => $rate->bookUrl,
            'chosen' => $chosen,
            'room_json' => $rate->roomData === [] ? '' : (string) json_encode($rate->roomData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // One room's price and struck price in the shopper's currency, for a page that adds them up (several rooms),
            // and the till's total and struck total for the rooms it books (the room's qty; RoomGroupsBuilder sets them)
            'unit' => $this->money->toDisplay($rate->price),
            'unit_was' => $struck ? $this->money->toDisplay($rate->standardPrice) : 0.0,
            'qty_price' => $this->money->format($rate->price),
            'qty_was' => $struck ? $this->money->format($rate->standardPrice) : '',
        ];
    }

    /**
     * The discount's line: its name, until when, and (for one option) how
     * much it saves; null without a named discount.
     *
     * @return Deal|null
     */
    private function deal(RateOffer $rate, bool $withSaving): ?array
    {
        if ($rate->discountLabel === '') {
            return null;
        }

        return [
            'name' => $rate->discountLabel,
            'until' => $rate->discountUntil !== '' ? ($this->formatDate)($rate->discountUntil) : '',
            'saving' => $withSaving && $rate->standardPrice > $rate->price ? $this->money->format($rate->standardPrice - $rate->price) : '',
        ];
    }

    /**
     * What the card says on cancelling, from the period holding today
     * (TermsTimelineFactory's current step, when the rate has a timeline):
     * free until a day (its hour and "today" / "tomorrow" when the provider
     * gives them), already costing something today (with the amount),
     * non-refundable, or free with the day in the terms.
     *
     * @return Cancel|null null = nothing known before the terms
     */
    private function cancel(RateOffer $rate): ?array
    {
        $now = self::currentStep($rate->timeline);
        if ($rate->nonRefundable) {
            return self::cancelLine(self::CANCEL_NON_REFUNDABLE);
        }
        if ($rate->freeCancellationUntil !== '') {
            return self::cancelLine(self::CANCEL_FREE, [
                'until' => ($this->formatDate)($rate->freeCancellationUntil),
                'time' => TypeCoerce::toString($now['to_time'] ?? ''),
                'rel' => TypeCoerce::toString($now['to_rel'] ?? ''),
            ]);
        }
        if ($now !== null && ($now['kind'] ?? '') === 'partial' && TypeCoerce::toString($now['amount_label'] ?? '') !== '') {
            return self::cancelLine(self::CANCEL_PENALTY, [
                'amount' => TypeCoerce::toString($now['amount_label']),
                'nights' => TypeCoerce::toInt($now['nights'] ?? 0),
                'approx' => ($now['approx'] ?? false) === true,
                'percent' => TypeCoerce::toString($now['percent_label'] ?? ''),
            ]);
        }

        return $rate->freeCancellation ? self::cancelLine(self::CANCEL_FREE_OPEN) : null;
    }

    /**
     * @param array{until?: string, time?: string, rel?: string, amount?: string, nights?: int, approx?: bool, percent?: string} $set
     * @return Cancel
     */
    private static function cancelLine(string $kind, array $set = []): array
    {
        return [
            'kind' => $kind,
            'until' => $set['until'] ?? '',
            'time' => $set['time'] ?? '',
            'rel' => $set['rel'] ?? '',
            'amount' => $set['amount'] ?? '',
            'nights' => $set['nights'] ?? 0,
            'approx' => $set['approx'] ?? false,
            'percent' => $set['percent'] ?? '',
        ];
    }

    /**
     * The cancelling period holding today, from a rate's structured terms.
     *
     * @param array<string, mixed> $timeline
     * @return array<array-key, mixed>|null
     */
    private static function currentStep(array $timeline): ?array
    {
        $steps = $timeline['cancel_steps'] ?? [];
        foreach (is_array($steps) ? $steps : [] as $step) {
            if (is_array($step) && ($step['is_current'] ?? false) === true && ($step['is_no_show'] ?? false) !== true) {
                return $step;
            }
        }

        return null;
    }

    /** @return Payment */
    private function payment(RateOffer $rate): array
    {
        $deposit = !$rate->onRequest && $rate->depositRatio > 0 && $rate->depositRatio < 1 && $rate->balanceDue !== '';

        return [
            'on_request' => $rate->onRequest,
            'deposit_percent' => $deposit ? (int) round($rate->depositRatio * 100) : 0,
            'balance_due' => $deposit ? ($this->formatDate)($rate->balanceDue) : '',
            'text' => $rate->onRequest ? '' : $rate->paymentText,
        ];
    }

    /**
     * The room's terms link: one set when every option has the same, else
     * the chosen option's (each option's set, `for` its index).
     *
     * @param non-empty-list<RateOffer> $options
     * @return list<TermsView>
     */
    private function terms(array $options): array
    {
        $sets = [];
        foreach ($options as $i => $rate) {
            $sets[$i] = ['for' => $i, 'terms' => $rate->terms, 'timeline' => $rate->timeline, 'url' => $rate->termsUrl];
        }
        $same = count(array_unique(array_map(
            static fn (array $set): string => serialize([$set['terms'], $set['timeline'], $set['url']]),
            $sets,
        ))) === 1;
        if ($same) {
            $sets = [['for' => self::TERMS_ALL] + $sets[0]];
        }

        return array_values(array_filter(
            $sets,
            static fn (array $set): bool => $set['terms'] !== [] || $set['timeline'] !== [] || $set['url'] !== '',
        ));
    }

    /** @param array<string, true> $used */
    private static function unique(string $key, array &$used): string
    {
        $candidate = $key;
        for ($n = 2; isset($used[$candidate]); $n++) {
            $candidate = $key . '-' . $n;
        }
        $used[$candidate] = true;

        return $candidate;
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
