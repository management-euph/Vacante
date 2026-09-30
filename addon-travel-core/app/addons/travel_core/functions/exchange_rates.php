<?php
declare(strict_types=1);
/**
 * Travel Core - Exchange Rates Functions
 *
 * Handles automatic exchange rate updates from BNR (National Bank of Romania).
 * Updates CS-Cart currency coefficients with rates + optional commission.
 * Shared across all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

use Tygh\Registry;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Fetch exchange rates XML from BNR (National Bank of Romania)
 *
 * @return string|false XML content or false on failure
 */
function fn_travel_core_fetch_bnr_rates(): string|false
{
    $url = TravelConstants::BNR_RATES_URL;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml',
            'User-Agent: CS-Cart/TravelCore'
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR exchange rate fetch failed: ' . $error
        ]);
        return false;
    }

    if ($http_code !== 200) {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR exchange rate fetch failed with HTTP ' . $http_code
        ]);
        return false;
    }

    if (empty($response) || !is_string($response)) {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR exchange rate fetch returned empty response'
        ]);
        return false;
    }

    return $response;
}

/**
 * Parse BNR XML and extract rates for specified currencies
 *
 * @param string $xml_content Raw XML from BNR
 * @param array<int, string> $currencies Currency codes to extract (default: EUR, USD, GBP)
 * @param bool $include_date If true, returns array with 'rates' and 'publishing_date' keys
 * @return array<string, mixed> Associative array of currency => rate (relative to RON)
 */
function fn_travel_core_parse_bnr_xml($xml_content, $currencies = ['EUR', 'USD', 'GBP'], $include_date = false): array
{
    $rates = [];
    $publishing_date = '';

    if (empty($xml_content)) {
        return $include_date ? ['rates' => $rates, 'publishing_date' => $publishing_date] : $rates;
    }

    libxml_use_internal_errors(true);

    try {
        $xml = new SimpleXMLElement($xml_content);
        $xml->registerXPathNamespace('bnr', 'http://www.bnr.ro/xsd');

        $cubes = $xml->xpath('//bnr:Cube');
        if (empty($cubes)) {
            $cubes = $xml->Body->Cube;
        }

        if (!empty($cubes)) {
            $cube = is_array($cubes) ? $cubes[0] : $cubes;

            if (isset($cube['date'])) {
                $publishing_date = (string) $cube['date'];
            }

            foreach ($cube->Rate as $rate) {
                $currency = (string) $rate['currency'];

                if (in_array($currency, $currencies)) {
                    $value = (float) $rate;

                    $multiplier = 1;
                    if (isset($rate['multiplier']) && (string)$rate['multiplier'] !== '') {
                        $multiplier = (int) $rate['multiplier'];
                    }
                    if ($multiplier > 1) {
                        $value = $value / $multiplier;
                    }

                    $rates[$currency] = $value;
                }
            }
        }
    } catch (Exception $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR XML parsing error: ' . $e->getMessage()
        ]);
    }

    libxml_clear_errors();

    return $include_date ? ['rates' => $rates, 'publishing_date' => $publishing_date] : $rates;
}

/**
 * Calculate CS-Cart currency coefficients from BNR rates
 *
 * CS-Cart's convention (fn_format_price_by_currency): a coefficient is what
 * ONE unit of the currency is worth in the store's PRIMARY currency (primary =
 * 1). BNR publishes RON per unit, so with RON itself at 1:
 *   coefficient(X) = rate(X) / rate(primary)
 * e.g. on a USD store 1 EUR = 4.975 / 4.58 = 1.0862 USD.
 *
 * The commission makes the shopper pay more in a foreign currency: a price
 * shown in X is primary / coefficient(X), so the coefficient is divided by
 * (1 + commission).
 *
 * (Earlier builds assumed EUR primary and wrote "units per EUR" — the inverse
 * — so CS-Cart divided where it had to multiply.)
 *
 * @param array<int|string, mixed> $bnr_rates Rates from BNR (currency => RON rate)
 * @param float $commission Commission percentage to add (e.g., 2 for 2%)
 * @param string $primary The store's primary currency code
 * @return array<string, float> currency => coefficient, primary excluded; [] when the primary has no rate
 */
function fn_travel_core_calculate_currency_coefficients($bnr_rates, $commission = 0, string $primary = 'EUR'): array
{
    $rates = ['RON' => 1.0];
    foreach ($bnr_rates as $code => $rate) {
        $value = TypeCoerce::toFloat($rate);
        if (is_string($code) && $value > 0) {
            $rates[$code] = $value;
        }
    }

    $primary = strtoupper($primary);
    if (!isset($rates[$primary]) || count($rates) < 2) {
        return [];
    }

    $commission_multiplier = 1 + ($commission / 100);
    $coefficients = [];
    foreach ($rates as $code => $rate) {
        if ($code === $primary) {
            continue;
        }
        $coefficients[$code] = round($rate / $rates[$primary] / $commission_multiplier, 5);
    }

    return $coefficients;
}

/**
 * Update CS-Cart currency rates using direct SQL
 *
 * @param array<string, mixed> $coefficients Currency coefficients to update
 * @return array<string, mixed> Results with success/error info per currency
 */
function fn_travel_core_update_cscart_currencies($coefficients): array
{
    $results = [];

    // Validate first (reads outside the transaction): a currency that is not
    // installed in the store is a soft skip, not a failure of the whole batch.
    /** @var array<string, array{old: mixed, new: float}> $pending */
    $pending = [];

    foreach ($coefficients as $currency_code => $coefficient) {
        $currency = db_get_row(
            "SELECT * FROM ?:currencies WHERE currency_code = ?s",
            $currency_code
        );

        if (!is_array($currency) || $currency === []) {
            $results[$currency_code] = [
                'success' => false,
                'error' => 'Currency not found in CS-Cart'
            ];
            continue;
        }

        // Skip the primary currency (its coefficient is always 1)
        if (($currency['is_primary'] ?? '') === 'Y') {
            $results[$currency_code] = [
                'success' => true,
                'message' => 'Primary currency - coefficient unchanged',
                'old_rate' => $currency['coefficient'] ?? null,
                'new_rate' => 1.0
            ];
            continue;
        }

        $pending[$currency_code] = [
            'old' => $currency['coefficient'] ?? null,
            'new' => TypeCoerce::toFloat($coefficient),
        ];
    }

    // Apply all coefficient updates atomically. Customer-facing prices must not
    // mix old and new rates, so a mid-batch failure — including a verify-readback
    // mismatch — rolls the WHOLE batch back and leaves every rate unchanged.
    if ($pending !== []) {
        db_query('START TRANSACTION');
        try {
            foreach ($pending as $currency_code => $update) {
                // Bind the coefficient as an explicit, locale-safe decimal STRING.
                // The Tygh '?d' placeholder does not reliably persist the full
                // fractional precision of an exchange coefficient (it reformats /
                // rounds the value), so the verify-readback below saw a different
                // number than intended and rolled the WHOLE batch back on every
                // run ("rates unchanged"). currencies.coefficient is decimal(20,10),
                // so a "%.6F" string (locale-independent '.' separator, 6 dp covers
                // our 4-dp inputs) round-trips exactly. Do NOT revert to ?d here.
                db_query(
                    "UPDATE ?:currencies SET coefficient = ?s WHERE currency_code = ?s",
                    sprintf('%.6F', $update['new']),
                    $currency_code
                );

                $stored = TypeCoerce::toFloat(db_get_field(
                    "SELECT coefficient FROM ?:currencies WHERE currency_code = ?s",
                    $currency_code
                ));

                if (abs($stored - $update['new']) > 0.001) {
                    throw new \RuntimeException(sprintf(
                        'Currency coefficient mismatch after update for %s: expected %s, got %s',
                        $currency_code,
                        $update['new'],
                        $stored
                    ));
                }

                $results[$currency_code] = [
                    'success' => true,
                    'old_rate' => $update['old'],
                    'new_rate' => $stored
                ];
            }

            db_query('COMMIT');
        } catch (\Throwable $e) {
            db_query('ROLLBACK');

            fn_log_event('general', 'runtime', [
                'message' => 'Currency coefficient update rolled back — no rates were changed: ' . $e->getMessage(),
            ]);

            foreach ($pending as $currency_code => $update) {
                $results[$currency_code] = [
                    'success' => false,
                    'rolled_back' => true,
                    'error' => 'Rolled back: ' . $e->getMessage(),
                    'old_rate' => $update['old'],
                ];
            }
        }
    }

    if (function_exists('fn_clear_cache')) {
        fn_clear_cache('currencies');
    }

    Registry::del('currencies');

    return $results;
}

/**
 * Main function to update exchange rates from BNR
 *
 * Fetches rates from BNR, applies commission, and updates CS-Cart currencies.
 * Provider addons should call this from their cron infrastructure.
 *
 * @param float $commission Commission percentage to apply (0-5%)
 * @param bool $return_details If true, returns detailed results instead of just success/fail
 * @return array<string, mixed>|bool Results array or bool success
 */
function fn_travel_core_update_exchange_rates(float $commission = 0.0, bool $return_details = false): array|bool
{
    $commission = max(0.0, min(5.0, $commission));

    $result = [
        'success' => false,
        'message' => '',
        'bnr_rates' => [],
        'publishing_date' => '',
        'commission' => $commission,
        'coefficients' => [],
        'updates' => [],
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    // Step 1: Fetch BNR XML
    $xml = fn_travel_core_fetch_bnr_rates();
    if ($xml === false) {
        $result['message'] = 'Failed to fetch exchange rates from BNR';
        return $return_details ? $result : false;
    }

    // Step 2: Parse XML for EUR, USD, GBP (with publishing date)
    $parsed = fn_travel_core_parse_bnr_xml($xml, ['EUR', 'USD', 'GBP'], true);
    $bnr_rates = is_array($parsed['rates'] ?? null) ? $parsed['rates'] : [];
    $result['publishing_date'] = $parsed['publishing_date'] ?? '';

    if (empty($bnr_rates)) {
        $result['message'] = 'Failed to parse BNR exchange rates';
        return $return_details ? $result : false;
    }

    if (empty($bnr_rates['EUR'])) {
        $result['message'] = 'EUR rate not found in BNR response';
        return $return_details ? $result : false;
    }
    $result['bnr_rates'] = $bnr_rates;

    // Step 3: Calculate coefficients relative to the store's primary currency
    $primary = defined('CART_PRIMARY_CURRENCY')
        ? TypeCoerce::toString(CART_PRIMARY_CURRENCY)
        : TypeCoerce::toString(db_get_field("SELECT currency_code FROM ?:currencies WHERE is_primary = 'Y'"));
    $coefficients = fn_travel_core_calculate_currency_coefficients($bnr_rates, $commission, $primary !== '' ? $primary : 'EUR');
    if (empty($coefficients)) {
        $result['message'] = 'Failed to calculate currency coefficients';
        return $return_details ? $result : false;
    }
    $result['coefficients'] = $coefficients;

    // Step 4: Update CS-Cart currencies
    $updates = fn_travel_core_update_cscart_currencies($coefficients);
    $result['updates'] = $updates;

    // A rolled-back batch means NO rate changed — report failure, not success.
    // ('Currency not found' entries stay soft skips: a store without e.g. GBP
    // installed is a valid configuration, not an error.)
    $rolled_back = array_filter(
        $updates,
        static fn ($u): bool => is_array($u) && !empty($u['rolled_back'])
    );
    if ($rolled_back !== []) {
        $result['message'] = 'Currency coefficient update was rolled back — rates unchanged';
        // Still notify provider addons so the failure lands in their sync logs.
        fn_set_hook('travel_core_exchange_rates_updated', $result);
        return $return_details ? $result : false;
    }

    $result['success'] = true;
    $result['message'] = 'Exchange rates updated successfully';
    $result['timestamp'] = date('Y-m-d H:i:s');

    fn_log_event('general', 'runtime', [
        'message' => sprintf(
            'Exchange rates updated (per 1 unit, in %s): %s (commission: %s%%)',
            $primary,
            implode(', ', array_map(
                static fn (string $code, float $coefficient): string => $code . '=' . $coefficient,
                array_keys($coefficients),
                $coefficients,
            )),
            $commission
        )
    ]);

    // Notify provider addons so they can log to their own sync tables
    fn_set_hook('travel_core_exchange_rates_updated', $result);

    return $return_details ? $result : true;
}

/**
 * Format exchange rate update result as plain-text output.
 *
 * Shared by cron.php and travel_cron controller to avoid duplicating
 * the same formatting logic in multiple entry points.
 *
 * @param array<string, mixed> $result Result array from fn_travel_core_update_exchange_rates()
 * @return string Formatted plain-text output
 */
function fn_travel_core_format_exchange_rate_output(array $result): string
{
    $lines = [];

    $lines[] = 'Status: ' . (!empty($result['success']) ? 'SUCCESS' : 'FAILED');
    $lines[] = 'Message: ' . TypeCoerce::toString($result['message'] ?? 'Unknown');

    if (!empty($result['publishing_date'])) {
        $lines[] = 'Publishing Date: ' . TypeCoerce::toString($result['publishing_date']);
    }

    $bnrRates = is_array($result['bnr_rates'] ?? null) ? $result['bnr_rates'] : [];
    if (!empty($bnrRates)) {
        $lines[] = '';
        $lines[] = 'BNR Rates (RON-based):';
        foreach ($bnrRates as $currency => $rate) {
            $lines[] = '  ' . TypeCoerce::toString($currency) . ': ' . TypeCoerce::toString($rate);
        }
    }

    $coefficients = is_array($result['coefficients'] ?? null) ? $result['coefficients'] : [];
    if (!empty($coefficients)) {
        $lines[] = '';
        $lines[] = 'Calculated Coefficients (EUR-based, commission: ' . TypeCoerce::toString($result['commission'] ?? 0) . '%):';
        foreach ($coefficients as $currency => $coefficient) {
            $lines[] = '  ' . TypeCoerce::toString($currency) . ': ' . TypeCoerce::toString($coefficient);
        }
    }

    $updates = is_array($result['updates'] ?? null) ? $result['updates'] : [];
    if (!empty($updates)) {
        $lines[] = '';
        $lines[] = 'Update Results:';
        foreach ($updates as $currency => $update) {
            $update = is_array($update) ? $update : [];
            if (!empty($update['success'])) {
                $lines[] = '  ' . TypeCoerce::toString($currency) . ': ' . TypeCoerce::toString($update['old_rate'] ?? '-') . ' -> ' . TypeCoerce::toString($update['new_rate'] ?? '-');
            } else {
                $lines[] = '  ' . TypeCoerce::toString($currency) . ': FAILED - ' . TypeCoerce::toString($update['error'] ?? 'Unknown');
            }
        }
    }

    return implode("\n", $lines);
}
