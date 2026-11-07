<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * What a Sphinx book* answer means for the store's booking row.
 *
 * The documented answer carries a status: "All successful bookings will
 * have the status confirmed", on_request needs the supplier's confirmation,
 * processing is to be checked again through the Orders endpoint, and
 * unconfirmed / canceled / errored are no booking. A refusal comes as an
 * error instead (an HTTP 4xx), which the client reports as no answer.
 *
 * Before, every call that did not throw marked the row confirmed — a
 * refused booking (no answer at all) included. Now:
 *  - confirmed: status confirmed, or (older answers) no status but a voucher code;
 *  - pending: on_request, processing, an unknown status, an answer with
 *    neither status nor voucher, and no answer when the request may have
 *    been processed (an HTTP 5xx, a timeout): the booking may exist at
 *    Sphinx, so it must not be booked again before it is checked there —
 *    the order status sync looks it up by the order's reference_code;
 *  - failed: a refusal (4xx), a request never sent (circuit open), or a
 *    status that says no booking was made. Only these can be retried.
 */
final class BookOutcome
{
    /** Statuses that say no booking was made. */
    private const array NO_BOOKING = ['unconfirmed', 'errored', 'canceled', 'cancelled'];

    /** Statuses still waiting on the supplier or on Sphinx. */
    private const array WAITING = ['on_request', 'processing'];

    /**
     * @param string $status TravelConstants::STATUS_CONFIRMED, STATUS_PENDING or STATUS_FAILED
     * @param string $reason why, when not confirmed ('' when confirmed)
     * @param string $voucher the booking_confirmation_number, '' when none
     * @param string $apiStatus the answer's own status, '' when none came
     */
    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly string $voucher,
        public readonly string $apiStatus,
    ) {
    }

    /**
     * @param array<string, mixed>|null $answer the decoded book answer; null when none came
     * @param bool $maybeProcessed no answer, but the request may have been processed (5xx, timeout)
     * @param string $error the client's error for a missing answer
     *
     * @return self the booking row's status, why, and the voucher code
     */
    public static function of(?array $answer, bool $maybeProcessed, string $error): self
    {
        if ($answer === null) {
            $error = trim($error) !== '' ? trim($error) : 'no answer';

            return $maybeProcessed
                ? new self(TravelConstants::STATUS_PENDING, 'no clear answer (' . $error . '): the booking may exist at Sphinx', '', '')
                : new self(TravelConstants::STATUS_FAILED, $error, '', '');
        }

        $voucher = TypeCoerce::toString($answer['booking_confirmation_number'] ?? $answer['booking_reference'] ?? '');
        $apiStatus = strtolower(trim(TypeCoerce::toString($answer['status'] ?? '')));

        return match (true) {
            $apiStatus === 'confirmed', $apiStatus === '' && $voucher !== '' => new self(TravelConstants::STATUS_CONFIRMED, '', $voucher, $apiStatus),
            in_array($apiStatus, self::NO_BOOKING, true) => new self(TravelConstants::STATUS_FAILED, 'Sphinx answered status ' . $apiStatus, $voucher, $apiStatus),
            in_array($apiStatus, self::WAITING, true) => new self(TravelConstants::STATUS_PENDING, 'Sphinx answered status ' . $apiStatus, $voucher, $apiStatus),
            $apiStatus === '' => new self(TravelConstants::STATUS_PENDING, 'an answer without status or voucher code', $voucher, $apiStatus),
            default => new self(TravelConstants::STATUS_PENDING, 'Sphinx answered an unknown status: ' . $apiStatus, $voucher, $apiStatus),
        };
    }

    public function isConfirmed(): bool
    {
        return $this->status === TravelConstants::STATUS_CONFIRMED;
    }

    /**
     * Whether a Sphinx booking status says no booking stands (an order's
     * booking list counts the others only).
     *
     * @param string $apiStatus a booking's status, as Sphinx writes it
     *
     * @return bool true for unconfirmed, errored, canceled
     */
    public static function isNoBooking(string $apiStatus): bool
    {
        return in_array(strtolower(trim($apiStatus)), self::NO_BOOKING, true);
    }
}
