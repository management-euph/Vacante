<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;
use Tygh\Addons\TravelCore\Contracts\BookingPageDetailsProviderInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Novoton implementation of BookingAdminProviderInterface.
 *
 * Provides Novoton-specific display data, actions, and POST handlers
 * for the unified travel_bookings admin interface.
 *
 * @package NovotonHolidays
 * @since   2.8.0
 */
class BookingAdminProvider implements BookingAdminProviderInterface, BookingPageDetailsProviderInterface
{
    private BookingRepositoryInterface $bookingRepo;

    private SupplierRoomLabels $roomLabels;

    private ReservationExchange $exchange;

    public function __construct(
        ?BookingRepositoryInterface $bookingRepo = null,
        ?SupplierRoomLabels $roomLabels = null,
        ?ReservationExchange $exchange = null,
    ) {
        $this->bookingRepo = $bookingRepo ?? new BookingRepository();
        $this->roomLabels = $roomLabels ?? new SupplierRoomLabels();
        $this->exchange = $exchange ?? new ReservationExchange(null, $this->roomLabels);
    }

    /** @return array<string, mixed> */
    public function getDisplayData(string $providerBookingId): array
    {
        $booking = $this->bookingRepo->findById((int) $providerBookingId);

        if (empty($booking)) {
            return [];
        }

        $display = [];

        // Provider reference: the first real Novoton identifier we hold — API
        // confirmation, invoice (IdNum), or resinfo ResNum. Empty while the
        // booking is still pending (no API reference yet); the grid then shows
        // "-" instead of the internal booking id, which is NOT a provider ref
        // and rendered a meaningless, per-provider-duplicated "#1".
        $novotonRef = TypeCoerce::toString($booking['novoton_confirm_id'] ?? '');
        if ($novotonRef === '') {
            $novotonRef = TypeCoerce::toString($booking['novoton_invoice_id'] ?? '');
        }
        if ($novotonRef === '') {
            $novotonRef = TypeCoerce::toString($booking['novoton_res_num'] ?? '');
        }
        $display['provider_ref'] = $novotonRef !== '' ? 'NT ' . $novotonRef : '';

        // Status label with Novoton-specific styling
        $novotonStatus = TypeCoerce::toString($booking['novoton_status'] ?? '');
        $display['novoton_status'] = $novotonStatus;
        $display['novoton_invoice_id'] = $booking['novoton_invoice_id'] ?? '';
        $display['novoton_confirm_id'] = $booking['novoton_confirm_id'] ?? '';

        $statusLabels = [
            'Good' => '<span class="label label-success">Good</span>',
            'ASK' => '<span class="label label-warning">ASK</span>',
            'ST' => '<span class="label label-danger">ST</span>',
            'WT' => '<span class="label label-info">WT</span>',
            'RQ' => '<span class="label label-primary">RQ</span>',
        ];
        $display['status_label'] = $statusLabels[$novotonStatus] ?? '<span class="label">' . htmlspecialchars(TypeCoerce::toString($booking['status'] ?? '')) . '</span>';

        // Price info
        if (!empty($booking['api_price'])) {
            $display['api_price'] = $booking['api_price'];
        }

        return $display;
    }

    /**
     * The booking's own page, never the list: each room as Novoton names
     * it, IdRoom (Type), for a booking no order holds yet (the order card
     * carries its own), and the booking's exchange with Novoton under the
     * technical details (ReservationExchange): the request, the answer,
     * the notes, the hotel's rooms.
     *
     * @return array{supplier_rooms?: list<string>, technical?: list<array{label: string, value: string, is_pre: bool}>}
     */
    public function getPageDetails(string $providerBookingId): array
    {
        $booking = $this->bookingRepo->findById((int) $providerBookingId);
        if (empty($booking)) {
            return [];
        }

        $details = ['technical' => $this->exchange->rows($booking)];
        $rooms = $this->roomLabels->labels(
            TypeCoerce::toString($booking['hotel_id'] ?? ''),
            SupplierRoomLabels::roomIds($booking['rooms_data'] ?? null, TypeCoerce::toString($booking['room_id'] ?? '')),
        );
        if ($rooms !== []) {
            $details['supplier_rooms'] = $rooms;
        }

        return $details;
    }

    /** @return array{changed: bool, old_status: string, new_status: string, error: string|null} */
    public function checkStatus(string $providerBookingId): array
    {
        $bookingId = (int) $providerBookingId;
        if ($bookingId <= 0) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Invalid booking ID'];
        }

        $booking = $this->bookingRepo->findById($bookingId);
        $oldStatus = TypeCoerce::toString($booking['novoton_status'] ?? $booking['status'] ?? '');

        if (function_exists('fn_novoton_holidays_check_reservation_status')) {
            fn_novoton_holidays_check_reservation_status($bookingId);
        } else {
            return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => '', 'error' => 'Status check function not available'];
        }

        BookingRepository::invalidateCache($bookingId);
        $updatedBooking = $this->bookingRepo->findById($bookingId);
        $newStatus = TypeCoerce::toString($updatedBooking['novoton_status'] ?? $updatedBooking['status'] ?? '');

        return [
            'changed' => $oldStatus !== $newStatus,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return list<array<string, mixed>>
     */
    public function getAvailableActions(array $booking): array
    {
        $actions = [];
        $providerBookingId = TypeCoerce::toString($booking['provider_booking_id'] ?? '');
        $display = TypeCoerce::toStringMap($booking['provider_display'] ?? []);
        $novotonStatus = $display['novoton_status'] ?? '';

        // Check Status action for ASK bookings
        if ($novotonStatus === 'ASK') {
            $actions[] = [
                'name' => 'check_status',
                'label' => 'Check Status',
                'url' => 'travel_bookings.provider_action',
                'method' => 'POST',
                'css_class' => 'btn-default',
                'icon' => 'icon-refresh',
                'booking_id' => $providerBookingId,
                'extra_params' => ['provider_action' => 'resinfo'],
            ];
        }

        // Stopped / refused (ST) or answered with alternatives (RQ): ask for
        // alternatives (handleRequestAlternatives files the request the
        // alternatives cron works through). It used to link to
        // travel_bookings.view with this NOVOTON id — the unified page of
        // another booking — and a tab that page never had.
        if (in_array($novotonStatus, ['ST', 'RQ'], true)) {
            $actions[] = [
                'name' => 'request_alternatives',
                'label' => 'Request alternatives',
                'url' => 'travel_bookings.provider_action',
                'method' => 'POST',
                'css_class' => 'btn-primary',
                'icon' => 'icon-list',
                'booking_id' => $providerBookingId,
                'extra_params' => ['provider_action' => 'request_alternatives'],
            ];
        }

        // Cleanup orphans (global action, not per-booking)
        // This is surfaced as a bulk action in the manage view, not per-row

        return $actions;
    }

    public function getProviderViewUrl(string $providerBookingId): ?string
    {
        // Route through novoton's own controller, which resolves this novoton
        // booking id to the unified travel_bookings surrogate before
        // redirecting. Pointing straight at travel_bookings.view with the
        // novoton id collides id-spaces: that param is the surrogate key, and
        // each provider's FIRST booking shares provider_booking_id "1", so the
        // novoton row's "View in Novoton" icon opened sphinx's booking 1.
        return 'novoton_bookings.view?booking_id=' . (int) $providerBookingId;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    #[\Override]
    public function handleAction(string $action, array $request): array
    {
        switch ($action) {
            case 'resinfo':
                return $this->handleResinfo($request);

            case 'request_alternatives':
                return $this->handleRequestAlternatives($request);

            case 'check_all_status':
                return $this->handleCheckAllStatus();

            case 'cleanup_orphans':
                return $this->handleCleanupOrphans();

            case 'update_novoton_id':
                return $this->handleUpdateNovotonId($request);

            default:
                return [
                    'redirect' => 'travel_bookings.manage',
                    'notification' => ['type' => 'W', 'title' => TypeCoerce::toString(__('warning')), 'message' => "Unknown Novoton action: {$action}"],
                ];
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @return list<array{name: string, label: string, dispatch: string, ajax: bool}>
     */
    public function getProviderTabs(array $booking): array
    {
        $tabs = [];
        $bookingId = TypeCoerce::toString($booking['provider_booking_id'] ?? $booking['booking_id'] ?? '');
        $display = TypeCoerce::toStringMap($booking['provider_display'] ?? []);
        $novotonStatus = $display['novoton_status'] ?? '';

        // Alternatives tab for ST/RQ bookings or if alternatives were previously requested
        if (in_array($novotonStatus, ['ST', 'RQ'], true) || !empty($display['alternatives_requested'])) {
            $tabs[] = [
                'name' => 'alternatives',
                'label' => 'Alternatives',
                'dispatch' => 'travel_bookings.view&booking_id=' . $bookingId . '&tab=alternatives',
                'ajax' => false,
            ];
        }

        return $tabs;
    }

    // ── Provider-specific action handlers ──

    /**
     * Validate return_url to prevent open redirects.
     * Only allows relative dispatch URLs (no scheme/host).
     */
    private function validateReturnUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
            return '';
        }
        return $url;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    private function handleResinfo(array $request): array
    {
        $bookingId = TypeCoerce::toInt($request['booking_id'] ?? 0);
        if ($bookingId > 0) {
            fn_novoton_holidays_check_reservation_status($bookingId);
        }

        $returnUrl = $this->validateReturnUrl(TypeCoerce::toString($request['return_url'] ?? ''));

        return [
            'redirect' => $returnUrl !== '' ? $returnUrl : 'travel_bookings.manage',
            'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => TypeCoerce::toString(__('novoton_holidays.status_checked'))],
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    private function handleRequestAlternatives(array $request): array
    {
        $bookingId = TypeCoerce::toInt($request['booking_id'] ?? 0);

        if ($bookingId > 0) {
            $result = fn_novoton_holidays_request_alternatives($bookingId);

            if (!empty($result['success'])) {
                return [
                    'redirect' => $this->validateReturnUrl(TypeCoerce::toString($request['return_url'] ?? '')) ?: 'travel_bookings.manage',
                    'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => TypeCoerce::toString(__('novoton_holidays.alternatives_found', ['[count]' => 1]))],
                ];
            }

            return [
                'redirect' => $this->validateReturnUrl(TypeCoerce::toString($request['return_url'] ?? '')) ?: 'travel_bookings.manage',
                'notification' => ['type' => 'W', 'title' => TypeCoerce::toString(__('warning')), 'message' => TypeCoerce::toString(__('novoton_holidays.no_alternatives'))],
            ];
        }

        return ['redirect' => 'travel_bookings.manage'];
    }

    /**
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    private function handleCheckAllStatus(): array
    {
        $results = fn_novoton_holidays_cron_resinfo();

        return [
            'redirect' => 'travel_bookings.manage&provider=novoton',
            'notification' => [
                'type' => 'N',
                'title' => TypeCoerce::toString(__('notice')),
                'message' => TypeCoerce::toString(__('novoton_holidays.bulk_status_checked', [
                    '[checked]' => $results['checked'] ?? 0,
                    '[changed]' => $results['changed'] ?? 0,
                ])),
            ],
        ];
    }

    /**
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    private function handleCleanupOrphans(): array
    {
        $bookingRepo = Container::getInstance()->bookingRepository();
        $count = Container::getInstance()->bookingReportingRepository()->countOrphans(24);

        if ($count > 0) {
            $bookingRepo->deleteOrphans(24);
            return [
                'redirect' => 'travel_bookings.manage&provider=novoton',
                'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => "Cleaned up {$count} orphan booking(s) older than 24 hours."],
            ];
        }

        return [
            'redirect' => 'travel_bookings.manage&provider=novoton',
            'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => 'No orphan bookings to clean up.'],
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    private function handleUpdateNovotonId(array $request): array
    {
        $bookingId = TypeCoerce::toInt($request['booking_id'] ?? 0);
        $novotonInvoiceId = isset($request['novoton_invoice_id'])
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', TypeCoerce::toString($request['novoton_invoice_id']))
            : '';

        if ($bookingId > 0) {
            $bookingRepo = Container::getInstance()->bookingRepository();
            $bookingRepo->update($bookingId, ['novoton_invoice_id' => $novotonInvoiceId]);

            // If ID provided, check status
            if (!empty($novotonInvoiceId)) {
                fn_novoton_holidays_check_reservation_status($bookingId);
            }

            return [
                'redirect' => 'travel_bookings.view&booking_id=' . $bookingId,
                'notification' => ['type' => 'N', 'title' => TypeCoerce::toString(__('notice')), 'message' => 'Novoton ID updated'],
            ];
        }

        return ['redirect' => 'travel_bookings.manage'];
    }
}
