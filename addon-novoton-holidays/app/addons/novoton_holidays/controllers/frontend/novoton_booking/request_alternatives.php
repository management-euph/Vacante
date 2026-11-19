<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Request Alternatives Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "request_alternatives".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    // Sends a hotel_request to Novoton and an e-mail to the address typed in:
    // POST only (CS-Cart checks the form's security hash on POST).
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_booking.search'];
    }

    // The honeypot: people never see the field, bots fill it. Pretend it worked.
    if (RequestCoerce::string($_REQUEST, 'website') !== '') {
        fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_request_sent'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_booking.search'];
    }

    $security = _nvt_get_security_service();

    // --- Rate limiting (per account or session, and per address) ---
    if (!$security->allowsBookingRequest('request_alternatives', \Tygh\Addons\NovotonHolidays\Services\SecurityService::sessionIdentity())) {
        fn_set_notification('E', __('error'), __('novoton_holidays.too_many_requests'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }
    // The confirmation goes to the address typed in: a few a day per address,
    // whatever connection asks.
    $contact_email = mb_strtolower(trim(RequestCoerce::string($_REQUEST, 'contact_email')));
    if ($contact_email !== '' && !$security->checkRateLimit('alternatives_email_' . $contact_email, 3, 86400)['allowed']) {
        fn_set_notification('E', __('error'), __('novoton_holidays.too_many_requests'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // --- Validate and sanitize search params ---
    $sanitized = $security->validateSearchParams(TypeCoerce::toStringMap($_REQUEST));

    $hotel_id = TypeCoerce::toString($sanitized['hotel_id'] ?? '');
    $check_in = TypeCoerce::toString($sanitized['check_in'] ?? '');
    $nights = TypeCoerce::toInt($sanitized['nights'] ?? 7);

    // Delegate to AlternativeRequestService
    $altService = _nvt_alternative_request_service();
    $result = $altService->submitAlternativeBookingRequest([
        'hotel_id' => $hotel_id,
        'check_in' => $check_in,
        'check_out' => $sanitized['check_out'] ?? ($_REQUEST['check_out'] ?? ''),
        'nights' => $nights,
        'adults' => $sanitized['adults'] ?? 2,
        'children' => $sanitized['children'] ?? 0,
        'num_rooms' => $sanitized['rooms'] ?? 1,
        'contact_email' => RequestCoerce::string($_REQUEST, 'contact_email'),
        'contact_phone' => RequestCoerce::string($_REQUEST, 'contact_phone'),
        'notes' => strip_tags(mb_substr(RequestCoerce::string($_REQUEST, 'notes'), 0, 1000)),
    ]);

    if (!empty($result['error']) && $result['error'] === 'missing_required_fields') {
        fn_set_notification('E', __('error'), __('novoton_holidays.missing_required_fields'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id))];
    }

    if (!empty($result['error']) && $result['error'] === 'invalid_hotel') {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_hotel'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search')];
    }

    if (!empty($result['error']) && $result['error'] === 'invalid_email') {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_email'));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id))];
    }

    // Show appropriate notification
    if (!empty($result['message'])) {
        fn_set_notification('N', __('notice'), __('novoton_holidays.' . $result['message']));
    }

    return [CONTROLLER_STATUS_REDIRECT, fn_url('novoton_booking.search?hotel_id=' . urlencode($hotel_id) . '&check_in=' . urlencode($check_in) . '&nights=' . (int)$nights)];
