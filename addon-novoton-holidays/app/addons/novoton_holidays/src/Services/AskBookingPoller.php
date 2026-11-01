<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Asks Novoton (resinfo) about the bookings still on request, as Novoton
 * asked on 28 Oct 2026: only bookings with status ASK, about every 15–30
 * minutes, and no more once the answer is OK, ST or RQ.
 *
 * Any answer other than ASK is stored as Novoton's status with its internal
 * one (Constants::NOVOTON_STATUS_TO_INTERNAL): OK confirms, ST cancels, RQ
 * hands the booking to the alternatives jobs (alternative_RS), WT puts it on
 * the waiting list. The booking leaves the ASK list with it, so it is not
 * asked about again. RQ used to stay ASK: it was asked about forever, and
 * the alternatives jobs never saw it.
 *
 * A booking Novoton does not answer for is skipped until the next run; it no
 * longer stops the run for the others.
 *
 * Each answer that ends the polling is handed on ($answered,
 * RequestAnswerMail): the guest is told when the hotel confirms, the store
 * when it refuses or cancels.
 *
 * Shared by the resinfo job and Travel Core's "Sync statuses" button, which
 * used to fetch the hotel list of every country instead.
 */
final class AskBookingPoller
{
    /** Answers that are not Novoton's status codes, as the old job read them. */
    private const array WORDS = [
        'confirmed' => Constants::NOVOTON_STATUS_CONFIRMED,
        'cancelled' => Constants::NOVOTON_STATUS_CANCELLED,
        'rejected' => Constants::NOVOTON_STATUS_CANCELLED,
    ];

    /** @var \Closure(string): void */
    private readonly \Closure $output;

    /**
     * @param (\Closure(string): void)|null $output one line of the run's log
     * @param (\Closure(array<string, mixed>, string): string)|null $answered the booking and Novoton's answer;
     *                                                                        returns who was told ('' = nobody)
     */
    public function __construct(
        private readonly NovotonApiKitInterface $api,
        private readonly BookingRepositoryInterface $bookings,
        ?\Closure $output = null,
        private readonly ?\Closure $answered = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /**
     * @return array{checked: int, changed: int, errors: int}
     */
    public function poll(int $limit = 50): array
    {
        $ask = $this->bookings->findByNovotonStatus(
            Constants::NOVOTON_STATUS_ON_REQUEST,
            [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_ASK],
            $limit,
        );
        $stats = ['checked' => 0, 'changed' => 0, 'errors' => 0];
        if ($ask === []) {
            ($this->output)('No bookings on request (ASK).');
            return $stats;
        }
        ($this->output)('Found ' . count($ask) . ' bookings on request (ASK).');

        foreach ($ask as $booking) {
            $bookingId = TypeCoerce::toInt($booking['booking_id'] ?? 0);
            $reservation = TypeCoerce::toString($booking['novoton_confirm_id'] ?? '')
                ?: TypeCoerce::toString($booking['novoton_res_num'] ?? '');
            if ($bookingId <= 0 || $reservation === '') {
                ($this->output)("Booking #{$bookingId}: no reservation number, skipped.");
                continue;
            }

            try {
                $response = $this->api->reservations()->getReservationInfo($reservation);
            } catch (NovotonException $e) {
                // No answer, a refusal, or one that does not parse.
                $stats['errors']++;
                ($this->output)("Booking #{$bookingId}: no answer ({$e->getMessage()}), asked again on the next run.");
                continue;
            }
            $stats['checked']++;
            $now = date('Y-m-d H:i:s');

            $status = self::novotonStatus(isset($response->Status) ? (string) $response->Status : '');
            $internal = Constants::NOVOTON_STATUS_TO_INTERNAL[$status] ?? null;
            if ($status === Constants::NOVOTON_STATUS_ON_REQUEST || $internal === null) {
                $this->bookings->update($bookingId, ['last_status_check' => $now]);
                ($this->output)("Booking #{$bookingId}: still " . ($status === '' ? 'no status' : $status) . '.');
                continue;
            }

            $this->bookings->update($bookingId, [
                'status' => $internal,
                'novoton_status' => $status,
                'last_status_check' => $now,
                'updated_at' => $now,
            ]);
            $stats['changed']++;
            ($this->output)("Booking #{$bookingId}: {$status} ({$internal}), no longer asked about.");

            if ($this->answered !== null) {
                try {
                    $told = ($this->answered)(['novoton_status' => $status, 'status' => $internal] + $booking, $status);
                } catch (\Throwable $e) {
                    // The answer is stored; an email that cannot go out never stops the run
                    $told = '';
                    ($this->output)("Booking #{$bookingId}: no email ({$e->getMessage()}).");
                }
                if ($told !== '') {
                    ($this->output)("Booking #{$bookingId}: the {$told} was emailed.");
                }
            }
        }

        return $stats;
    }

    /** Novoton's status as stored: 'OK' becomes 'Good', words become codes. */
    public static function novotonStatus(string $answer): string
    {
        $answer = trim($answer);

        return self::WORDS[strtolower($answer)] ?? Constants::normalizeApiStatus($answer);
    }
}
