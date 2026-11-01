<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The two emails of the hotel's answer to a booking on request.
 *
 * The owner's rule (1 Nov 2026): checkout charges every room, those on
 * request too. By card the guest pays at once and is refunded for the rooms
 * the hotel does not confirm; by bank transfer, which takes days, the guest
 * is told when the hotel confirms and pays then, or not.
 *
 *  - Confirmed (Good): the guest, in the order's language — the hotel, the
 *    stay, the rooms confirmed and what they cost; an order not paid yet
 *    can be paid now by bank transfer, a paid one needs nothing more.
 *  - Refused (RQ) or cancelled (ST): the store, at its orders email — what
 *    the rooms cost and what to do: refund it when the order is paid (card),
 *    take that much less when it is not (bank transfer).
 *
 * WT (waiting list), and a booking no order holds, tell nobody.
 *
 * Both are built here and sent through CS-Cart's mailer with their subject
 * and body, so they need no email template that an installed store would
 * have to add; the guest's texts are the add-on's language keys, seeded on
 * every store (lang_keys.php). The guest's email names no supplier.
 */
final class RequestAnswerMail
{
    /** Statuses of a paid order, as BalanceService reads them: processed, complete. */
    public const array PAID_STATUSES = ['P', 'C'];

    public const string GUEST = 'guest';
    public const string STORE = 'store';

    /**
     * @param \Closure(int): array<string, mixed> $order the order (fn_get_order_info); [] when there is none
     * @param \Closure(array<string, mixed>, string, string): bool $deliver one mail: the mailer's params, its area (C / A), its language
     * @param \Closure(string, array<string, string>, string): string $translate a language key's text in a language
     * @param \Closure(float): string $roomsMoney an amount in the API currency, as the store charged it
     * @param \Closure(float): string $orderMoney an amount in the store's primary currency (the order's total)
     * @param string $storeEmail the store's orders email ('' = the store is not told)
     */
    public function __construct(
        private readonly \Closure $order,
        private readonly \Closure $deliver,
        private readonly \Closure $translate,
        private readonly \Closure $roomsMoney,
        private readonly \Closure $orderMoney,
        private readonly string $storeEmail,
    ) {
    }

    /**
     * The store's: its orders, CS-Cart's mailer, its language keys, its
     * money format, the orders email of Settings → Company.
     */
    public static function forStore(): self
    {
        $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';
        $format = MoneyFormatter::forCurrency($primary);
        $currency = Container::getInstance()->currencyService();

        return new self(
            static fn (int $orderId): array => TypeCoerce::toStringMap(fn_get_order_info($orderId)),
            static fn (array $mail, string $area, string $lang): bool => fn_novoton_holidays_send_mail($mail, $area, $lang),
            static fn (string $key, array $replace, string $lang): string => TypeCoerce::toString(__($key, $replace, $lang)),
            static fn (float $amount): string => $format->format($currency->convertFromApiCurrency($amount, $primary)),
            static fn (float $amount): string => $format->format($amount),
            ConfigProvider::getAdminEmail(),
        );
    }

    /**
     * The hotel answered a booking on request: the email it calls for, sent.
     *
     * @param array<string, mixed> $booking the novoton_bookings row
     * @param string $novotonStatus Novoton's status as stored (Good, RQ, ST, WT …)
     * @return string who was told: GUEST, STORE, or '' for nobody
     */
    public function answered(array $booking, string $novotonStatus): string
    {
        $refused = in_array($novotonStatus, [Constants::NOVOTON_STATUS_ALTERNATIVES_PENDING, Constants::NOVOTON_STATUS_CANCELLED], true);
        if (!$refused && $novotonStatus !== Constants::NOVOTON_STATUS_CONFIRMED) {
            return '';
        }
        $orderId = TypeCoerce::toInt($booking['order_id'] ?? 0);
        $order = $orderId > 0 ? ($this->order)($orderId) : [];
        if ($order === []) {
            return '';
        }

        try {
            if ($refused) {
                if ($this->storeEmail === '') {
                    return '';
                }
                $mail = $this->refusedMail($booking, $order, $novotonStatus);

                return ($this->deliver)(self::params($this->storeEmail, $mail), 'A', 'en') ? self::STORE : '';
            }

            $to = trim(TypeCoerce::toString($order['email'] ?? '')) ?: trim(TypeCoerce::toString($booking['guest_email'] ?? ''));
            if ($to === '') {
                return '';
            }
            $lang = strtolower(TypeCoerce::toString($order['lang_code'] ?? '')) ?: 'ro';
            $mail = $this->confirmedMail($booking, $order, $lang);

            return ($this->deliver)(self::params($to, $mail), 'C', $lang) ? self::GUEST : '';
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton: the email of a hotel\'s answer could not be sent',
                'booking_id' => TypeCoerce::toInt($booking['booking_id'] ?? 0),
                'order_id' => $orderId,
                'status' => $novotonStatus,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * The guest's email: the hotel confirmed the rooms on request.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $order
     * @return array{subject: string, body: string}
     */
    public function confirmedMail(array $booking, array $order, string $lang): array
    {
        $hotel = TypeCoerce::toString($booking['hotel_name'] ?? '');
        $orderId = self::orderId($booking, $order);
        $name = trim(TypeCoerce::toString($order['firstname'] ?? '')) ?: TypeCoerce::toString($booking['holder_name'] ?? '');

        $rooms = '';
        foreach (self::rooms($booking) as $room) {
            $rooms .= '<li>' . self::esc($room) . '</li>';
        }
        $payment = self::paid($order)
            ? $this->text('request_confirmed_paid', $lang)
            : $this->text('request_confirmed_pay', $lang, ['[order_id]' => $orderId, '[total]' => ($this->orderMoney)(TypeCoerce::toFloat($order['total'] ?? 0))]);

        return [
            'subject' => $this->text('request_confirmed_subject', $lang, ['[hotel]' => $hotel]),
            'body' => '<h2 style="color: #003580;">' . self::esc($this->text('request_confirmed_title', $lang)) . '</h2>'
                . '<p>' . self::esc($this->text('dear_customer', $lang, ['[name]' => $name])) . '</p>'
                . '<p>' . self::esc($this->text('request_confirmed_body', $lang, [
                    '[order_id]' => $orderId,
                    '[hotel]' => $hotel,
                    '[from]' => self::date($booking['check_in'] ?? ''),
                    '[to]' => self::date($booking['check_out'] ?? ''),
                ])) . '</p>'
                . '<ul>' . $rooms . '</ul>'
                . '<p>' . self::esc($this->text('request_confirmed_amount', $lang, ['[amount]' => ($this->roomsMoney)(TypeCoerce::toFloat($booking['total_price'] ?? 0))])) . '</p>'
                . '<p><strong>' . self::esc($payment) . '</strong></p>'
                . '<p>' . self::esc($this->text('request_confirmed_thanks', $lang)) . '</p>',
        ];
    }

    /**
     * The store's email: the hotel refused (RQ) or cancelled (ST) the rooms
     * on request — what they cost and what to do with the order's money.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $order
     * @return array{subject: string, body: string}
     */
    public function refusedMail(array $booking, array $order, string $novotonStatus): array
    {
        $hotel = TypeCoerce::toString($booking['hotel_name'] ?? '');
        $orderId = self::orderId($booking, $order);
        $amount = ($this->roomsMoney)(TypeCoerce::toFloat($booking['total_price'] ?? 0));
        $paid = self::paid($order);
        $cancelled = $novotonStatus === Constants::NOVOTON_STATUS_CANCELLED;

        $headline = $cancelled ? 'BOOKING ON REQUEST CANCELLED' : 'REQUEST REFUSED BY THE HOTEL';
        $action = $paid
            ? 'The order is paid: refund ' . $amount . ' to the guest for these rooms.'
            : 'The order is not paid yet: the guest pays ' . $amount . ' less for these rooms (bank transfer). '
                . 'If a card payment for the whole order arrives, refund ' . $amount . '.';
        $details = [
            'Order' => '#' . $orderId . ' (status ' . TypeCoerce::toString($order['status'] ?? '') . ')',
            'Booking' => '#' . TypeCoerce::toInt($booking['booking_id'] ?? 0)
                . ' (Novoton ' . $novotonStatus . ', reservation ' . TypeCoerce::toString($booking['novoton_invoice_id'] ?? '') . ')',
            'Hotel' => $hotel . ' (ID: ' . TypeCoerce::toString($booking['hotel_id'] ?? '') . ')',
            'Package' => TypeCoerce::toString($booking['package_name'] ?? ''),
            'Stay' => self::date($booking['check_in'] ?? '') . ' – ' . self::date($booking['check_out'] ?? ''),
            'Rooms' => implode('; ', self::rooms($booking)),
            'Guest' => TypeCoerce::toString($booking['holder_name'] ?? '') . ' · ' . TypeCoerce::toString($order['email'] ?? $booking['guest_email'] ?? ''),
            'These rooms' => $amount,
        ];
        $rows = '';
        foreach ($details as $label => $value) {
            $rows .= '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 160px;"><strong>' . self::esc($label) . ':</strong></td>'
                . '<td style="padding: 8px; border: 1px solid #ddd;">' . self::esc($value) . '</td></tr>';
        }

        return [
            'subject' => '[Novoton] ' . ($paid ? 'Refund ' : 'Do not collect ') . $amount . ' - order #' . $orderId . ' - ' . $hotel,
            'body' => '<h2 style="color: #c0392b;">' . self::esc($headline) . '</h2>'
                . '<p><strong>' . self::esc($action) . '</strong></p>'
                . '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">' . $rows . '</table>'
                . ($cancelled ? '' : '<p>Novoton looks for alternatives (alternative_RS); the guest is emailed when it finds some.</p>')
                . '<p style="color: #666; font-size: 12px;">Sent when Novoton answered the booking on request.</p>',
        ];
    }

    /**
     * Each room of the booking as the guest reads it: its type and its meal
     * plan, from rooms_data (add_to_cart's names), else the booking's own.
     *
     * @param array<string, mixed> $booking
     * @return list<string>
     */
    public static function rooms(array $booking): array
    {
        $raw = $booking['rooms_data'] ?? null;
        $rooms = is_string($raw) ? json_decode($raw, true) : $raw;
        $out = [];
        foreach (is_array($rooms) ? $rooms : [] as $room) {
            if (!is_array($room)) {
                continue;
            }
            $type = TypeCoerce::toString($room['room_type_display'] ?? '') ?: TypeCoerce::toString($room['room_name'] ?? '') ?: TypeCoerce::toString($room['room_id'] ?? '');
            $board = TypeCoerce::toString($room['board_name'] ?? '');
            $out[] = trim($type . ($board !== '' ? ' · ' . $board : ''));
        }
        if ($out === []) {
            $board = TypeCoerce::toString($booking['board_name'] ?? '');
            $out[] = trim(TypeCoerce::toString($booking['room_type'] ?? '') . ($board !== '' ? ' · ' . $board : ''));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function paid(array $order): bool
    {
        return in_array(TypeCoerce::toString($order['status'] ?? ''), self::PAID_STATUSES, true);
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $order
     */
    private static function orderId(array $booking, array $order): string
    {
        return (string) (TypeCoerce::toInt($order['order_id'] ?? 0) ?: TypeCoerce::toInt($booking['order_id'] ?? 0));
    }

    private static function date(mixed $iso): string
    {
        $text = TypeCoerce::toString($iso);
        $time = $text !== '' ? strtotime($text) : false;

        return $time !== false ? date('d.m.Y', $time) : $text;
    }

    /**
     * A language key of the add-on in the guest's language.
     *
     * @param array<string, string> $replace
     */
    private function text(string $key, string $lang, array $replace = []): string
    {
        return ($this->translate)('novoton_holidays.' . $key, $replace, $lang);
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array{subject: string, body: string} $mail
     * @return array<string, mixed>
     */
    private static function params(string $to, array $mail): array
    {
        return [
            'to' => $to,
            'from' => 'default_company_orders_department',
            'subj' => $mail['subject'],
            'body' => $mail['body'],
        ];
    }
}
