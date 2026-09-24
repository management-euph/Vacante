<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Alternative Requests Admin Controller
 * Manages hotel_request and alternative_RS functionality
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$altRequestRepo = _nvt_alternative_request_repo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check alternatives for a request
    if ($mode === 'check_alternatives' || $mode === 'alternative_rs') {
        $request_id = RequestCoerce::int($_REQUEST, 'request_id');

        if ($request_id > 0) {
            $request = $altRequestRepo->findById($request_id);
            $request = !empty($request) ? fn_novoton_holidays_decrypt_request_pii($request) : $request;

            if (!empty($request) && !empty($request['novoton_request_id'])) {
                // Load API
                $src_dir = TypeCoerce::toString(Registry::get('config.dir.addons')) . 'novoton_holidays/src/';
                if (file_exists($src_dir . 'NovotonApi.php')) {
                    require_once($src_dir . 'NovotonApi.php');
                }

                $api = _nvt_api();
                $novotonReqId = TypeCoerce::toString($request['novoton_request_id']);
                $response = $api->reservations()->getAlternatives($novotonReqId);

                if ((bool) $response && isset($response->alternative)) {
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'res_num' => (string)($alt->ResNum ?? ''),
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'hotel_name' => (string)($alt->HotelName ?? ''),
                            'package_name' => (string)($alt->PackageName ?? ''),
                            'room_id' => (string)($alt->IdRoom ?? ''),
                            'check_in' => (string)($alt->CheckIn ?? ''),
                            'check_out' => (string)($alt->CheckOut ?? ''),
                            'board_id' => (string)($alt->IdBoard ?? ''),
                            'quota' => (string)($alt->Quota ?? ''),
                            'total' => (string)($alt->Total ?? ''),
                            'currency' => (string)($alt->Currency ?? 'EUR')
                        ];
                    }

                    $altRequestRepo->markAlternativesFound($request_id, (string) json_encode($alternatives));

                    fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_found', ['[count]' => count($alternatives)]));
                } else {
                    fn_set_notification('W', __('warning'), __('novoton_holidays.no_alternatives_yet'));
                }
            } else {
                fn_set_notification('E', __('error'), __('novoton_holidays.no_novoton_request_id'));
            }
        }

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_alternatives.manage'];
    }

    // Notify customer about alternatives
    if ($mode === 'notify_customer') {
        $request_id = RequestCoerce::int($_REQUEST, 'request_id');

        if ($request_id > 0) {
            $request = $altRequestRepo->findById($request_id);
            $request = !empty($request) ? fn_novoton_holidays_decrypt_request_pii($request) : $request;

            if (!empty($request) && !empty($request['alternatives_data'])) {
                $alternatives = json_decode(TypeCoerce::toString($request['alternatives_data']), true);

                if (!empty($alternatives)) {
                    // Send email to customer
                    $mail_data = [
                        'request' => $request,
                        'alternatives' => $alternatives,
                        'hotel_name' => $request['hotel_name'],
                        'check_in' => $request['check_in'],
                        'check_out' => $request['check_out']
                    ];

                    /** @var \Tygh\Mailer\Mailer $mailer */
                    $mailer = Tygh::$app['mailer'];
                    $result = $mailer->send([
                        'to' => $request['contact_email'],
                        'from' => 'default_company_orders_department',
                        'data' => $mail_data,
                        'template_code' => 'novoton_alternatives_available',
                        'tpl' => 'addons/novoton_holidays/email/alternatives_available.tpl'
                    ], 'A');

                    if ($result) {
                        $altRequestRepo->markNotified($request_id);
                        fn_set_notification('N', __('notice'), __('novoton_holidays.customer_notified'));
                    } else {
                        fn_set_notification('E', __('error'), __('novoton_holidays.email_send_failed'));
                    }
                }
            }
        }

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_alternatives.manage'];
    }

    // Delete request
    if ($mode === 'delete') {
        $request_id = RequestCoerce::int($_REQUEST, 'request_id');

        if ($request_id > 0) {
            $altRequestRepo->delete($request_id);
            fn_set_notification('N', __('notice'), __('novoton_holidays.request_deleted'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_alternatives.manage'];
    }

    // Bulk check all pending requests
    if ($mode === 'check_all_pending') {
        $pending = $altRequestRepo->findPendingOlderThan(0);

        if (!empty($pending)) {
            $src_dir = TypeCoerce::toString(Registry::get('config.dir.addons')) . 'novoton_holidays/src/';
            if (file_exists($src_dir . 'NovotonApi.php')) {
                require_once($src_dir . 'NovotonApi.php');
            }

            $api = _nvt_api();
            $found = 0;

            foreach ($pending as $request) {
                if (empty($request['novoton_request_id'])) {
                    continue;
                }
                $novotonReqId = TypeCoerce::toString($request['novoton_request_id']);
                $response = $api->reservations()->getAlternatives($novotonReqId);

                if ((bool) $response && isset($response->alternative)) {
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'res_num' => (string)($alt->ResNum ?? ''),
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'hotel_name' => (string)($alt->HotelName ?? ''),
                            'room_id' => (string)($alt->IdRoom ?? ''),
                            'check_in' => (string)($alt->CheckIn ?? ''),
                            'check_out' => (string)($alt->CheckOut ?? ''),
                            'total' => (string)($alt->Total ?? '')
                        ];
                    }

                    if (!empty($alternatives)) {
                        $altRequestRepo->markAlternativesFound(TypeCoerce::toInt($request['request_id'] ?? 0), (string) json_encode($alternatives));
                        $found++;
                    }
                }

                usleep(\Tygh\Addons\NovotonHolidays\Constants::API_DELAY_MODERATE);
            }

            fn_set_notification('N', __('notice'), __('novoton_holidays.bulk_check_complete', ['[checked]' => count($pending), '[found]' => $found]));
        } else {
            fn_set_notification('I', __('information'), __('novoton_holidays.no_pending_requests'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_alternatives.manage'];
    }
}

// View/manage alternative requests
if ($mode === 'manage') {

    // The pagination menu sends items_per_page; the store setting is only the default.
    $items_per_page = max(1, RequestCoerce::int(
        $_REQUEST,
        'items_per_page',
        TypeCoerce::toInt(Registry::get('settings.Appearance.admin_elements_per_page')) ?: 30,
    ));
    $page = max(1, RequestCoerce::int($_REQUEST, 'page', 1));

    // Filters
    $status_filter = htmlspecialchars(RequestCoerce::string($_REQUEST, 'status'), ENT_QUOTES, 'UTF-8');
    $search_email = htmlspecialchars(RequestCoerce::string($_REQUEST, 'email'), ENT_QUOTES, 'UTF-8');

    $where = [];
    $params = [];

    if (!empty($status_filter)) {
        $where[] = "status = ?s";
        $params[] = $status_filter;
    }

    // Note: contact_email is AES-256 encrypted since v2.9.0.
    // LIKE search no longer works on encrypted values. For new encrypted rows
    // we must decrypt in PHP. For backward compat with pre-encryption rows,
    // we keep the LIKE as a fallback and also filter decrypted results below.
    $search_email_filter = $search_email;

    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count
    $total_items = $altRequestRepo->countFiltered($where_sql, $params);

    // Get requests with pagination
    $offset = ($page - 1) * $items_per_page;
    $requests = $altRequestRepo->findFiltered($where_sql, $params, $items_per_page, $offset);

    // Decrypt PII and decode alternatives data
    $requests = fn_novoton_holidays_decrypt_requests_pii($requests);

    // Post-decrypt email filter (LIKE doesn't work on encrypted data)
    if (!empty($search_email_filter)) {
        $requests = array_filter($requests, function($req) use ($search_email_filter) {
            return str_contains(strtolower(TypeCoerce::toString($req['contact_email'] ?? '')), strtolower($search_email_filter));
        });
        $total_items = count($requests);
    }

    foreach ($requests as &$req) {
        if (!empty($req['alternatives_data'])) {
            $req['alternatives'] = json_decode(TypeCoerce::toString($req['alternatives_data']), true);
        }
    }

    // Get status counts for tabs
    $status_counts = $altRequestRepo->getStatusCounts();

    $view->assign('requests', $requests);
    $view->assign('status_counts', $status_counts);
    $view->assign('status_filter', $status_filter);
    $view->assign('search_email', $search_email);
    $view->assign('total_items', $total_items);
    $view->assign('items_per_page', $items_per_page);
    $view->assign('page', $page);
    // common/pagination.tpl builds its controls from $search (fn_generate_pagination).
    $view->assign('search', [
        'page' => $page,
        'items_per_page' => $items_per_page,
        'total_items' => $total_items,
        'status' => $status_filter,
        'email' => $search_email,
    ]);
}

// View single request details
if ($mode === 'view') {
    $request_id = RequestCoerce::int($_REQUEST, 'request_id');

    if ($request_id > 0) {
        $request = $altRequestRepo->findById($request_id);
        $request = !empty($request) ? fn_novoton_holidays_decrypt_request_pii($request) : $request;

        if (!empty($request)) {
            if (!empty($request['alternatives_data'])) {
                $request['alternatives'] = json_decode(TypeCoerce::toString($request['alternatives_data']), true);
            }
            if (!empty($request['api_response'])) {
                $request['api_response_decoded'] = json_decode(TypeCoerce::toString($request['api_response']), true);
            }

            $view->assign('request', $request);
        } else {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
    } else {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
}
