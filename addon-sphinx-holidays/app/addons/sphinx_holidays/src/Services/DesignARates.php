<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Results\DesignA;
use Tygh\Addons\TravelCore\Results\MealRank;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\Services\DateHelper;

/**
 * Sphinx's offers for one hotel → the rates of the Design A room cards
 * (travel_core Results\RateOffer; docs/hotel-page-design-a-plan.md), for a
 * one-room search. The rows are the search's flat offers
 * (SearchOfferNormalizer, commission applied) as search.php and the poll
 * keep them — cached, or gathered poll by poll.
 *
 *  - the room as Sphinx names it ("Twin Room with Sea View"); its code is a
 *    number, so none shows;
 *  - the meal plan as sent, ranked by name (MealRank), an all-capitals one
 *    ("ULTRA ALL INCLUSIVE") in title case; none = room only;
 *  - the price in the store's primary currency, as the booking form and the
 *    cart convert it; the marketing price (before the discount, commission
 *    applied) struck, the offer's labels ("Early Booking") naming it;
 *  - confirmation "on_request" = on request; cancellation_fees.is_free =
 *    free cancellation, its last day only in the terms, which the card loads
 *    when opened (sphinx_booking.offer_terms, design=a);
 *  - "Rezervă" goes to the booking form exactly as today's card.
 */
final class DesignARates
{
    /**
     * @param \Closure(float, string): float $toPrimary an amount in an API currency, in the store's primary currency
     * @param \Closure(string): string $url a storefront URL (fn_url)
     * @param array<string, mixed> $search the search: hotel_id, product_id, check_in, check_out, adults, children, children_ages, rooms
     */
    public function __construct(
        private readonly \Closure $toPrimary,
        private readonly \Closure $url,
        private readonly string $roomOnly,
        private readonly array $search,
    ) {
    }

    /**
     * The store's: the API currency converted as the cart converts it, fn_url.
     *
     * @param array<string, mixed> $search
     */
    public static function forStore(array $search): self
    {
        $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';
        $default = ConfigProvider::getDefaultCurrency();

        return new self(
            static fn (float $amount, string $currency): float => (new CurrencyService($currency !== '' ? $currency : $default))
                ->convertFromApiCurrency($amount, $primary),
            static fn (string $url): string => TypeCoerce::toString(fn_url($url)),
            TypeCoerce::toString(__('travel_core.pdpa_r_room_only')),
            $search,
        );
    }

    /**
     * The Design A results view of a one-room search (design_a_results.tpl);
     * [] when no offer makes a room card (the page keeps its no-results).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public static function view(array $rows, array $search): array
    {
        $rates = self::forStore($search)->rates($rows);
        if ($rates === []) {
            return [];
        }
        $nights = DateHelper::calculateNights(TypeCoerce::toString($search['check_in'] ?? ''), TypeCoerce::toString($search['check_out'] ?? ''));

        return DesignA::builder()->build(
            $rates,
            max(1, $nights),
            max(1, TypeCoerce::toInt($search['adults'] ?? 2)),
            max(0, TypeCoerce::toInt($search['children'] ?? 0)),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<RateOffer>
     */
    public function rates(array $rows): array
    {
        $rates = [];
        foreach ($rows as $row) {
            $rate = $this->rate($row);
            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /** @param array<string, mixed> $row */
    private function rate(array $row): ?RateOffer
    {
        $offerId = TypeCoerce::toString($row['offer_id'] ?? '');
        $roomName = trim(TypeCoerce::toString($row['room_name'] ?? $row['room_type'] ?? ''));
        $price = TypeCoerce::toFloat($row['price'] ?? 0);
        if ($offerId === '' || $roomName === '' || $price <= 0) {
            return null;
        }
        $currency = TypeCoerce::toString($row['currency'] ?? '');
        $board = trim(TypeCoerce::toString($row['board_name'] ?? $row['board_type'] ?? ''));
        $meal = $board === '' ? $this->roomOnly : self::readable($board);
        $marketing = TypeCoerce::toFloat($row['marketing_price'] ?? 0);

        return new RateOffer(
            roomKey: TypeCoerce::toString($row['room_code'] ?? '') . '|' . mb_strtolower($roomName),
            roomName: $roomName,
            mealPlan: $meal,
            price: ($this->toPrimary)($price, $currency),
            bookUrl: ($this->url)('sphinx_booking.booking_form?' . http_build_query([
                'offer_id' => $offerId,
                'hotel_id' => TypeCoerce::toString($row['hotel_id'] ?? $this->search['hotel_id'] ?? ''),
                'product_id' => TypeCoerce::toInt($this->search['product_id'] ?? 0) ?: TypeCoerce::toInt($row['product_id'] ?? 0),
                'check_in' => TypeCoerce::toString($this->search['check_in'] ?? ''),
                'check_out' => TypeCoerce::toString($this->search['check_out'] ?? ''),
                'adults' => TypeCoerce::toInt($this->search['adults'] ?? 2),
                'children' => TypeCoerce::toInt($this->search['children'] ?? 0),
                'children_ages' => TypeCoerce::toString($this->search['children_ages'] ?? ''),
                'rooms' => TypeCoerce::toInt($this->search['rooms'] ?? 1),
                'room_name' => $roomName,
                'board_name' => $board,
            ])),
            mealRank: $board === '' ? RateOffer::MEAL_ROOM_ONLY : MealRank::of($board),
            standardPrice: $marketing > $price ? ($this->toPrimary)($marketing, $currency) : 0.0,
            discountLabel: TypeCoerce::toString($row['deal_label'] ?? ''),
            onRequest: TypeCoerce::toString($row['confirmation'] ?? '') === 'on_request',
            freeCancellation: TypeCoerce::toBool($row['free_cancellation'] ?? false),
            termsUrl: ($this->url)('sphinx_booking.offer_terms?' . http_build_query([
                'offer_id' => $offerId,
                DesignA::PARAM => DesignA::VALUE,
            ])),
        );
    }

    /**
     * The terms a card loads when opened: offer_terms' own answer (the
     * verify's payment and cancellation lines, the free-cancellation fact,
     * a partial answer from the search, an outage) as sections of lines.
     *
     * @param array<array-key, mixed> $answer offer_terms' JSON answer
     * @param array{payment: string, cancellation: string, free: string, free_until: string, none: string, outage: string, unavailable: string} $texts
     * @return list<array{title: string, lines: list<string>}>
     */
    public static function termsSections(array $answer, array $texts): array
    {
        $status = TypeCoerce::toString($answer['status'] ?? '');
        if ($status === 'outage' || $status === 'unavailable') {
            return [['title' => '', 'lines' => [$texts[$status]]]];
        }
        if ($status !== 'ok' && $status !== 'partial') {
            return [];
        }

        $cancel = [];
        $freeUntil = TypeCoerce::toString($answer['free_until'] ?? '');
        if ($freeUntil !== '') {
            $cancel[] = $texts['free_until'] . ' ' . $freeUntil;
        } elseif (TypeCoerce::toBool($answer['is_free'] ?? false)) {
            $cancel[] = $texts['free'];
        }
        $cancel = [...$cancel, ...TypeCoerce::toStringList($answer['cancellation_fees'] ?? [])];
        $payment = TypeCoerce::toStringList($answer['payment_terms'] ?? []);
        $notice = TypeCoerce::toString($answer['notice'] ?? '');

        $sections = [];
        if ($payment !== []) {
            $sections[] = ['title' => $texts['payment'], 'lines' => $payment];
        }
        if ($cancel !== [] || $notice !== '') {
            $sections[] = ['title' => $texts['cancellation'], 'lines' => $notice !== '' ? [...$cancel, $notice] : $cancel];
        }

        return $sections !== [] ? $sections : [['title' => '', 'lines' => [$texts['none']]]];
    }

    /** "ULTRA ALL INCLUSIVE" → "Ultra All Inclusive"; a name in mixed case stays as sent. */
    private static function readable(string $name): string
    {
        return mb_strtoupper($name) === $name && mb_strtolower($name) !== $name
            ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE)
            : $name;
    }
}
