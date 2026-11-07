<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * The email to the orders department when Sphinx does not confirm a booking,
 * at order placement or on a retry.
 *
 * Refused (failed): no booking was made. It can be retried from Travel →
 * Bookings once the cause is fixed. No clear answer (pending): the booking
 * may exist at Sphinx, so it must not be booked again before it is checked
 * there. The order status sync looks it up by the order's reference code.
 *
 * The wording comes from language keys, which the admin can edit under
 * Translations.
 */
final class BookingAlert
{
    /** @var \Closure(string, array<string, string>): string */
    private \Closure $translate;

    /** @var \Closure(array<string, string>): bool */
    private \Closure $mailer;

    /** @var \Closure(): string */
    private \Closure $recipient;

    /**
     * @param (\Closure(string, array<string, string>): string)|null $translate test seam; defaults to __()
     * @param (\Closure(array<string, string>): bool)|null $mailer test seam; defaults to fn_send_mail, admin area
     * @param (\Closure(): string)|null $recipient test seam; defaults to the orders department's email
     */
    public function __construct(?\Closure $translate = null, ?\Closure $mailer = null, ?\Closure $recipient = null)
    {
        $this->translate = $translate ?? static fn (string $key, array $params): string => TypeCoerce::toString(__($key, $params));
        $this->mailer = $mailer ?? static fn (array $mail): bool => (bool) fn_send_mail($mail, 'A');
        $this->recipient = $recipient ?? static fn (): string => TypeCoerce::toString(
            db_get_field("SELECT value FROM ?:settings_objects WHERE name = 'company_orders_department'"),
        );
    }

    /**
     * @param BookOutcome $outcome what the book call answered
     * @param int $bookingId the sphinx_bookings row
     * @param int $orderId the store's order
     * @param string $what the hotel (or the offer type) booked
     *
     * @return array{subject: string, body: string}|null null when the booking is confirmed
     */
    public function compose(BookOutcome $outcome, int $bookingId, int $orderId, string $what): ?array
    {
        if ($outcome->isConfirmed()) {
            return null;
        }
        $params = [
            '[booking_id]' => (string) $bookingId,
            '[order_id]' => (string) $orderId,
            '[what]' => $what,
            '[reason]' => $outcome->reason,
        ];
        $t = $this->translate;

        return [
            'subject' => $t('sphinx_holidays.book_alert_subject', $params),
            'body' => $t($outcome->status === TravelConstants::STATUS_FAILED ? 'sphinx_holidays.book_alert_failed' : 'sphinx_holidays.book_alert_pending', $params),
        ];
    }

    /**
     * @param BookOutcome $outcome what the book call answered
     * @param int $bookingId the sphinx_bookings row
     * @param int $orderId the store's order
     * @param string $what the hotel (or the offer type) booked
     *
     * @return bool whether an email went out (none for a confirmed booking, or without a recipient)
     */
    public function send(BookOutcome $outcome, int $bookingId, int $orderId, string $what): bool
    {
        // An alert never changes the booking: whatever fails here is logged.
        try {
            $mail = $this->compose($outcome, $bookingId, $orderId, $what);
            if ($mail === null) {
                return false;
            }
            $to = ($this->recipient)();
            if ($to === '') {
                fn_log_event('general', 'runtime', [
                    'message' => '[SphinxBooking] cannot alert the store: the company_orders_department email is not configured',
                    'booking_id' => $bookingId,
                ]);

                return false;
            }

            return ($this->mailer)([
                'to' => $to,
                'from' => 'default_company_orders_department',
                'subject' => $mail['subject'],
                'body' => $mail['body'],
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[SphinxBooking] alert email failed: ' . $e->getMessage()]);

            return false;
        }
    }
}
