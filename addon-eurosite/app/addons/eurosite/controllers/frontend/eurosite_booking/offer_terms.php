<?php

declare(strict_types=1);
/**
 * eurosite_booking.offer_terms — AJAX JSON for the "Condiții de Anulare și
 * Plată" modal on the search results page (sphinx offer_terms shape).
 *
 * Eurosite search responses carry NO terms (unlike novoton, whose quote
 * includes them), so the modal fetches on open:
 *   - cancellation rules: live getItemFeesRequest for the snapshotted offer
 *     (placeholder pax names pre-booking — the client fills them);
 *   - payment terms: the admin-authored addon setting (the API exposes no
 *     payment schedule pre-booking).
 *
 * GET params: offer_key (from the search snapshot); design=a — the Design A
 * room card's terms link (pdp-design-a.js): the same terms as
 * {sections: [{title, lines}]} (DesignARates::termsSections), worded as the
 * booking sidebar words them.
 */

use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\DesignARates;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Results\DesignA;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

header('Content-Type: application/json; charset=utf-8');

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;
$designA = DesignA::requested($_REQUEST);

if ($snapshot === null) {
    echo json_encode($designA ? ['sections' => [], 'error' => 'offer_expired'] : ['success' => false, 'error' => 'offer_expired']);
    exit;
}

$paymentLines = array_values(array_filter(array_map(
    'trim',
    explode("\n", ConfigProvider::getPaymentTermsText()),
), static fn (string $line): bool => $line !== ''));

// The Design A card's words for its terms (design=a).
$designATexts = [
    'cancel' => TypeCoerce::toString(__('eurosite.cancellation_terms')),
    'payment' => TypeCoerce::toString(__('eurosite.payment_terms')),
    'fee_line' => TypeCoerce::toString(__('eurosite.cancel_fee_line')),
    'unavailable' => TypeCoerce::toString(__('eurosite.fees_confirmed_at_booking')),
];
$currency = TypeCoerce::toString($snapshot['currency']);

try {
    $hotelRow = Container::hotels()->findByProductCode(TypeCoerce::toString($snapshot['product_code']));
    $tourop = $hotelRow !== null ? TypeCoerce::toString($hotelRow['tourop_code'] ?? '') : '';

    $fees = Container::getApi()->getItemFees([
        'currency'     => TypeCoerce::toString($snapshot['currency']),
        'country_code' => TypeCoerce::toString($snapshot['country_code']),
        'city_code'    => TypeCoerce::toString($snapshot['city_code']),
        'product_code' => TypeCoerce::toString($snapshot['product_code']),
        'variant_id'   => TypeCoerce::toString($snapshot['variant_id']),
        'check_in'     => TypeCoerce::toString($snapshot['check_in']),
        'check_out'    => TypeCoerce::toString($snapshot['check_out']),
        'tourop_code'  => $tourop,
        'rooms'        => RoomOccupancy::itemRooms($snapshot),
    ]);

    if ($designA) {
        echo json_encode(['sections' => DesignARates::termsSections($fees, $paymentLines, $currency, $designATexts, TravelCoreConfig::getDateFormat())], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $feeRows = [];
    foreach ($fees as $fee) {
        $feeRows[] = [
            'from'  => (string) $fee['from_date'],
            'to'    => (string) $fee['to_date'],
            'value' => $fee['is_percent']
                ? rtrim(rtrim(number_format($fee['value'], 2), '0'), '.') . '%'
                : number_format($fee['value'], 2) . ' ' . TypeCoerce::toString($snapshot['currency']),
        ];
    }

    echo json_encode([
        'success'       => true,
        'cancellation'  => $feeRows,
        'payment_lines' => $paymentLines,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Eurosite offer_terms failed: ' . $e->getMessage()]);
    if ($designA) {
        echo json_encode(['sections' => DesignARates::termsSections([], $paymentLines, $currency, $designATexts, TravelCoreConfig::getDateFormat())], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success'       => true,
        'cancellation'  => [],
        'unavailable'   => true,
        'payment_lines' => $paymentLines,
    ], JSON_UNESCAPED_UNICODE);
}

exit;
