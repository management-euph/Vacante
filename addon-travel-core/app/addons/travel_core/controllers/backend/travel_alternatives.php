<?php

declare(strict_types=1);
/**
 * Travel Core — Alternative Requests admin controller.
 *
 * Cross-provider grid of "no availability" contact requests stored in
 * travel_alternative_requests (novoton mirrors its provider records here;
 * sphinx records are internal-only), and of the hotel page's "notify me"
 * requests. Listing with provider/status/type filters and pagination.
 *
 * @package TravelCore
 * @since 1.5.0
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;
use Tygh\Addons\TravelCore\Repository\NotifyRequestRepository;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'update_status') {
    // Manual transitions are only for internal-only providers (sphinx) —
    // novoton rows follow the provider workflow, whose transitions
    // propagate into this table automatically.
    $requestId = TypeCoerce::toInt($_REQUEST['request_id'] ?? 0);
    $newStatus = trim(TypeCoerce::toString($_REQUEST['new_status'] ?? ''));

    $repo = new AlternativeRequestRepository();
    $request = $requestId > 0 ? $repo->findById($requestId) : null;

    if ($request === null) {
        fn_set_notification('E', __('error'), __('travel_core.request_not_found'));
    } elseif (($request['request_type'] ?? '') === NotifyRequestRepository::TYPE) {
        // A "notify me" request can only be cancelled; that removes its address.
        if ($newStatus === 'cancelled' && (new NotifyRequestRepository())->cancel($requestId)) {
            fn_set_notification('N', __('notice'), __('travel_core.status_updated'));
        } else {
            fn_set_notification('E', __('error'), __('travel_core.invalid_status'));
        }
    } elseif (($request['provider'] ?? '') !== 'sphinx') {
        fn_set_notification('E', __('error'), __('travel_core.status_managed_by_provider'));
    } elseif (!in_array($newStatus, AlternativeRequestRepository::MANUAL_STATUSES, true)) {
        fn_set_notification('E', __('error'), __('travel_core.invalid_status'));
    } else {
        $repo->updateStatus($requestId, $newStatus);
        fn_set_notification('N', __('notice'), __('travel_core.status_updated'));
    }

    return [CONTROLLER_STATUS_OK, 'travel_alternatives.manage'];
}

if ($mode === 'manage') {
    $repo = new AlternativeRequestRepository();
    [$requests, $search] = $repo->getListing(TypeCoerce::toStringMap($_REQUEST));

    $view->assign('alt_requests', $requests);
    $view->assign('search', $search);
    $view->assign('total_items', $search['total_items']);
}
