<?php

declare(strict_types=1);
/**
 * Sphinx Booking Controller — AJAX Offer Terms
 *
 * Loads an offer's payment terms + cancellation fees ON DEMAND for the search
 * card's "Condiții de Plată și Anulare" modal. The search/results API does not
 * carry terms (must_verify=true); they exist only on the verify response, so
 * this re-verifies the offer and returns friendly terms lines + a free-until
 * date. Mirrors ajax_recalculate_price.php's verify → unwrap chain.
 *
 * design=a — the Design A room card's terms link (pdp-design-a.js): the same
 * answer as {sections: [{title, lines}]} (DesignARates::termsSections);
 * [] when nothing can be said, so the card says it couldn't load them.
 *
 * @package SphinxHolidays
 * @since   1.4.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Helpers\TermsDebug;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DesignARates;
use Tygh\Addons\SphinxHolidays\Services\TermsFormatter;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Results\DesignA;

header('Content-Type: application/json; charset=utf-8');

// The answer as JSON: as built below, or for a Design A card as sections of
// lines. Some of these texts are seeded by the .po files only: CS-Cart
// returns "_key" for one not seeded, which never reaches the guest.
$designA = DesignA::requested($_REQUEST);
$label = static function (string $key, string $fallback): string {
    $text = TypeCoerce::toString(__($key));

    return ($text === '' || str_starts_with($text, '_')) ? $fallback : $text;
};
$respond = static function (array $answer) use ($designA, $label): void {
    echo json_encode($designA ? ['sections' => DesignARates::termsSections($answer, [
        'payment' => $label('sphinx_holidays.payment_terms', 'Termeni de plată'),
        'cancellation' => $label('sphinx_holidays.cancellation_policy', 'Politica de anulare'),
        'free' => $label('sphinx_holidays.free_cancellation', 'Anulare gratuită'),
        'free_until' => $label('sphinx_holidays.free_cancellation_until', 'Anulare gratuită înainte de'),
        'none' => $label('sphinx_holidays.no_terms_info', 'Nu există condiții specifice pentru această ofertă.'),
        'outage' => $label('sphinx_holidays.terms_outage', 'Condițiile nu pot fi afișate momentan. Vă rugăm încercați din nou în câteva minute.'),
        'unavailable' => $label('sphinx_holidays.terms_unavailable', 'Condițiile nu sunt disponibile. Vă rugăm căutați din nou.'),
    ])] : $answer);
};

try {
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    if ($offer_id === '') {
        $respond(['status' => 'error', 'message' => 'Missing offer_id']);
        exit;
    }

    $api = Container::getApi();
    $verifyResult = $api->verifyHotelOffer($offer_id);

    // Operator diagnostics, off unless Settings > Debug > "Enable debug
    // logging" is on. Attached to EVERY branch below — the failure branches
    // are the ones worth inspecting, since that is where the modal currently
    // shows a message with no way to tell an empty payload from a dead
    // endpoint.
    $debugOn = ConfigProvider::isDebugLogging();

    // Offer expired / no longer verifiable → tell the modal to show a
    // re-search message rather than an empty terms panel. A 5xx / transport
    // failure is the verify SERVICE being down, not the offer being gone —
    // report it as an outage so the modal doesn't send the guest on a
    // pointless re-search.
    if (!OfferAvailability::isVerifiedAvailable(
        $verifyResult,
        ConfigProvider::shouldRequireImmediateAvailability(),
    )) {
        $http = $api->getHttpClient();
        $verifyHttpCode = $http->getLastHttpCode();
        $outage = $verifyHttpCode === 0 || $verifyHttpCode >= 500;

        // This branch used to be SILENT. The booking button's identical
        // rejection logged the HTTP code, but the terms modal logged nothing —
        // so a guest reporting "conditions can't be shown" left no trace at
        // all, and the two symptoms of one outage looked unrelated. Both now
        // carry the RAW upstream body: a 5xx decodes to null, so logging the
        // decoded result alone recorded the word "null" and threw away the
        // provider's actual error — the one thing worth forwarding to them.
        fn_log_event('general', 'runtime', [
            'message' => $outage
                ? 'Sphinx offer_terms: verify OUTAGE (HTTP ' . $verifyHttpCode . ') — terms unavailable, offer not rejected on merit'
                : 'Sphinx offer_terms: offer rejected by verify (HTTP ' . $verifyHttpCode . ')',
            'offer_id' => $offer_id,
            'transport_error' => $http->getLastError(),
            'raw_response' => substr((string) $http->getLastResponseRaw(), 0, 1000),
        ]);

        // The detailed schedule genuinely only exists on the verify response
        // (search sends payment_terms.is_loaded=false on every offer — 0 of
        // 17,336 measured). But search DOES carry cancellation_fees.is_free,
        // and during a verify outage that one fact is the difference between
        // "we can tell you nothing" and "your cancellation is free". Serve it
        // from the server-side snapshot rather than showing a bare error.
        $snapshot = \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::get($offer_id);
        $snapshotFees = TypeCoerce::toStringMap(($snapshot ?? [])['cancellation_fees'] ?? []);

        // Verify produced nothing usable, so the blocks shown are the SEARCH
        // ones from the snapshot — labelled as such, because "empty because
        // search never carries them" and "empty because verify died" are
        // different diagnoses and the panel must not blur them.
        $rejectDebug = TermsDebug::build(
            $debugOn,
            $outage ? 'verify_failed_showing_search_snapshot' : 'verify_rejected',
            $verifyHttpCode,
            TypeCoerce::toString($http->getLastError()),
            TypeCoerce::toString($http->getLastResponseRaw()),
            $snapshot,
        );

        if ($outage && $snapshotFees !== [] && array_key_exists('is_free', $snapshotFees)) {
            $respond([
                'status' => 'partial',
                'debug' => $rejectDebug,
                'is_free' => TypeCoerce::toBool($snapshotFees['is_free']),
                'payment_terms' => [],
                'cancellation_fees' => [],
                'payment_rules' => [],
                'cancellation_rules' => [],
                'free_until' => null,
                'notice' => TypeCoerce::toString(__('sphinx_holidays.terms_partial', [
                    '[default]' => 'The detailed payment and cancellation schedule cannot be loaded right now. The cancellation status below comes from the search result and is accurate.',
                ])),
            ]);
            exit;
        }

        $respond([
            'status' => $outage ? 'outage' : 'unavailable',
            'debug' => $rejectDebug,
        ]);
        exit;
    }

    $offer = TypeCoerce::toStringMap(OfferAvailability::unwrapOffer($verifyResult));

    // Value currency: the offer's own pricing currency, else the store default.
    $pricing = TypeCoerce::toStringMap($offer['pricing'] ?? []);
    $currency = TypeCoerce::toString($pricing['currency'] ?? $offer['currency'] ?? '');
    if ($currency === '') {
        $currency = ConfigProvider::getDefaultCurrency();
    }

    // Localized connective words for the rule dates (RO storefront).
    $untilLabel = TypeCoerce::toString(__('sphinx_holidays.terms_until', ['[default]' => 'până la']));
    $sinceLabel = TypeCoerce::toString(__('sphinx_holidays.terms_from', ['[default]' => 'de la']));

    $paymentTerms = TermsFormatter::lines($offer['payment_terms'] ?? null, $currency, $untilLabel, $sinceLabel);
    $cancellationFees = TermsFormatter::lines($offer['cancellation_fees'] ?? null, $currency, $untilLabel, $sinceLabel);
    $freeUntil = TermsFormatter::freeCancellationUntil($offer['cancellation_fees'] ?? null);

    $cancellation = TypeCoerce::toStringMap($offer['cancellation_fees'] ?? []);
    $isFree = TypeCoerce::toBool($cancellation['is_free'] ?? false);

    // Structured schedule rows for the timeline UI, kept ALONGSIDE the
    // legacy string lines (older cached pages keep working; prose-text
    // offers yield empty rule lists and the modal falls back to the lists).
    // The API's rule values are CUMULATIVE; payment rows are converted to
    // PER-INSTALLMENT increments so displayed amounts/percentages sum to
    // exactly 100% (763+3050=3813 → 20%+80%). Cancellation rows STAY
    // cumulative — each is an alternative "cancel from this date" scenario,
    // not an installment. Percent is SELF-ANCHORED to the schedule's final
    // cumulative value: the API sends no percentages, and these are
    // supplier-schedule amounts that do not sum to the commissioned
    // storefront price.
    $cumulativePayment = TermsFormatter::rules($offer['payment_terms'] ?? null);
    $cancellationRules = TermsFormatter::rules($offer['cancellation_fees'] ?? null);

    $scheduleTotal = 0.0;
    foreach (array_merge($cumulativePayment, $cancellationRules) as $row) {
        $scheduleTotal = max($scheduleTotal, $row['amount']);
    }

    $paymentRules = TermsFormatter::increments($cumulativePayment);
    foreach ($paymentRules as $i => $row) {
        $paymentRules[$i]['percent'] = $scheduleTotal > 0.0 ? round($row['amount'] / $scheduleTotal * 100, 1) : null;
    }
    foreach ($cancellationRules as $i => $row) {
        $cancellationRules[$i]['percent'] = $scheduleTotal > 0.0 ? round($row['amount'] / $scheduleTotal * 100, 1) : null;
    }

    $respond([
        'status' => 'ok',
        // The verify offer's own payment_terms / cancellation_fees objects,
        // untouched by TermsFormatter — so an empty panel can be attributed to
        // the API sending is_loaded=false rather than to our formatting.
        'debug' => TermsDebug::build(
            $debugOn,
            'verify',
            $api->getHttpClient()->getLastHttpCode(),
            TypeCoerce::toString($api->getHttpClient()->getLastError()),
            TypeCoerce::toString($api->getHttpClient()->getLastResponseRaw()),
            $offer,
        ),
        'payment_terms' => $paymentTerms,
        'cancellation_fees' => $cancellationFees,
        'free_until' => $freeUntil,
        'is_free' => $isFree,
        'payment_rules' => $paymentRules,
        'cancellation_rules' => $cancellationRules,
        'schedule_total' => $scheduleTotal > 0.0 ? $scheduleTotal : null,
        'currency' => $currency,
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx offer_terms error: ' . $e->getMessage()]);
    $respond(['status' => 'error', 'message' => 'Terms temporarily unavailable.']);
}

exit;
