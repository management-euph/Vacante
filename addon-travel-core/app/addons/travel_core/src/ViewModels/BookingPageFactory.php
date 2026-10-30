<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * The admin's page for one travel booking (travel_bookings.view), for every
 * provider.
 *
 * The stay reads as on the order page: the booking card of its order line
 * (OrderBookingCardFactory, admin) gives the dates with weekdays, each room
 * with its guests and its name at the supplier, the package and the
 * cancellation and payment terms. Around it the page adds:
 *
 *  - a head: the status, the provider, the order and when the booking was
 *    made, with the actions that fit the state and one of them primary
 *    (Check status while the supplier has not confirmed; the provider's own
 *    remedy when the booking failed or was never sent);
 *  - a banner saying what needs attention, and how close check-in is;
 *  - key facts at a glance: dates, nights, guests, the totals;
 *  - the supplier's side (status, reference, price, and the provider's own
 *    fields that hold a value), the order, and the booking's history;
 *  - every raw provider field, folded away, for troubleshooting.
 *
 * A booking no order holds yet (still in a cart) has no card: its stay comes
 * from its ?:travel_bookings row, without terms.
 *
 * Pure: today, the date format and every looked-up record are passed in
 * (controllers/backend/travel_bookings.php gathers them).
 */
final class BookingPageFactory
{
    public const BANNER_AWAITING = 'awaiting';
    public const BANNER_FAILED = 'failed';
    public const BANNER_NOT_SENT = 'not_sent';

    /** Provider display fields the page already shows in their own place. */
    private const array SHOWN_ELSEWHERE = ['status_label', 'provider_ref', 'supplier_rooms'];

    /** Statuses after which the supplier has nothing left to answer. */
    private const array SETTLED = [
        TravelConstants::STATUS_CONFIRMED,
        TravelConstants::STATUS_COMPLETED,
        TravelConstants::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly string $today,
        private readonly string $dateFormat = '%d.%m.%Y',
    ) {
    }

    /**
     * $booking is the ?:travel_bookings row as enriched for the page
     * (provider_display, provider_actions, hotel_product_url, guests_decoded,
     * total_label: its total, formatted); $card the admin booking card of its
     * order line and $order its order, formatted ({id, total, placed,
     * placed_ts}) — both [] when no order holds it.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $card
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function build(array $booking, array $card, array $order, string $providerName): array
    {
        $id = TypeCoerce::toInt($booking['booking_id'] ?? 0);
        $provider = TypeCoerce::toString($booking['provider'] ?? '');
        $display = TypeCoerce::toStringMap($booking['provider_display'] ?? null);
        $hasCard = $card !== [];
        $status = $hasCard
            ? self::statusOf($card)
            : (new OrderBookingCardFactory($this->money, $this->today, $this->dateFormat))->status($booking, [], true);
        $reference = trim(TypeCoerce::toString($hasCard ? ($card['reference'] ?? '') : ($display['provider_ref'] ?? '')));
        $orderId = TypeCoerce::toInt($order['id'] ?? $booking['order_id'] ?? 0);
        $banner = $this->banner($status['code'], $booking, $card, $reference);

        return [
            'booking_id' => $id,
            'status' => $status,
            'provider' => ['code' => $provider, 'name' => $providerName !== '' ? $providerName : ucfirst($provider)],
            'order_id' => $orderId,
            'created' => $this->stamp($booking['created_at'] ?? null),
            'updated' => $this->stamp($booking['updated_at'] ?? null),
            'banner' => $banner,
            'actions' => $this->actions($id, $provider, $orderId, $booking, $banner),
            'facts' => $this->facts($booking, $card, $order),
            'stay' => $this->stay($booking, $card, $display),
            'terms' => $hasCard && !empty($card['has_terms'])
                ? ['cancel' => TypeCoerce::toStringMap($card['cancel'] ?? null), 'lists' => TypeCoerce::toStringMap($card['terms'] ?? null)]
                : [],
            'supplier' => [
                'reference' => $reference,
                'price' => TypeCoerce::toString($hasCard && !empty($card['supplier_price']) ? $card['supplier_price'] : ($booking['total_label'] ?? '')),
                'note' => $hasCard ? trim(TypeCoerce::toString($card['note'] ?? '')) : '',
                'fields' => self::fields($display),
            ],
            'order' => $orderId > 0 ? [
                'id' => $orderId,
                'total' => TypeCoerce::toString($order['total'] ?? ''),
                'placed' => TypeCoerce::toString($order['placed'] ?? ''),
            ] : [],
            'history' => $this->history($booking, $order, $orderId),
            'technical' => self::technical($display),
        ];
    }

    /**
     * @param array<string, mixed> $card
     * @return array{code: string, tone: string}
     */
    private static function statusOf(array $card): array
    {
        $status = TypeCoerce::toStringMap($card['status'] ?? null);

        return ['code' => TypeCoerce::toString($status['code'] ?? ''), 'tone' => TypeCoerce::toString($status['tone'] ?? '')];
    }

    /**
     * What needs the admin's attention: a booking that failed or never
     * reached the supplier (with the supplier's answer), or one it has not
     * confirmed yet (since when, and how many days are left before check-in).
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $card
     * @return array{kind: string, error: string, has_reference: bool, sent_at: string, days_left: int}|array{}
     */
    private function banner(string $code, array $booking, array $card, string $reference): array
    {
        $alert = TypeCoerce::toStringMap($card['alert'] ?? null);
        $kind = match (true) {
            $code === OrderBookingCardFactory::STATUS_NOT_SENT => self::BANNER_NOT_SENT,
            $code === TravelConstants::STATUS_FAILED => self::BANNER_FAILED,
            $code === '' || in_array($code, self::SETTLED, true) => '',
            default => self::BANNER_AWAITING,
        };
        if ($kind === '') {
            return [];
        }
        $checkIn = DateHelper::toTimestamp(TypeCoerce::toString($booking['check_in'] ?? ''));
        $today = DateHelper::toTimestamp($this->today);
        $daysLeft = $checkIn !== null && $today !== null ? (int) round(($checkIn - $today) / 86400) : -1;

        return [
            'kind' => $kind,
            'error' => trim(TypeCoerce::toString($alert['error'] ?? '')),
            'has_reference' => $reference !== '',
            'sent_at' => $this->stamp($booking['created_at'] ?? null),
            // -1: the stay has begun (or its date is unknown) — no countdown.
            'days_left' => max(-1, $daysLeft),
        ];
    }

    /**
     * The head's buttons, in order: the provider's remedies, Check status,
     * the order. One is primary: the first remedy for a failed or unsent
     * booking, Check status while the supplier has not confirmed.
     *
     * A provider action that checks the status again duplicates Check status,
     * and one that opens this page again leads nowhere: both are left out.
     * POST actions carry cm-post (CS-Cart submits them with the security
     * hash) and come back to this page.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $banner
     * @return list<array{kind: string, label: string, href: string, post: bool, confirm: bool, primary: bool}>
     */
    private function actions(int $id, string $provider, int $orderId, array $booking, array $banner): array
    {
        $kind = TypeCoerce::toString($banner['kind'] ?? '');
        $broken = in_array($kind, [self::BANNER_FAILED, self::BANNER_NOT_SENT], true);
        $back = 'travel_bookings.view?booking_id=' . $id;

        $actions = [];
        foreach (TypeCoerce::toRowList($booking['provider_actions'] ?? null) as $action) {
            $name = TypeCoerce::toString($action['name'] ?? '');
            $url = TypeCoerce::toString($action['url'] ?? '');
            if ($url === '' || $name === 'check_status' || str_starts_with($url, 'travel_bookings.view')) {
                continue;
            }
            $post = strtoupper(TypeCoerce::toString($action['method'] ?? 'GET')) === 'POST';
            $params = TypeCoerce::toStringMap($action['extra_params'] ?? null);
            $query = ['provider' => $provider] + $params;
            $query['provider_action'] = TypeCoerce::toString($params['provider_action'] ?? $name);
            if (!empty($action['booking_id'])) {
                $query['booking_id'] = TypeCoerce::toString($action['booking_id']);
            }
            $query['return_url'] = $back;
            $actions[] = [
                'kind' => 'provider',
                'label' => TypeCoerce::toString($action['label'] ?? ''),
                'href' => $post ? $url . '?' . http_build_query($query) : $url,
                'post' => $post,
                'confirm' => str_contains(TypeCoerce::toString($action['css_class'] ?? ''), 'cm-confirm'),
                'primary' => $broken && $actions === [],
            ];
        }
        $actions[] = [
            'kind' => 'check_status',
            'label' => '',
            'href' => 'travel_bookings.check_status?booking_id=' . $id,
            'post' => true,
            'confirm' => false,
            'primary' => $kind === self::BANNER_AWAITING || ($broken && $actions === []),
        ];
        if ($orderId > 0) {
            $actions[] = [
                'kind' => 'order',
                'label' => '',
                'href' => 'orders.details?order_id=' . $orderId,
                'post' => false,
                'confirm' => false,
                'primary' => false,
            ];
        }

        return $actions;
    }

    /**
     * Dates, nights, where, who and what it costs — the card's when there is
     * one (circuit dates read as departure / return), else the row's.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $card
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function facts(array $booking, array $card, array $order): array
    {
        if ($card !== []) {
            return [
                'kind' => TypeCoerce::toString($card['kind'] ?? OrderBookingCardFactory::KIND_HOTEL),
                'check_in' => TypeCoerce::toStringMap($card['check_in'] ?? null),
                'check_out' => TypeCoerce::toStringMap($card['check_out'] ?? null),
                'nights' => TypeCoerce::toInt($card['nights'] ?? 0),
                'days' => TypeCoerce::toInt($card['days'] ?? 0),
                'place' => TypeCoerce::toString(TypeCoerce::toStringMap($card['hotel'] ?? null)['location'] ?? $booking['destination'] ?? ''),
                'rooms' => max(1, TypeCoerce::toInt($card['rooms'] ?? 1)),
                'adults' => TypeCoerce::toInt($card['adults'] ?? 0),
                'children' => TypeCoerce::toInt($card['children'] ?? 0),
                'total' => TypeCoerce::toString($booking['total_label'] ?? ''),
                'order_total' => TypeCoerce::toString($order['total'] ?? ''),
            ];
        }

        return [
            'kind' => OrderBookingCardFactory::KIND_HOTEL,
            'check_in' => $this->date(TypeCoerce::toString($booking['check_in'] ?? '')),
            'check_out' => $this->date(TypeCoerce::toString($booking['check_out'] ?? '')),
            'nights' => TypeCoerce::toInt($booking['nights'] ?? 0),
            'days' => 0,
            'place' => trim(TypeCoerce::toString($booking['destination'] ?? '')),
            'rooms' => 1,
            'adults' => TypeCoerce::toInt($booking['adults'] ?? 0),
            'children' => TypeCoerce::toInt($booking['children'] ?? 0),
            'total' => TypeCoerce::toString($booking['total_label'] ?? ''),
            'order_total' => TypeCoerce::toString($order['total'] ?? ''),
        ];
    }

    /**
     * The hotel, the package and every room with its guests — the card's
     * rooms when there is one, else the row's own (one block; its guests
     * from guests_json, the supplier's room names from the provider).
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $card
     * @param array<string, mixed> $display
     * @return array<string, mixed>
     */
    private function stay(array $booking, array $card, array $display): array
    {
        $hotel = TypeCoerce::toStringMap($card['hotel'] ?? null);
        $name = trim(TypeCoerce::toString($hotel['name'] ?? ''));
        $stay = [
            'hotel' => $name !== '' ? $name : trim(TypeCoerce::toString($booking['hotel_name'] ?? '')),
            'hotel_url' => TypeCoerce::toString($booking['hotel_product_url'] ?? ''),
            'location' => TypeCoerce::toString($hotel['location'] ?? $booking['destination'] ?? ''),
            'package' => TypeCoerce::toString($card['package'] ?? ''),
        ];
        if ($card !== []) {
            return $stay + ['rooms' => array_map(static fn (array $room): array => [
                'number' => TypeCoerce::toInt($room['number'] ?? 1),
                'name' => TypeCoerce::toString($room['name'] ?? ''),
                'board' => TypeCoerce::toString($room['board'] ?? ''),
                'adults' => TypeCoerce::toInt($room['adults'] ?? 0),
                'children' => TypeCoerce::toInt($room['children'] ?? 0),
                'children_ages' => TypeCoerce::toString($room['children_ages'] ?? ''),
                'supplier_room' => TypeCoerce::toString($room['supplier_room'] ?? ''),
                'guests' => self::guests(TypeCoerce::toRowList($room['guests'] ?? null)),
            ], TypeCoerce::toRowList($card['room_cards'] ?? null))];
        }

        return $stay + ['rooms' => [[
            'number' => 1,
            'name' => TypeCoerce::toString($booking['room_name'] ?? ''),
            'board' => TypeCoerce::toString($booking['board_code'] ?? ''),
            'adults' => TypeCoerce::toInt($booking['adults'] ?? 0),
            'children' => TypeCoerce::toInt($booking['children'] ?? 0),
            'children_ages' => TypeCoerce::toString($booking['children_ages'] ?? ''),
            'supplier_room' => implode(', ', array_filter(TypeCoerce::toStringList($display['supplier_rooms'] ?? null))),
            'guests' => self::guests(TypeCoerce::toRowList($booking['guests_decoded'] ?? null)),
        ]]];
    }

    /**
     * Guests as the page lists them: a name, child or adult, the age when
     * known, the lead guest marked. The card's guests carry
     * {name, is_child, age, is_holder}; a row's guests_json carries the
     * provider's own keys (first_name / last_name or name, type, is_holder).
     *
     * @param list<array<string, mixed>> $guests
     * @return list<array{name: string, is_child: bool, age: string, is_holder: bool}>
     */
    private static function guests(array $guests): array
    {
        $out = [];
        foreach ($guests as $guest) {
            $name = trim(TypeCoerce::toString($guest['name'] ?? ''));
            if ($name === '') {
                $name = trim(TypeCoerce::toString($guest['first_name'] ?? '') . ' ' . TypeCoerce::toString($guest['last_name'] ?? ''));
            }
            if ($name === '') {
                continue;
            }
            $age = TypeCoerce::toInt($guest['age'] ?? 0);
            $out[] = [
                'name' => $name,
                'is_child' => !empty($guest['is_child']) || strtolower(TypeCoerce::toString($guest['type'] ?? '')) === 'child',
                'age' => $age > 0 ? (string) $age : '',
                'is_holder' => !empty($guest['is_holder']),
            ];
        }

        return $out;
    }

    /**
     * The booking's moments, newest first: its last change, the order that
     * took it (or, without the order's own time, when it was linked), when
     * it was made — added to a cart.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $order
     * @return list<array{kind: string, at: string, order_id: int}>
     */
    private function history(array $booking, array $order, int $orderId): array
    {
        $events = [];
        $created = TypeCoerce::toString($booking['created_at'] ?? '');
        $updated = TypeCoerce::toString($booking['updated_at'] ?? '');
        $placed = TypeCoerce::toInt($order['placed_ts'] ?? 0);
        $linked = TypeCoerce::toString($booking['linked_at'] ?? '');
        if ($orderId > 0 && $placed > 0) {
            $events[] = ['kind' => 'order', 'ts' => $placed, 'at' => TypeCoerce::toString($order['placed'] ?? ''), 'order_id' => $orderId];
        } elseif ($orderId > 0 && $linked !== '') {
            $events[] = ['kind' => 'linked', 'ts' => (int) DateHelper::toTimestamp($linked), 'at' => $this->stamp($linked), 'order_id' => $orderId];
        }
        if ($created !== '') {
            $events[] = ['kind' => 'created', 'ts' => (int) DateHelper::toTimestamp($created), 'at' => $this->stamp($created), 'order_id' => 0];
        }
        // An update in the same minute as another moment says nothing new.
        $updatedAt = $this->stamp($updated);
        if ($updated !== '' && !in_array($updatedAt, array_column($events, 'at'), true)) {
            $events[] = ['kind' => 'updated', 'ts' => (int) DateHelper::toTimestamp($updated), 'at' => $updatedAt, 'order_id' => 0];
        }
        usort($events, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);

        return array_map(
            static fn (array $e): array => ['kind' => $e['kind'], 'at' => $e['at'], 'order_id' => $e['order_id']],
            array_values(array_filter($events, static fn (array $e): bool => $e['at'] !== '')),
        );
    }

    /**
     * The provider's own fields that hold a value ("Novoton invoice id:
     * 311921"); an empty one is not a fact.
     *
     * @param array<string, mixed> $display
     * @return list<array{label: string, value: string}>
     */
    private static function fields(array $display): array
    {
        $fields = [];
        foreach ($display as $field => $value) {
            if (in_array($field, self::SHOWN_ELSEWHERE, true) || is_array($value)) {
                continue;
            }
            $text = trim(TypeCoerce::toString($value));
            if ($text !== '') {
                $fields[] = ['label' => self::label($field), 'value' => $text];
            }
        }

        return $fields;
    }

    /**
     * Every raw provider field, empty ones too, for troubleshooting; a list
     * or record as pretty JSON.
     *
     * @param array<string, mixed> $display
     * @return list<array{label: string, value: string, is_pre: bool}>
     */
    private static function technical(array $display): array
    {
        $rows = [];
        foreach ($display as $field => $value) {
            if ($field === 'status_label' || $field === 'provider_ref') {
                continue;
            }
            $isArray = is_array($value);
            $rows[] = [
                'label' => self::label($field),
                'value' => $isArray
                    ? (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : TypeCoerce::toString($value),
                'is_pre' => $isArray,
            ];
        }

        return $rows;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
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

        return $ts === null || $iso === ''
            ? ['date' => $iso, 'weekday' => '']
            : ['date' => DateHelper::formatWith($ts, $this->dateFormat), 'weekday' => DateHelper::formatWith($ts, '%A')];
    }
}
