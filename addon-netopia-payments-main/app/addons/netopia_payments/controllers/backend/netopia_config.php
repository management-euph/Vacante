<?php

/**
 * NETOPIA Payments — admin helpers for the payment method settings screen.
 *
 * Routes:
 *   POST dispatch=netopia_config.test (AJAX) — "Check settings": checks the
 *        selected mode's API key, POS signature and public key as typed into
 *        the form, before they are saved. -> data.netopia_test
 *
 *        mode, api_key, pos_signature   what the form holds now for that mode
 *        other_api_key                  the other mode's key (same-key warning)
 *        public_key                     a key pasted or picked in the form ('' = the saved one)
 *        payment_id                     the payment method, for the saved values
 *
 * Nothing is saved and NETOPIA is not called: its API documents no way to
 * validate a key without a payment (see Config\SettingsCheck).
 *
 * @package NetopiaPayments
 */

use Netopia\CsCart\Bootstrap;
use Netopia\CsCart\Config\Credentials;
use Netopia\CsCart\Config\SettingsCheck;
use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $mode !== 'test') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

$request = Arr::stringKeys($_REQUEST);
$payment_id = Arr::int($request, 'payment_id');
$saved = $payment_id > 0 ? fn_netopia_load_processor_params($payment_id) : [];
$payment_mode = PaymentMode::fromMixed($request['mode'] ?? ($saved['mode'] ?? null));

// What the form holds for the selected mode; the saved pair when a field is empty.
$saved_pair = Credentials::forMode($saved, $payment_mode);
$api_key = trim(Arr::string($request, 'api_key'));
if ($api_key === '') {
    $api_key = $saved_pair['api_key'];
}
$pos_signature = trim(Arr::string($request, 'pos_signature'));
if ($pos_signature === '') {
    $pos_signature = $saved_pair['pos_signature'];
}

$bootstrap = Bootstrap::instance();
$public_key = trim(Arr::string($request, 'public_key'));
if ($public_key === '' && $payment_id > 0) {
    $public_key = $bootstrap->keyStorage->load($saved, 'public_key', $payment_id, $payment_mode);
}

// The other mode's key, to catch the same key pasted into both modes.
$other_mode = $payment_mode === PaymentMode::Live ? PaymentMode::Sandbox : PaymentMode::Live;
$other_api_key = trim(Arr::string($request, 'other_api_key'));
if ($other_api_key === '') {
    $other_api_key = Credentials::forMode($saved, $other_mode)['api_key'];
}

$result = SettingsCheck::run($api_key, $other_api_key, $pos_signature, $payment_mode, $public_key, time());

$checks = [];
foreach ($result['checks'] as $check) {
    $checks[] = [
        'id' => $check['id'],
        'state' => $check['state'],
        'text' => (string) __($check['lang_key'], $check['params']),
    ];
}

$payload = [
    'ready' => $result['ready'],
    'summary' => (string) __($result['ready'] ? 'netopia_test_ready' : 'netopia_test_not_ready', ['[mode]' => $payment_mode->value]),
    'checks' => $checks,
];

if (defined('AJAX_REQUEST')) {
    $ajax = Tygh::$app->offsetExists('ajax') ? Tygh::$app->offsetGet('ajax') : null;
    if (is_object($ajax) && method_exists($ajax, 'assign')) {
        $ajax->assign('netopia_test', $payload);
    }
}

return [CONTROLLER_STATUS_NO_CONTENT];
