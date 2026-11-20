<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A booking's Novoton reservation number: the IdNum that resinfo and
 * alternative_RS take.
 *
 * Submission keeps hotel_res_RQ's IdNum in novoton_invoice_id
 * (BookingSubmissionService). novoton_confirm_id and novoton_res_num are
 * older columns nothing writes today, still read for rows that have them.
 * Reading only those two left every booking on request without a number:
 * the resinfo job skipped them all, so no confirmation or refusal ever
 * reached the guest or the store.
 */
final class ReservationNumber
{
    /** The columns that can hold it, the one submission writes first. */
    public const array COLUMNS = ['novoton_invoice_id', 'novoton_confirm_id', 'novoton_res_num'];

    /**
     * @param array<string, mixed> $booking a novoton_bookings row
     *
     * @return string '' while the booking has none (not sent to Novoton yet)
     */
    public static function of(array $booking): string
    {
        foreach (self::COLUMNS as $column) {
            $number = trim(TypeCoerce::toString($booking[$column] ?? ''));
            if ($number !== '' && $number !== '0') {
                return $number;
            }
        }

        return '';
    }
}
