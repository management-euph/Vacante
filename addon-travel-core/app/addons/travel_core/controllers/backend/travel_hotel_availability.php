<?php

declare(strict_types=1);
/**
 * Travel Core — Hotel availability admin page
 * (docs/novoton-availability-gaps-plan.md §6, §10).
 *
 * Every supplier hotel's availability state as the weekly review left it,
 * with its product's status and what the availability gate did or would do,
 * filters, paging and a CSV of what the filters match. "Review now" runs the
 * review at once, unless a supplier job that records availability is
 * running. An admin's decision on a hotel (keep visible, keep hidden,
 * closed) is saved here, and applied at once in Automatic mode; so is the
 * hiding the gate's brake held, once the admin has checked the list.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Dto\Availability\AvailabilityVerdict;
use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityListing;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Services\AvailabilityCard;
use Tygh\Addons\TravelCore\Services\AvailabilityGate;
use Tygh\Addons\TravelCore\Services\AvailabilityOverride;
use Tygh\Addons\TravelCore\Services\AvailabilityReview;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'run') {
    $outcome = AvailabilityReview::run(true);

    if ($outcome['status'] === 'done') {
        fn_set_notification('N', __('notice'), __('travel_core.availability_review_done', [
            '[hotels]' => $outcome['hotels'],
            '[changed]' => $outcome['changed'],
        ]));
    } elseif ($outcome['status'] === 'busy') {
        fn_set_notification('W', __('warning'), __('travel_core.availability_review_busy', ['[jobs]' => implode(', ', $outcome['busy'])]));
    } elseif ($outcome['status'] === 'none') {
        fn_set_notification('W', __('warning'), __('travel_core.availability_review_none'));
    } else {
        fn_set_notification('E', __('error'), __('travel_core.availability_review_failed', ['[error]' => $outcome['error']]));
    }

    return [CONTROLLER_STATUS_REDIRECT, 'travel_hotel_availability.manage'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'override') {
    $provider = RequestCoerce::string($_REQUEST, 'provider');
    $hotelId = RequestCoerce::string($_REQUEST, 'hotel_id');
    $hotelName = RequestCoerce::string($_REQUEST, 'hotel_name');
    $outcome = (new AvailabilityOverride())->set(
        $provider,
        $hotelId,
        RequestCoerce::string($_REQUEST, 'override'),
        RequestCoerce::string($_REQUEST, 'override_note'),
        TypeCoerce::toInt((new SessionAccessor())->auth()['user_id'] ?? 0),
        new DateTimeImmutable(),
    );

    if ($outcome['status'] === 'saved') {
        $then = match (true) {
            $outcome['hidden'] > 0 => 'travel_core.availability_override_hidden',
            $outcome['shown'] > 0 => 'travel_core.availability_override_shown',
            !$outcome['automatic'] => 'travel_core.availability_override_report',
            default => '',
        };
        $message = TypeCoerce::toString(__('travel_core.availability_override_saved', ['[hotel]' => $hotelName !== '' ? $hotelName : '#' . $hotelId]))
            . ($then !== '' ? ' ' . TypeCoerce::toString(__($then)) : '');
        fn_set_notification('N', __('notice'), $message);
    } else {
        fn_set_notification('E', __('error'), __('travel_core.availability_override_failed'));
    }

    // Back to the same filters and page: only this page's own query string.
    $back = (string) preg_replace('/[^A-Za-z0-9_=&%.+-]/', '', RequestCoerce::string($_REQUEST, 'return_query'));

    return [CONTROLLER_STATUS_REDIRECT, 'travel_hotel_availability.manage' . ($back !== '' ? '?' . $back : '')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'apply_gate') {
    $provider = RequestCoerce::string($_REQUEST, 'provider');
    $outcome = (new AvailabilityOverride())->confirmHiding($provider, TypeCoerce::toInt((new SessionAccessor())->auth()['user_id'] ?? 0), new DateTimeImmutable());

    if ($outcome === null) {
        fn_set_notification('W', __('warning'), __('travel_core.availability_gate_not_automatic'));
    } else {
        fn_set_notification('N', __('notice'), __('travel_core.availability_gate_applied', [
            '[hidden]' => $outcome['hidden'],
            '[shown]' => $outcome['shown'],
        ]));
    }

    return [CONTROLLER_STATUS_REDIRECT, 'travel_hotel_availability.manage?provider=' . rawurlencode($provider) . '&gate=hidden'];
}

if ($mode === 'export') {
    $rows = (new HotelAvailabilityListing())->exportRows(TypeCoerce::toStringMap($_REQUEST), new DateTimeImmutable());

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel_availability_' . date('Y-m-d') . '.csv"');
    echo HotelAvailabilityListing::csv($rows);
    exit;
}

if ($mode === 'manage') {
    $now = new DateTimeImmutable();
    $listing = new HotelAvailabilityListing();
    [$rows, $search] = $listing->getListing(TypeCoerce::toStringMap($_REQUEST), $now);
    $counts = $listing->counts();
    $gateCounts = $listing->gateCounts();

    // Suppliers that record availability now, and any that recorded before.
    $providers = array_values(array_unique(array_merge(
        array_keys(TravelProviderRegistry::getAvailabilitySources()),
        array_map('strval', array_keys($counts)),
    )));
    $modes = [];
    $automatic = AvailabilityReview::automatic(TravelProviderRegistry::getAvailabilitySources());
    foreach ($providers as $provider) {
        $modes[$provider] = TravelCoreConfig::getAvailabilityMode($provider)->value;
        $counts[$provider] ??= []; // nothing recorded yet
    }

    // When the next weekly review is due: a week after the last complete one.
    $last = CronRunLog::get('travel_core', AvailabilityReview::JOB);
    $finished = $last['finished'] ?? 0;
    $nextReview = $finished > 0 && ($last['ok'] ?? false) ? $finished + AvailabilityReview::EVERY_DAYS * 86400 : 0;

    $view = Tygh::$app['view'];
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('availability_rows', $rows);
        $view->assign('search', $search);
        $view->assign('availability_counts', $counts);
        $view->assign('availability_labels', AvailabilityReview::labels($providers));
        $view->assign('availability_modes', $modes);
        $view->assign('availability_states', array_map(static fn (HotelAvailabilityState $s): string => $s->value, HotelAvailabilityState::cases()));
        $view->assign('availability_reasons', [
            AvailabilityVerdict::REASON_OFFER_SEEN,
            AvailabilityVerdict::REASON_OFFER_RECENT,
            AvailabilityVerdict::REASON_PEERS_SELLING,
            AvailabilityVerdict::REASON_SEASON_MEMORY,
            AvailabilityVerdict::REASON_NO_AVAILABILITY,
            AvailabilityVerdict::REASON_LEFT_FEED,
            AvailabilityVerdict::REASON_OVERRIDE_CLOSED,
            AvailabilityVerdict::REASON_NO_EVIDENCE,
        ]);
        $view->assign('availability_pills', AvailabilityCard::PILLS);
        // The availability gate: per supplier what it did and would do, which
        // suppliers it acts for now, the admin's choices, and what its brake
        // held at the last review.
        $view->assign('availability_gate_counts', $gateCounts);
        $view->assign('availability_automatic', array_fill_keys($automatic, true));
        $view->assign('availability_overrides', AvailabilityGate::OVERRIDES);
        $view->assign('availability_gate_filters', HotelAvailabilityListing::GATE_FILTERS);
        $view->assign('availability_brake', ['min' => AvailabilityGate::BRAKE_MIN, 'percent' => AvailabilityGate::BRAKE_PERCENT]);
        $view->assign('availability_dormant_months', TravelCoreConfig::getAvailabilityThresholds()->dormantMonths);
        $view->assign('availability_offer_months', HotelAvailabilityListing::OFFER_MONTHS);
        $view->assign('availability_summary', AvailabilityReview::lastSummary());
        $view->assign('availability_last_failed', $finished > 0 && !($last['ok'] ?? false) ? TypeCoerce::toString($last['error'] ?? '') : '');
        $view->assign('availability_next_review', $nextReview);
        // The export keeps the page's filters.
        $filters = array_intersect_key($search, array_flip(['provider', 'state', 'no_offer_months', 'gate', 'q', 'sort_by']));
        $view->assign('availability_export_url', 'travel_hotel_availability.export?' . http_build_query($filters));
        // A decision saved from a row returns to this view of the page.
        $view->assign('availability_return_query', http_build_query($filters + ['page' => $search['page']]));
    }
}
