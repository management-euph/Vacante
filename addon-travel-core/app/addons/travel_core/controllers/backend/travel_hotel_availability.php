<?php

declare(strict_types=1);
/**
 * Travel Core — Hotel availability admin page
 * (docs/novoton-availability-gaps-plan.md §6).
 *
 * Every supplier hotel's availability state as the weekly review left it,
 * with filters, paging and a CSV of what the filters match. Report only: no
 * product is hidden or changed from here. "Review now" runs the review at
 * once, unless a supplier job that records availability is running.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Dto\Availability\AvailabilityVerdict;
use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityListing;
use Tygh\Addons\TravelCore\Services\AvailabilityCard;
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

    // Suppliers that record availability now, and any that recorded before.
    $providers = array_values(array_unique(array_merge(
        array_keys(TravelProviderRegistry::getAvailabilitySources()),
        array_map('strval', array_keys($counts)),
    )));
    $modes = [];
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
        $view->assign('availability_dormant_months', TravelCoreConfig::getAvailabilityThresholds()->dormantMonths);
        $view->assign('availability_offer_months', HotelAvailabilityListing::OFFER_MONTHS);
        $view->assign('availability_summary', AvailabilityReview::lastSummary());
        $view->assign('availability_last_failed', $finished > 0 && !($last['ok'] ?? false) ? TypeCoerce::toString($last['error'] ?? '') : '');
        $view->assign('availability_next_review', $nextReview);
        // The export keeps the page's filters.
        $view->assign('availability_export_url', 'travel_hotel_availability.export?' . http_build_query(array_intersect_key(
            $search,
            array_flip(['provider', 'state', 'no_offer_months', 'q', 'sort_by']),
        )));
    }
}
