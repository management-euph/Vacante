<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * The booking card under a travel line of an order — the admin order page
 * (components/order_booking_card.tpl, backend) and the customer's order page
 * (components/cart_booking_details.tpl, "order" form).
 *
 * The stay itself (dates, rooms, board, guests, the cancellation headline and
 * timeline, the deposit split) is the checkout card's (CartBookingCardFactory),
 * so a customer sees after ordering exactly what they confirmed. On top of it:
 *
 *  - the booking's status (travel_bookings) and what kind of trip it is
 *    (hotel, circuit, flight/bus + hotel package), with circuit dates read as
 *    departure / return and the supplier's own count of days;
 *  - the terms read as an order's, not a checkout's: an instalment without a
 *    date was due on booking (not "now"), and a cancelled, completed or
 *    started trip has no cancellation headline (nor, cancelled, a balance
 *    to pay);
 *  - every room as its own block with its board, occupancy and guests;
 *  - the balance of a deposit order: open / paid / overdue, its pay link;
 *  - for the ADMIN only: the provider, the supplier reference, the supplier's
 *    note and price, when it was booked / updated, and an alert with the
 *    supplier's answer when the booking failed or was never sent.
 *
 * The customer card is built without any of the admin-only data — it never
 * reaches the template — and a booking the supplier has not confirmed reads
 * "awaiting confirmation" whatever went wrong behind it.
 *
 * Pure: the formatter, today, the date format and every looked-up record are
 * passed in (functions/order_card.php gathers them).
 */
final class OrderBookingCardFactory
{
    public const AUDIENCE_ADMIN = 'admin';
    public const AUDIENCE_CUSTOMER = 'customer';

    public const STATUS_NOT_SENT = 'not_sent';

    public const TONE_OK = 'ok';
    public const TONE_WARN = 'warn';
    public const TONE_BAD = 'bad';
    public const TONE_MUTED = 'muted';

    public const KIND_HOTEL = 'hotel';
    public const KIND_CIRCUIT = 'circuit';
    public const KIND_PACKAGE = 'package';

    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly string $today,
        private readonly string $dateFormat = '%d.%m.%Y',
    ) {
    }

    /**
     * @param array<string, mixed> $item order item: product_id, product, price, amount, extra
     * @param string $audience self::AUDIENCE_ADMIN | self::AUDIENCE_CUSTOMER
     * @param array<string, mixed> $booking its ?:travel_bookings row ([] when none)
     * @param array<string, mixed> $facts TravelProviderRegistry::orderCardFacts() of the line
     * @param array<string, mixed> $meta what the gatherer formatted: provider_name, supplier_price,
     *                                   actions (list of {label, href, confirm})
     * @param array<string, mixed> $terms TravelProviderRegistry::cartTerms() of the line
     * @param list<array<string, mixed>> $balances the order's travel_balances rows (BalanceService::forOrder)
     * @return array<string, mixed> empty when the line is not a travel booking
     */
    public function build(
        array $item,
        string $audience,
        array $booking = [],
        array $facts = [],
        array $meta = [],
        array $terms = [],
        array $balances = [],
        ?HotelSeoData $hotel = null,
    ): array {
        $card = (new CartBookingCardFactory($this->money, $this->today, $this->dateFormat))
            ->build($item, '', $hotel, [], $terms);
        if ($card === []) {
            return [];
        }
        $admin = $audience === self::AUDIENCE_ADMIN;
        $extra = TypeCoerce::toStringMap($item['extra'] ?? null);

        $kind = self::kind($facts, $extra);
        $days = 0;
        if ($kind === self::KIND_CIRCUIT) {
            [$return, $days] = self::circuitSpan($extra, TypeCoerce::toInt($card['nights']));
            if ($return !== '') {
                $card['check_out'] = $this->date($return);
            }
        }

        $status = $this->status($booking, $facts, $admin);
        $card = $this->orderTerms($card, $status['code'], $admin, TypeCoerce::toString($extra['check_in'] ?? ''));

        $card['audience'] = $admin ? self::AUDIENCE_ADMIN : self::AUDIENCE_CUSTOMER;
        // A placed order is not edited from here, and its price is settled.
        $card['edit_url'] = '';
        $card['price_change'] = [];
        $card['status'] = $status;
        $card['kind'] = $kind;
        $card['transport'] = self::word($facts['transport'] ?? '');
        $card['days'] = $days;
        // A circuit's meal plan, when the provider stored it. Circuit lines
        // from before carried the transport ("Bus") as their board.
        $meals = trim(TypeCoerce::toString($facts['meals'] ?? ''));
        if ($meals !== '') {
            $card['board'] = $meals;
        } elseif ($kind === self::KIND_CIRCUIT && $card['transport'] !== '' && strcasecmp(TypeCoerce::toString($card['board']), $card['transport']) === 0) {
            $card['board'] = '';
        }
        $card['departure'] = trim(TypeCoerce::toString($facts['departure'] ?? ''));
        // The offer the hotel was sold in (novoton: "ADMIRAL ***** +BEACH").
        $card['package'] = trim(TypeCoerce::toString($facts['package'] ?? ''));
        $card['services'] = self::lines($facts['services'] ?? null);
        // rooms_data prices are the supplier's, in its currency: never shown.
        $card['room_list'] = array_map(
            static fn (array $room): array => ['price' => ''] + $room,
            TypeCoerce::toRowList($card['room_list'] ?? null),
        );
        // One lead guest for the whole booking, marked in its room too.
        $card['guests'] = self::oneLead(TypeCoerce::toRowList($card['guests'] ?? null), TypeCoerce::toString($extra['holder_name'] ?? ''));
        $lead = [];
        foreach ($card['guests'] as $guest) {
            if ($guest['is_holder'] === true) {
                $lead = $guest;
                $card['lead_guest'] = TypeCoerce::toString($guest['name'] ?? '');
                break;
            }
        }
        $card['room_list'] = array_map(static function (array $room) use ($lead): array {
            $guests = TypeCoerce::toRowList($room['guests'] ?? null);
            foreach ($guests as $i => $guest) {
                $guests[$i]['is_holder'] = $lead !== []
                    && TypeCoerce::toString($guest['name'] ?? '') === TypeCoerce::toString($lead['name'] ?? '')
                    && TypeCoerce::toInt($guest['room'] ?? 0) === TypeCoerce::toInt($lead['room'] ?? 0);
            }

            return ['guests' => $guests] + $room;
        }, $card['room_list']);
        $card['room_cards'] = $this->roomCards($card);
        $card['balance'] = $this->balance($card['deposit'], $extra, $balances, TypeCoerce::toString($item['item_id'] ?? ''), $status['code'], $admin);

        // Admin only — never handed to the customer's template.
        $card['provider'] = $admin ? [
            'code' => TypeCoerce::toString($facts['provider'] ?? $booking['provider'] ?? ''),
            'name' => TypeCoerce::toString($meta['provider_name'] ?? ''),
        ] : [];
        $card['reference'] = $admin ? trim(TypeCoerce::toString($facts['reference'] ?? '')) : '';
        $card['our_reference'] = $admin ? trim(TypeCoerce::toString($facts['our_reference'] ?? '')) : '';
        $card['booking_id'] = $admin ? max(0, TypeCoerce::toInt($booking['booking_id'] ?? $extra['travel_surrogate_id'] ?? 0)) : 0;
        $card['note'] = $admin ? trim(TypeCoerce::toString($facts['note'] ?? '')) : '';
        $card['supplier_price'] = $admin ? TypeCoerce::toString($meta['supplier_price'] ?? '') : '';
        $card['booked_at'] = $admin ? $this->stamp($booking['created_at'] ?? null) : '';
        $card['updated_at'] = $admin ? $this->stamp($booking['updated_at'] ?? null) : '';
        $card['alert'] = $admin ? $this->alert($status['code'], $facts, $meta) : [];

        return $card;
    }

    /**
     * The status pill. The admin sees the booking's own status (and "not
     * sent" when the provider says it never left the store); the customer
     * sees whether it is confirmed, cancelled, or still awaiting it.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $facts
     * @return array{code: string, tone: string}
     */
    public function status(array $booking, array $facts, bool $admin): array
    {
        $code = strtolower(trim(TypeCoerce::toString($booking['status'] ?? $facts['status'] ?? '')));
        if (!empty($facts['not_sent']) && in_array($code, ['', TravelConstants::STATUS_PENDING], true)) {
            $code = self::STATUS_NOT_SENT;
        }
        if (!$admin) {
            $code = match ($code) {
                '' => '',
                TravelConstants::STATUS_CONFIRMED, TravelConstants::STATUS_COMPLETED, TravelConstants::STATUS_CANCELLED => $code,
                default => TravelConstants::STATUS_PENDING,
            };
        }

        return ['code' => $code, 'tone' => match ($code) {
            '' => '',
            TravelConstants::STATUS_CONFIRMED, TravelConstants::STATUS_COMPLETED => self::TONE_OK,
            TravelConstants::STATUS_FAILED, self::STATUS_NOT_SENT => self::TONE_BAD,
            TravelConstants::STATUS_CANCELLED => self::TONE_MUTED,
            default => self::TONE_WARN,
        }];
    }

    /**
     * hotel | circuit | package: the provider says so; a line from before it
     * did carries sphinx's own booking_type.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $extra
     */
    private static function kind(array $facts, array $extra): string
    {
        $kind = strtolower(TypeCoerce::toString($facts['kind'] ?? $extra['booking_type'] ?? ''));

        return in_array($kind, [self::KIND_CIRCUIT, self::KIND_PACKAGE], true) ? $kind : self::KIND_HOTEL;
    }

    /**
     * A circuit's return date and its length in days. The supplier gives
     * days and nights apart (9 days / 6 nights when legs run overnight), so
     * a line keeps its duration_days and the trip ends on its last day,
     * departure + days - 1. Lines from before stored departure + days as
     * their check-out (some, briefly, departure + nights): at least nights + 1.
     *
     * @param array<string, mixed> $extra
     * @return array{0: string, 1: int} [return date (ISO) or '', days or 0]
     */
    private static function circuitSpan(array $extra, int $nights): array
    {
        $departure = DateHelper::parseDate(TypeCoerce::toString($extra['check_in'] ?? ''));
        if ($departure === null) {
            return ['', 0];
        }
        $days = TypeCoerce::toInt($extra['duration_days'] ?? 0);
        if ($days <= 0) {
            $stored = DateHelper::parseDate(TypeCoerce::toString($extra['check_out'] ?? ''));
            $span = $stored !== null ? DateHelper::calculateNights($departure, $stored) : 0;
            $days = $nights > 0 ? max($span, $nights + 1) : $span;
        }

        return $days > 0 ? [DateHelper::getCheckOutDate($departure, $days - 1), $days] : ['', 0];
    }

    /**
     * The checkout's terms, read for a placed order. Its payment steps say
     * "now" for an instalment without a date or one already past — on an
     * order those were due on booking, and a past one was paid or is owed by
     * its date: each step keeps its date, the undated ones read "on booking".
     * The cancellation headline ("free until …", "cancelling now costs …")
     * is for a trip still ahead: none once the booking is cancelled or
     * completed or the stay has begun; the customer of a cancelled booking
     * gets no timeline either.
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function orderTerms(array $card, string $status, bool $admin, string $checkIn): array
    {
        $terms = TypeCoerce::toStringMap($card['terms'] ?? null);
        $steps = array_map(static function (array $step): array {
            $step['is_now'] = false;
            $step['at_booking'] = TypeCoerce::toString($step['due_iso'] ?? '') === '';

            return $step;
        }, TypeCoerce::toRowList($terms['payment_steps'] ?? null));
        usort($steps, static fn (array $a, array $b): int => [$a['at_booking'] ? 0 : 1, TypeCoerce::toString($a['due_iso'] ?? '')]
            <=> [$b['at_booking'] ? 0 : 1, TypeCoerce::toString($b['due_iso'] ?? '')]);
        $terms['payment_steps'] = $steps;
        $card['terms'] = $terms;

        $start = DateHelper::parseDate($checkIn);
        $over = in_array($status, [TravelConstants::STATUS_CANCELLED, TravelConstants::STATUS_COMPLETED], true)
            || ($start !== null && $start <= $this->today);
        if ($over) {
            $card['cancel'] = ['state' => '', 'free_until' => '', 'now' => [], 'then' => []];
        }
        if (!$admin && $status === TravelConstants::STATUS_CANCELLED) {
            $card['has_terms'] = false;
        }

        return $card;
    }

    /**
     * Every room as a block of its own, also a single one: name, supplier
     * code, board, occupancy and its guests (lead guest marked).
     *
     * @param array<string, mixed> $card the checkout card
     * @return list<array<string, mixed>>
     */
    private function roomCards(array $card): array
    {
        $board = TypeCoerce::toString($card['board'] ?? '');
        $rooms = TypeCoerce::toRowList($card['room_list'] ?? null);
        if ($rooms !== []) {
            return array_map(static function (array $room) use ($board): array {
                // eurosite and sphinx circuits store the board for the stay only.
                if (TypeCoerce::toString($room['board'] ?? '') === '') {
                    $room['board'] = $board;
                }

                return $room;
            }, $rooms);
        }
        $room = TypeCoerce::toStringMap($card['room'] ?? null);

        return [[
            'number' => 1,
            'name' => TypeCoerce::toString($room['name'] ?? ''),
            'code' => TypeCoerce::toString($room['code'] ?? ''),
            'price' => '',
            'adults' => TypeCoerce::toInt($card['adults'] ?? 0),
            'children' => TypeCoerce::toInt($card['children'] ?? 0),
            'children_ages' => TypeCoerce::toString($card['children_ages'] ?? ''),
            'board' => $board,
            'guests' => TypeCoerce::toRowList($card['guests'] ?? null),
        ]];
    }

    /**
     * The balance still owed on a deposit order: its ?:travel_balances row is
     * the one of this order item, else (rows written before item_id) the one
     * with this line's due date. A cancelled booking's balance is not paid:
     * no pay link, and the customer reads it as cancelled even while the row
     * stays open (only an order's own cancellation closes it). The reminders
     * are the day marks it was sent at ("7,2").
     *
     * @param mixed $deposit the checkout card's deposit block
     * @param array<string, mixed> $extra
     * @param list<array<string, mixed>> $balances
     * @return array{state: string, amount: string, due: string, pay_query: string, reminders: int, balance_order_id: int}|array{}
     */
    private function balance(mixed $deposit, array $extra, array $balances, string $itemId, string $status, bool $admin): array
    {
        $deposit = TypeCoerce::toStringMap($deposit);
        if ($deposit === []) {
            return [];
        }
        $plan = TypeCoerce::toStringMap($extra['travel_deposit'] ?? null);
        $dueIso = TypeCoerce::toString($plan['balance_due'] ?? '');
        $row = [];
        foreach ($balances as $b) {
            if ($itemId !== '' && TypeCoerce::toString($b['item_id'] ?? '') === $itemId) {
                $row = $b;
                break;
            }
        }
        foreach ($row === [] ? $balances : [] as $b) {
            if (TypeCoerce::toString($b['due_date'] ?? '') === $dueIso) {
                $row = $b;
                break;
            }
        }
        $state = strtolower(TypeCoerce::toString($row['status'] ?? 'open'));
        if ($state === 'open' && (!empty($row['overdue_notified_at']) || ($dueIso !== '' && $dueIso < $this->today))) {
            $state = 'overdue';
        }
        $state = in_array($state, ['open', 'overdue', 'paid', 'cancelled'], true) ? $state : 'open';
        $payable = in_array($state, ['open', 'overdue'], true);
        if ($status === TravelConstants::STATUS_CANCELLED) {
            if ($payable && !$admin) {
                $state = 'cancelled';
            }
            $payable = false;
        }
        $marks = array_filter(
            array_map('trim', explode(',', TypeCoerce::toString($row['reminders_sent'] ?? ''))),
            static fn (string $mark): bool => $mark !== '',
        );

        return [
            'state' => $state,
            'amount' => TypeCoerce::toString($deposit['balance'] ?? ''),
            'due' => TypeCoerce::toString($deposit['balance_due'] ?? ''),
            'pay_query' => $payable ? TypeCoerce::toString($row['pay_query'] ?? '') : '',
            'reminders' => count($marks),
            'balance_order_id' => max(0, TypeCoerce::toInt($row['balance_order_id'] ?? 0)),
        ];
    }

    /**
     * The admin alert: a failed booking, or one that never reached the
     * supplier — with the supplier's own answer and the provider's remedies.
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $meta
     * @return array{kind: string, error: string, actions: list<array<string, mixed>>}|array{}
     */
    private function alert(string $status, array $facts, array $meta): array
    {
        if (!in_array($status, [TravelConstants::STATUS_FAILED, self::STATUS_NOT_SENT], true)) {
            return [];
        }

        return [
            'kind' => $status,
            'error' => trim(TypeCoerce::toString($facts['error'] ?? '')),
            'actions' => TypeCoerce::toRowList($meta['actions'] ?? null),
        ];
    }

    /**
     * One lead guest. The order's guest list marks the first adult AND anyone
     * whose name contains the holder's, so a booking could show two "lead"
     * guests, or the wrong one: the guest named exactly like the holder wins
     * (word order and punctuation aside), else the first one marked.
     *
     * @param list<array<string, mixed>> $guests
     * @return list<array<string, mixed>>
     */
    public static function oneLead(array $guests, string $holderName): array
    {
        $key = static function (string $name): string {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
            $words = is_array($words) ? $words : [];
            sort($words);

            return implode(' ', $words);
        };
        $holder = $key($holderName);
        $lead = null;
        foreach ($guests as $i => $guest) {
            if ($holder !== '' && $key(TypeCoerce::toString($guest['name'] ?? '')) === $holder) {
                $lead = $i;
                break;
            }
        }
        if ($lead === null) {
            foreach ($guests as $i => $guest) {
                if (!empty($guest['is_holder'])) {
                    $lead = $i;
                    break;
                }
            }
        }
        foreach ($guests as $i => $guest) {
            $guests[$i]['is_holder'] = $i === $lead;
        }

        return $guests;
    }

    /** "2026-10-06 14:33:00" → "06.10.2026 14:33" */
    private function stamp(mixed $value): string
    {
        $ts = DateHelper::toTimestamp(TypeCoerce::toString($value));

        return $ts !== null && $ts > 0 ? DateHelper::formatWith($ts, $this->dateFormat . ' %H:%M') : '';
    }

    /** @return array{date: string, weekday: string} */
    private function date(string $iso): array
    {
        $ts = DateHelper::toTimestamp($iso);

        return $ts === null
            ? ['date' => $iso, 'weekday' => '']
            : ['date' => DateHelper::formatWith($ts, $this->dateFormat), 'weekday' => DateHelper::formatWith($ts, '%A')];
    }

    /** A lower-case identifier ("flight", "bus") or ''. */
    private static function word(mixed $value): string
    {
        $word = strtolower(trim(TypeCoerce::toString($value)));

        return preg_match('/^[a-z_]{1,20}$/', $word) === 1 ? $word : '';
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        return array_values(array_filter(
            array_map('trim', TypeCoerce::toStringList(is_array($value) ? $value : [])),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
