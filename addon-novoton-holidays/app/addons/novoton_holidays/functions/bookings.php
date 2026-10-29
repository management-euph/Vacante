<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Booking Functions
 * 
 * Functions for booking management, reservation status, alternatives.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Decrypt encrypted PII fields on an alternative request row.
 *
 * Since v2.9.0, contact_email, contact_phone and notes are stored
 * AES-256-CBC encrypted via SecurityService::encrypt(). This helper
 * transparently decrypts them so callers (admin views, cron, email
 * sending) can work with plaintext.
 *
 * Safe to call on rows that were stored before encryption was introduced:
 * decrypt() returns null on non-encrypted strings, and the helper falls
 * back to the original value in that case.
 *
 * @param array<string, mixed> $request  Single row from novoton_alternative_requests
 * @return array<string, mixed>          Same row with decrypted PII fields
 */
function fn_novoton_holidays_decrypt_request_pii(array $request): array
{
    // Lazy-load SecurityService (works in both controller and cron context)
    static $security = null;
    if ($security === null) {
        $loader = TypeCoerce::toString(Registry::get('config.dir.addons')) . 'novoton_holidays/src/Services/ServiceLoader.php';
        if (file_exists($loader)) {
            require_once $loader;
        }
        if (function_exists('_nvt_security_service')) {
            $security = _nvt_security_service();
        }
    }

    if (!$security instanceof SecurityServiceInterface) {
        return $request;
    }

    foreach (['contact_email', 'contact_phone', 'notes'] as $field) {
        if (!empty($request[$field])) {
            $decrypted = $security->decrypt(TypeCoerce::toString($request[$field]));
            if ($decrypted !== null) {
                $request[$field] = $decrypted;
            }
            // else: value was stored in plaintext (pre-encryption) — keep as-is
        }
    }

    return $request;
}

/**
 * Decrypt PII fields on an array of alternative request rows.
 *
 * @param list<array<string, mixed>> $requests  Array of rows from novoton_alternative_requests
 * @return list<array<string, mixed>>           Same rows with decrypted PII fields
 */
function fn_novoton_holidays_decrypt_requests_pii(array $requests): array
{
    foreach ($requests as &$request) {
        $request = fn_novoton_holidays_decrypt_request_pii($request);
    }
    return $requests;
}

/**
 * Check reservation status from Novoton API
 * 
 * @param int $booking_id Booking ID (0 = check all pending)
 * @return array<string, mixed>
 */
function fn_novoton_holidays_check_reservation_status($booking_id = 0): array
{
    $api = fn_novoton_holidays_get_api();
    if ($api === null) {
        return ['success' => false, 'error' => 'API not available'];
    }

    $bookingRepo = _nvt_booking_repo();

    if ($booking_id > 0) {
        $booking = $bookingRepo->findById($booking_id);
        $bookings = $booking !== null ? [$booking] : [];
    } else {
        $bookings = $bookingRepo->findWithReservationId();
        // Filter to only pending
        $bookings = array_filter($bookings, fn($b) => $b['status'] === TravelConstants::STATUS_PENDING);
    }

    $result = [
        'success' => true,
        'checked' => 0,
        'updated' => 0,
        'details' => []
    ];

    foreach ($bookings as $booking) {
        // Reservation reference: confirmation id from booking, else the
        // ResNum learned via resinfo (same precedence as ResInfoCommand).
        $reservation_ref = TypeCoerce::toString($booking['novoton_confirm_id'] ?? '')
            ?: TypeCoerce::toString($booking['novoton_res_num'] ?? '');
        if ($reservation_ref === '') continue;
        $result['checked']++;

        $booking_pk = TypeCoerce::toInt($booking['booking_id'] ?? 0);
        $current_status = TypeCoerce::toString($booking['status'] ?? '');

        try {
            $status_response = $api->reservations()->getReservationInfo($reservation_ref);

            if (!empty($status_response)) {
                // getReservationInfo returns an XML object — access via object properties
                $new_status = (string)($status_response->Status ?? $status_response->status ?? '');

                // Map Novoton API status codes to internal status via centralized constant
                $internal_status = TypeCoerce::toString(
                    \Tygh\Addons\NovotonHolidays\Constants::NOVOTON_STATUS_TO_INTERNAL[$new_status]
                        ?? $current_status
                );

                if ($internal_status !== $current_status) {
                    $bookingRepo->updateStatus($booking_pk, $internal_status, $new_status);
                    $result['updated']++;
                    $result['details'][$booking_pk] = [
                        'old' => $current_status,
                        'new' => $internal_status,
                        'novoton' => $new_status
                    ];
                }
            }

        } catch (\Exception $e) {
            $result['details'][$booking_pk] = [
                'error' => $e->getMessage()
            ];
        }
    }

    return $result;
}

/**
 * Request alternatives for a booking
 * 
 * @param int $booking_id Booking ID
 * @return array{success: bool, request_id?: int, error?: string, message?: string}
 */
function fn_novoton_holidays_request_alternatives($booking_id): array
{
    $bookingRepo = _nvt_booking_repo();
    $booking = $bookingRepo->findById($booking_id);

    if (empty($booking)) {
        return ['success' => false, 'error' => 'Booking not found'];
    }

    // Check if request already exists
    $existing = db_get_field(
        "SELECT request_id FROM ?:novoton_alternative_requests WHERE booking_id = ?i AND status = ?s",
        $booking_id, TravelConstants::STATUS_PENDING
    );

    if ($existing) {
        return ['success' => false, 'error' => 'Alternative request already pending', 'request_id' => TypeCoerce::toInt($existing)];
    }

    // Create new request
    $request_data = [
        'booking_id' => $booking_id,
        'order_id' => $booking['order_id'],
        'status' => TravelConstants::STATUS_PENDING,
        'notes' => 'Requested by customer'
    ];

    $request_id = db_query("INSERT INTO ?:novoton_alternative_requests ?e", $request_data);

    return [
        'success' => true,
        'request_id' => TypeCoerce::toInt($request_id),
        'message' => 'Alternative request created'
    ];
}

/**
 * Get alternatives for a booking
 * 
 * @param int $booking_id Booking ID
 * @return array<string, mixed> Alternatives data (decoded JSON)
 */
function fn_novoton_holidays_get_alternatives($booking_id): array
{
    $request = TypeCoerce::toStringMap(db_get_row(
        "SELECT request_id, booking_id, order_id, status, alternatives_data, notes, created_at, updated_at FROM ?:novoton_alternative_requests WHERE booking_id = ?i ORDER BY created_at DESC LIMIT 1",
        $booking_id
    ));

    if (empty($request)) {
        return [];
    }

    return JsonDecoder::decode($request['alternatives_data'] ?? '', 'alternatives_data');
}

/**
 * Get bookings for an order
 * 
 * @param int $order_id Order ID
 * @return list<array<string, mixed>> Booking rows
 */
function fn_novoton_holidays_get_order_bookings($order_id): array
{
    $bookingRepo = _nvt_booking_repo();
    return $bookingRepo->findByOrderId($order_id);
}

/**
 * The bookings still on request (ASK): asks Novoton about each with resinfo
 * and stores the answer (Services\AskBookingPoller, also the resinfo job).
 *
 * Called by Travel Core's "Sync statuses" button (init.php) and the admin
 * "resinfo" button. Despite its name it used to fetch the hotel list of
 * every country, so neither button checked a single booking.
 *
 * @return array{success: bool, checked?: int, changed?: int, errors?: int, error?: string}
 */
function fn_novoton_holidays_cron_resinfo(): array
{
    $api = fn_novoton_holidays_get_api();
    if ($api === null) {
        return ['success' => false, 'error' => 'API not available'];
    }

    $stats = (new \Tygh\Addons\NovotonHolidays\Services\AskBookingPoller($api, _nvt_booking_repo()))->poll();

    return ['success' => true] + $stats;
}
