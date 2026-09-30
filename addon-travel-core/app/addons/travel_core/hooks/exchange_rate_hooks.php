<?php
declare(strict_types=1);
/**
 * Travel Core - Exchange Rate Hook Definitions
 *
 * Hook: travel_core_exchange_rates_updated
 *   Fired after BNR exchange rates are successfully updated in CS-Cart currencies.
 *   Provider addons should implement fn_{addon}_travel_core_exchange_rates_updated()
 *   to log the result to their own sync tables.
 *
 *   Parameters:
 *     &$result (array) - Full result array from fn_travel_core_update_exchange_rates():
 *       'success'          => bool
 *       'message'          => string
 *       'bnr_rates'        => array  (currency => RON rate)
 *       'publishing_date'  => string
 *       'commission'       => float
 *       'coefficients'     => array  (currency => value of 1 unit in the primary currency)
 *       'updates'          => array  (currency => update result)
 *       'timestamp'        => string
 *
 * @package TravelCore
 * @since   1.1.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }
