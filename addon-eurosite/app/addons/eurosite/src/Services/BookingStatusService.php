<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Re-checks Eurosite bookings against getBookingRequest and maps the API's
 * status vocabulary onto the shared travel_core statuses. Powers both the
 * travel_bookings bulk button (syncAll) and the per-row check (checkSingle).
 */
class BookingStatusService
{
    public function __construct(
        private readonly EurositeBookingRepository $repo = new EurositeBookingRepository(),
    ) {
    }

    /**
     * @return array{checked: int, changed: int}
     */
    public function syncAll(int $limit = 50): array
    {
        $checked = 0;
        $changed = 0;
        foreach ($this->repo->findForStatusCheck($limit) as $booking) {
            $checked++;
            $result = $this->checkSingle(TypeCoerce::toInt($booking['booking_id'] ?? 0));
            if (!empty($result['changed'])) {
                $changed++;
            }
        }

        return ['checked' => $checked, 'changed' => $changed];
    }

    /**
     * @return array{changed: bool, old_status: string, new_status: string, error: string|null}
     */
    public function checkSingle(int $bookingId): array
    {
        $booking = $this->repo->findById($bookingId);
        if ($booking === null) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Booking not found'];
        }
        $reference = TypeCoerce::toString($booking['api_ref'] ?? '') !== ''
            ? TypeCoerce::toString($booking['api_ref'])
            : TypeCoerce::toString($booking['client_ref'] ?? '');
        $source = TypeCoerce::toString($booking['api_ref'] ?? '') !== '' ? 'api' : 'client';
        if ($reference === '') {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Booking has no API reference'];
        }

        $oldStatus = TypeCoerce::toString($booking['status'] ?? '');
        try {
            $info = Container::getApi()->getBooking($reference, $source);
        } catch (\Throwable $e) {
            return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'error' => $e->getMessage()];
        }

        $newStatus = self::mapApiStatus(TypeCoerce::toString($info['status'] ?? ''));
        if ($newStatus === '' || $newStatus === $oldStatus) {
            return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'error' => null];
        }

        $this->repo->update($bookingId, ['status' => $newStatus]);

        return ['changed' => true, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'error' => null];
    }

    /**
     * Best-effort mapping of Eurosite's status strings (RO/EN mixed in the
     * wild) onto the shared vocabulary; unknown values pass through
     * lowercased so nothing is silently rewritten.
     */
    public static function mapApiStatus(string $apiStatus): string
    {
        $s = strtolower(trim($apiStatus));
        if ($s === '') {
            return '';
        }

        return match (true) {
            str_contains($s, 'confirm') => TravelConstants::STATUS_CONFIRMED,
            str_contains($s, 'anulat') || str_contains($s, 'cancel') => TravelConstants::STATUS_CANCELLED,
            str_contains($s, 'pending') || str_contains($s, 'asteptare') || str_contains($s, 'request') => TravelConstants::STATUS_PENDING,
            default => $s,
        };
    }
}
