<?php

declare(strict_types=1);
/**
 * Travel Core — the hotel page's "notify me" form
 * (docs/novoton-availability-gaps-plan.md §8).
 *
 * POST travel_availability.notify: product_id, email, consent=Y. Stores one
 * request (NotifyRequestForm) and returns to the hotel page with a notice.
 * The daily cron sends its one email once booking opens
 * (AvailabilityNotifyMail). Takes nothing while the availability panels are
 * switched off.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\NotifyRequestForm;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($mode === 'notify') {
    $productId = max(0, RequestCoerce::int($_REQUEST, 'product_id'));
    $back = $productId > 0 ? 'products.view?product_id=' . $productId : 'index.index';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [CONTROLLER_STATUS_REDIRECT, $back];
    }

    $session = Tygh::$app['session'];
    if (!is_array($session) && !$session instanceof \ArrayAccess) {
        $session = [];
    }
    $status = (new NotifyRequestForm())->submit(
        TypeCoerce::toStringMap($_REQUEST),
        $session,
        new \DateTimeImmutable(),
        defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
    );

    match ($status) {
        'saved' => fn_set_notification('N', __('notice'), __('travel_core.notify_request_saved')),
        'invalid_email' => fn_set_notification('E', __('error'), __('travel_core.request_invalid_email')),
        'no_consent' => fn_set_notification('E', __('error'), __('travel_core.notify_request_no_consent')),
        'too_soon' => fn_set_notification('W', __('notice'), __('travel_core.request_too_soon')),
        'unavailable' => fn_set_notification('W', __('notice'), __('travel_core.notify_request_unavailable')),
        default => fn_set_notification('E', __('error'), __('travel_core.request_failed')),
    };

    return [CONTROLLER_STATUS_REDIRECT, $back];
}
