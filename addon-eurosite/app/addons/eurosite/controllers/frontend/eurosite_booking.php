<?php

declare(strict_types=1);
/**
 * Eurosite Touring — storefront booking controller (thin mode router).
 *
 * Each mode body lives in its own file under eurosite_booking/ — the
 * novoton/sphinx dispatcher pattern. The allowlist below is the single
 * routing truth; unknown modes 404 via CS-Cart's default handling.
 *
 * Modes:
 *   search       — a hotel product page's live price search (hotel_id)
 *   offer_terms  — AJAX JSON: cancellation fees + payment terms for an offer
 *   booking_form — guest booking form for a snapshotted offer
 *   add_to_cart  — persist booking + cart line (POST)
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

$allowed_modes = [
    'search'       => 'search.php',
    'offer_terms'  => 'offer_terms.php',
    'booking_form' => 'booking_form.php',
    'add_to_cart'  => 'add_to_cart.php',
];

$mode_name = is_string($mode ?? null) ? $mode : '';
if (isset($allowed_modes[$mode_name])) {
    $result = require __DIR__ . '/eurosite_booking/' . $allowed_modes[$mode_name];

    return is_array($result) ? $result : null;
}

return [CONTROLLER_STATUS_NO_PAGE];
