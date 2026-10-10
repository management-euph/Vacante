<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Eurosite implementation of BookingAdminProviderInterface — display data,
 * status checks, and the cancel action for the unified travel_bookings
 * admin (sphinx shape).
 */
class BookingAdminProvider implements BookingAdminProviderInterface
{
    private EurositeBookingRepository $repo;

    public function __construct(?EurositeBookingRepository $repo = null)
    {
        $this->repo = $repo ?? new EurositeBookingRepository();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getDisplayData(string $providerBookingId): array
    {
        $booking = $this->repo->findById((int) $providerBookingId);
        if ($booking === null) {
            return [];
        }

        $display = [];
        $display['provider_ref'] = TypeCoerce::toString($booking['api_ref'] ?? '');
        $display['client_reference'] = TypeCoerce::toString($booking['client_ref'] ?? '');
        $display['tourop'] = TypeCoerce::toString($booking['product_code'] ?? '');

        if (!empty($booking['series_id'])) {
            $display['series_id'] = $booking['series_id'];
        }
        if (!empty($booking['variant_id'])) {
            $display['variant_id'] = $booking['variant_id'];
        }

        // Cancellation fee schedule captured from getBookingFees. Money is
        // formatted HERE — Smarty modifiers throw inside the admin {capture}.
        if (!empty($booking['cancellation_fees_json'])) {
            $fees = json_decode(TypeCoerce::toString($booking['cancellation_fees_json']), true);
            if (is_array($fees)) {
                $display['cancellation_fees'] = $fees;
            }
        }

        $status = TypeCoerce::toString($booking['status'] ?? '');
        $statusLabels = [
            TravelConstants::STATUS_CONFIRMED => '<span class="label label-success">Confirmed</span>',
            TravelConstants::STATUS_PENDING => '<span class="label label-warning">Pending</span>',
            TravelConstants::STATUS_CANCELLED => '<span class="label label-danger">Cancelled</span>',
            TravelConstants::STATUS_FAILED => '<span class="label label-danger">Failed</span>',
        ];
        $display['status_label'] = $statusLabels[$status] ?? '<span class="label">' . htmlspecialchars($status) . '</span>';

        return $display;
    }

    /**
     * @return array{changed: bool, old_status: string, new_status: string, error: string|null}
     */
    #[\Override]
    public function checkStatus(string $providerBookingId): array
    {
        $bookingId = (int) $providerBookingId;
        if ($bookingId <= 0) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Invalid booking ID'];
        }

        return (new BookingStatusService($this->repo))->checkSingle($bookingId);
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function getAvailableActions(array $booking): array
    {
        $actions = [];
        $status = TypeCoerce::toString($booking['status'] ?? '');
        $providerBookingId = TypeCoerce::toString($booking['provider_booking_id'] ?? $booking['booking_id'] ?? '');

        if (in_array($status, [TravelConstants::STATUS_CONFIRMED, TravelConstants::STATUS_PENDING], true)) {
            $actions[] = [
                'name' => 'cancel_booking',
                'label' => 'Cancel at Eurosite',
                'url' => 'travel_bookings.provider_action',
                'method' => 'POST',
                'css_class' => 'btn-danger cm-confirm',
                'icon' => 'icon-remove',
                'booking_id' => $providerBookingId,
                'extra_params' => ['provider_action' => 'cancel_booking'],
            ];
            $actions[] = [
                'name' => 'refresh_fees',
                'label' => 'Refresh Cancellation Fees',
                'url' => 'travel_bookings.provider_action',
                'method' => 'POST',
                'css_class' => 'btn',
                'icon' => 'icon-refresh',
                'booking_id' => $providerBookingId,
                'extra_params' => ['provider_action' => 'refresh_fees'],
            ];
        }

        return $actions;
    }

    #[\Override]
    public function getProviderViewUrl(string $providerBookingId): ?string
    {
        return null; // the unified travel_bookings.view is the only surface
    }

    /**
     * @param array<string, mixed> $request
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    #[\Override]
    public function handleAction(string $action, array $request): array
    {
        $bookingId = TypeCoerce::toInt($request['booking_id'] ?? 0);
        $redirect = 'travel_bookings.manage&provider=eurosite';
        $booking = $bookingId > 0 ? $this->repo->findById($bookingId) : null;
        if ($booking === null) {
            return [
                'redirect' => $redirect,
                'notification' => ['type' => 'E', 'title' => TypeCoerce::toString(__('error')), 'message' => 'Eurosite booking not found.'],
            ];
        }

        $reference = TypeCoerce::toString($booking['api_ref'] ?? '') !== ''
            ? TypeCoerce::toString($booking['api_ref'])
            : TypeCoerce::toString($booking['client_ref'] ?? '');
        $source = TypeCoerce::toString($booking['api_ref'] ?? '') !== '' ? 'api' : 'client';

        try {
            if ($action === 'cancel_booking') {
                $result = Container::getApi()->cancelBooking($reference, $source);
                if (!empty($result['ok'])) {
                    $this->repo->update($bookingId, ['status' => TravelConstants::STATUS_CANCELLED]);

                    return [
                        'redirect' => $redirect,
                        'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => 'Cancellation requested at Eurosite.'],
                    ];
                }

                return [
                    'redirect' => $redirect,
                    'notification' => ['type' => 'E', 'title' => TypeCoerce::toString(__('error')), 'message' => 'Cancellation failed: ' . TypeCoerce::toString($result['error'])],
                ];
            }

            if ($action === 'refresh_fees') {
                $fees = Container::getApi()->getBookingFees($reference, $source);
                $this->repo->update($bookingId, [
                    'cancellation_fees_json' => (string) json_encode($fees, JSON_UNESCAPED_UNICODE),
                ]);

                return [
                    'redirect' => $redirect,
                    'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => 'Cancellation fees refreshed from Eurosite.'],
                ];
            }
        } catch (\Throwable $e) {
            return [
                'redirect' => $redirect,
                'notification' => ['type' => 'E', 'title' => TypeCoerce::toString(__('error')), 'message' => 'Eurosite API error: ' . $e->getMessage()],
            ];
        }

        return [
            'redirect' => $redirect,
            'notification' => ['type' => 'W', 'title' => TypeCoerce::toString(__('warning')), 'message' => "Unknown Eurosite action: {$action}"],
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<int, array{name: string, label: string, dispatch: string, ajax: bool}>
     */
    #[\Override]
    public function getProviderTabs(array $booking): array
    {
        return [];
    }
}
