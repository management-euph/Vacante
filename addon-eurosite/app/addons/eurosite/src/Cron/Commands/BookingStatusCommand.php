<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\Eurosite\Services\BookingStatusSchedule;
use Tygh\Addons\Eurosite\Services\BookingStatusService;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `booking_status` — every 15 minutes: asks Eurosite (getBookingRequest)
 * about the bookings it took and has not confirmed yet, on the schedule
 * Eurosite asked for on 28 Oct 2026 (BookingStatusSchedule): 30 minutes
 * after the booking, then every 30 minutes for two hours; if still not
 * final, 8 hours later, every 30 minutes for two hours again. After that
 * only staff ask (Travel Core → Bookings, Sync statuses).
 *
 * Nothing polled before: a booking waiting for confirmation changed only
 * when someone pressed Sync statuses.
 */
final class BookingStatusCommand extends AbstractSyncCommand
{
    /** Bookings taken longer ago than this are past the schedule (its last round ends at 750 minutes). */
    private const int WINDOW = 13 * 3600;

    private readonly EurositeBookingRepository $bookings;

    private readonly BookingStatusService $status;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock unix time; tests pin it
     */
    public function __construct(
        ?EurositeBookingRepository $bookings = null,
        ?BookingStatusService $status = null,
        ?\Closure $clock = null,
    ) {
        parent::__construct();
        $this->bookings = $bookings ?? Container::bookings();
        $this->status = $status ?? new BookingStatusService($this->bookings);
        $this->clock = $clock ?? static fn (): int => time();
    }

    #[\Override]
    public static function getModes(): array
    {
        return ['booking_status'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Check bookings waiting for confirmation on Eurosite\'s schedule (30 minutes after booking, then every 30 minutes for 2 hours; again 8 hours later)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('booking_status', function (): array {
            $now = ($this->clock)();
            $stats = ['total' => 0, 'synced' => 0, 'failed' => 0, 'changed' => 0];

            foreach ($this->bookings->findAwaitingConfirmation(date('Y-m-d H:i:s', $now - self::WINDOW)) as $booking) {
                $bookingId = TypeCoerce::toInt($booking['booking_id'] ?? 0);
                $submitted = strtotime(TypeCoerce::toString($booking['submitted_at'] ?? ''));
                $checked = strtotime(TypeCoerce::toString($booking['status_checked_at'] ?? ''));
                if ($bookingId <= 0 || $submitted === false) {
                    continue;
                }
                if (BookingStatusSchedule::state($submitted, $checked === false ? 0 : $checked, $now) !== BookingStatusSchedule::DUE) {
                    continue;
                }

                $stats['total']++;
                $result = $this->status->checkSingle($bookingId);
                $this->bookings->update($bookingId, ['status_checked_at' => date('Y-m-d H:i:s', $now)]);
                if ($result['error'] !== null) {
                    $stats['failed']++;
                    $this->output("Booking #{$bookingId}: no answer ({$result['error']}), asked again at its next check.");
                    continue;
                }
                $stats['synced']++;
                if ($result['changed']) {
                    $stats['changed']++;
                    $this->output("Booking #{$bookingId}: {$result['old_status']} → {$result['new_status']}.");
                } else {
                    $this->output("Booking #{$bookingId}: still {$result['new_status']}.");
                }
            }

            if ($stats['total'] === 0) {
                $this->output('No booking is due for a check.');
            }

            return $stats;
        });
    }
}
