<?php

/**
 * NETOPIA Payments helper functions for CS-Cart.
 *
 * This file is intentionally thin: each fn_netopia_* function is a wrapper
 * around a service in app/addons/netopia_payments/lib/. Business logic lives
 * in the PSR-4 Netopia\CsCart namespace — see lib/Bootstrap.php for the
 * object-graph composition root.
 *
 * @package NetopiaPayments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Netopia\CsCart\Bootstrap;
use Netopia\CsCart\Exception\KeyStorageException;
use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\CountryCodes;
use Netopia\CsCart\Support\Sanitizer;
use Netopia\Payment2\Enum\PaymentMode;
use Tygh\Tygh;

// ---------------------------------------------------------------------------
// Addon lifecycle (install / uninstall)
// ---------------------------------------------------------------------------

/**
 * Lifecycle install hook. Belt-and-braces seeds the payment_info field
 * labels in case CS-Cart's .po import didn't run; the customer retry-payment
 * email template is handled separately via the declarative
 * `<email_templates>` block in addon.xml (canonical mechanism per
 * developer_guide/core/documents/email_notifications.html).
 */
function fn_netopia_payments_install(): void
{
    \Netopia\CsCart\Install\Seeder::forRuntime()->seedLabelsOnInstall();
}

/**
 * Removes the language_values rows seeded by Install\Seeder. The leading
 * underscore is CS-Cart's convention for `payment_info` field labels —
 * the value side of `payment_info` is keyed without it, but the label
 * lookup table prefixes the column name with `_`.
 */
function fn_netopia_payments_uninstall(): void
{
    db_query(
        'DELETE FROM ?:language_values WHERE name IN '
        . "('_netopia_payment_link', '_netopia_payment_link_at', "
        . "'_netopia_amount', '_netopia_refunded_amount', "
        . "'_netopia_refund_log', '_netopia_order_id')",
    );
}

// ---------------------------------------------------------------------------
// Key management
// ---------------------------------------------------------------------------

/**
 * Returns the directory path where NETOPIA key files are stored.
 */
function fn_netopia_get_keys_dir(int $payment_id): string
{
    return Bootstrap::instance()->keyStorage->dirFor($payment_id);
}

/**
 * Load a NETOPIA key from file or from processor_params textarea fallback.
 *
 * @param array<string, mixed> $processor_params
 * @param 'public_key'|'private_key' $key_type
 * @param int $payment_id the payment method whose key directory is read
 * @param string $mode 'live' or 'sandbox'; '' uses $processor_params['mode']
 * @return string the key's PEM text; '' when none is configured
 */
function fn_netopia_load_key(array $processor_params, string $key_type, int $payment_id, string $mode = ''): string
{
    $paymentMode = $mode !== ''
        ? PaymentMode::fromMixed($mode)
        : PaymentMode::fromMixed($processor_params['mode'] ?? null);

    return Bootstrap::instance()->keyStorage->load($processor_params, $key_type, $payment_id, $paymentMode);
}

/**
 * Read a key file with path traversal protection.
 */
function fn_netopia_read_key_file(string $keys_dir, string $filename): string
{
    return Bootstrap::instance()->keyStorage->readFile($keys_dir, $filename);
}

// ---------------------------------------------------------------------------
// Key upload hook
// ---------------------------------------------------------------------------

/**
 * Hook: handle file uploads when a NETOPIA payment method is saved.
 *
 * @param array<string, mixed> $payment_data
 */
function fn_netopia_payments_update_payment_post(array $payment_data, int $payment_id, string $lang_code = ''): void
{
    if (empty($payment_data['processor_id'])) {
        return;
    }

    $processor_info = db_get_row('SELECT * FROM ?:payment_processors WHERE processor_id = ?i', $payment_data['processor_id']);
    if (empty($processor_info) || $processor_info['processor_script'] !== 'netopia_payments.php') {
        return;
    }

    $keyStorage = Bootstrap::instance()->keyStorage;
    $keys_dir = $keyStorage->dirFor($payment_id);
    $params = fn_netopia_load_processor_params($payment_id);
    $updated = false;

    $key_slots = [
        'sandbox_public_key' => 'netopia_sandbox_public_key_file',
        'sandbox_private_key' => 'netopia_sandbox_private_key_file',
        'live_public_key' => 'netopia_live_public_key_file',
        'live_private_key' => 'netopia_live_private_key_file',
    ];

    /** @var array<string, string> $uploaded_signatures mode => POS signature in the name of a key uploaded now */
    $uploaded_signatures = [];
    foreach ($key_slots as $param_key => $file_input_name) {
        $raw = $_FILES[$file_input_name] ?? null;
        if (!is_array($raw) || empty($raw['name']) || ($raw['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $upload = [
            'name' => is_string($raw['name']) ? $raw['name'] : '',
            'tmp_name' => is_string($raw['tmp_name'] ?? null) ? $raw['tmp_name'] : '',
            'size' => is_numeric($raw['size'] ?? null) ? (int) $raw['size'] : 0,
            'error' => Arr::int($raw, 'error'),
        ];

        // A sandbox.* file in a live slot (or the other way round) would make
        // that mode verify NETOPIA's notifications with the wrong key.
        $slot_mode = PaymentMode::fromMixed(strtok($param_key, '_'));
        $file_mode = \Netopia\CsCart\Key\KeyFileName::wrongMode($upload['name'], $slot_mode);
        if ($file_mode !== null) {
            fn_set_notification('W', __('warning'), __('netopia_key_wrong_mode', [
                '[file]' => htmlspecialchars($upload['name'], ENT_QUOTES, 'UTF-8'),
                '[file_mode]' => __('netopia_' . $file_mode->value),
                '[slot_mode]' => __('netopia_' . $slot_mode->value),
            ]));
            continue;
        }

        try {
            $keyStorage->secureDir($keys_dir);

            $file_field = $param_key . '_file';
            if (!empty($params[$file_field])) {
                $keyStorage->delete($keys_dir, Arr::string($params, $file_field));
            }

            $content = file_get_contents($upload['tmp_name']);
            $stored = $keyStorage->storeUpload($upload, $keys_dir);

            $params[$file_field] = $stored;
            $params[$param_key] = $content !== false ? trim($content) : '';
            $updated = true;
            $uploaded_signature = \Netopia\CsCart\Key\KeyFileName::posSignature($upload['name']);
            if ($uploaded_signature !== '') {
                $uploaded_signatures[$slot_mode->value] = $uploaded_signature;
            }

            fn_set_notification('N', __('notice'), __('netopia_key_uploaded', ['[key]' => $param_key]));
        } catch (KeyStorageException $e) {
            fn_set_notification('W', __('warning'), $e->getMessage());
        }
    }

    foreach (array_keys($key_slots) as $param_key) {
        if (empty($_POST['delete_netopia_' . $param_key])) {
            continue;
        }
        $file_field = $param_key . '_file';
        if (!empty($params[$file_field])) {
            if (!$keyStorage->delete($keys_dir, Arr::string($params, $file_field))) {
                fn_set_notification('W', __('warning'), __('netopia_key_delete_failed'));
            }
            unset($params[$file_field]);
            $params[$param_key] = '';
            $updated = true;
        }
    }

    // NETOPIA's key files carry the POS signature in their names: store it for
    // a mode whose POS signature was left empty, so it never has to be typed.
    // A key uploaded now for another POS replaces the stored signature: the
    // old one would sign payments for a POS whose keys are gone.
    foreach (PaymentMode::cases() as $mode) {
        $pos_field = $mode->value . '_pos_signature';
        $typed = trim(Arr::string($params, $pos_field));
        $from_key = $typed === ''
            ? \Netopia\CsCart\Config\Credentials::posSignatureFromKeyFiles($params, $mode)
            : ($uploaded_signatures[$mode->value] ?? '');
        if ($from_key !== '' && $from_key !== $typed) {
            $params[$pos_field] = $from_key;
            $updated = true;
            fn_set_notification('N', __('notice'), __('netopia_pos_signature_from_key', [
                '[mode]' => __('netopia_' . $mode->value),
                '[signature]' => $from_key,
            ]));
        }
    }

    // The runtime reads the plain api_key / pos_signature: make them the
    // selected mode's pair (Config\Credentials).
    $active = \Netopia\CsCart\Config\Credentials::applyActive($params);
    if ($active !== $params) {
        $params = $active;
        $updated = true;
    }

    if ($updated) {
        db_query('UPDATE ?:payments SET processor_params = ?s WHERE payment_id = ?i', serialize($params), $payment_id);
    }

    // Post-save sanity check: the IPN verifier will refuse every callback
    // from NETOPIA unless the selected mode has a public key uploaded or
    // pasted. Without this notice merchants only discover the issue after
    // a test order gets stuck in "Open" with no feedback in the admin.
    $selectedMode = PaymentMode::fromMixed($params['mode'] ?? null);
    $publicKeyForMode = $keyStorage->load($params, 'public_key', $payment_id, $selectedMode);
    if ($publicKeyForMode === '') {
        fn_set_notification(
            'W',
            __('warning'),
            __('netopia_public_key_missing_warning', ['[mode]' => $selectedMode->value]),
        );
    }
}

/**
 * Load processor params from the database for a given payment ID.
 *
 * CS-Cart stores `processor_params` as PHP `serialize()`d arrays and its core
 * readers (e.g. `fn_get_payment_method_data`) rely on that format — the addon
 * must match. We constrain the attack surface by (a) refusing to unserialize
 * anything that is not an array payload, and (b) passing
 * `allowed_classes => false` so magic methods on arbitrary classes cannot fire.
 *
 * @return array<string, mixed>
 */
function fn_netopia_load_processor_params(int $payment_id): array
{
    $payment_row = db_get_row('SELECT processor_params FROM ?:payments WHERE payment_id = ?i', $payment_id);
    $raw = $payment_row['processor_params'] ?? null;
    if (!is_string($raw) || $raw === '' || !str_starts_with($raw, 'a:')) {
        return [];
    }

    $params = unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($params)) {
        return [];
    }

    $result = [];
    foreach ($params as $k => $v) {
        if (is_string($k)) {
            $result[$k] = $v;
        }
    }
    return $result;
}

/**
 * CS-Cart's payment method data with the NETOPIA credentials resolved
 * (Config\Credentials::applyActive): the selected mode's API key and POS
 * signature, the signature taken from the key file names when it was left
 * empty. Every runtime reader goes through this, so a configuration saved
 * before a credentials rule changed still pays and verifies correctly.
 *
 * @return array<string, mixed>
 */
function fn_netopia_get_payment_method_data(int $payment_id): array
{
    $data = Arr::stringKeys(fn_get_payment_method_data($payment_id));
    if (is_array($data['processor_params'] ?? null)) {
        $data['processor_params'] = \Netopia\CsCart\Config\Credentials::applyActive(Arr::stringKeys($data['processor_params']));
    }

    return $data;
}

/**
 * Write security files (.htaccess, index.html) to prevent direct web access.
 */
function fn_netopia_secure_keys_dir(string $dir): void
{
    Bootstrap::instance()->keyStorage->secureDir($dir);
}

// ---------------------------------------------------------------------------
// Country code mapping
// ---------------------------------------------------------------------------

/**
 * ISO 3166-1 alpha-2 to numeric country code.
 */
function fn_netopia_get_country_numeric_code(string $alpha2): int
{
    return CountryCodes::toNumeric($alpha2);
}

// ---------------------------------------------------------------------------
// API communication
// ---------------------------------------------------------------------------

/**
 * Send an HTTP request to the NETOPIA API.
 *
 * @return array{status: int, code: int, message: string, data: array<string, mixed>|null}
 */
function fn_netopia_api_request(string $endpoint, string $json_body, string $api_key, bool $is_live = false): array
{
    $mode = $is_live ? PaymentMode::Live : PaymentMode::Sandbox;
    $client = Bootstrap::instance()->apiClientFor($api_key, $mode);

    try {
        $response = $client->post($endpoint, $json_body);
    } catch (\Netopia\CsCart\Exception\ApiException $e) {
        return ['status' => 0, 'code' => 0, 'message' => $e->getMessage(), 'data' => null];
    }

    return [
        'status' => $response->status,
        'code' => $response->code,
        'message' => $response->message,
        'data' => $response->data,
    ];
}

// ---------------------------------------------------------------------------
// 3D Secure browser fingerprint helpers
// ---------------------------------------------------------------------------

/**
 * Collect 3D Secure browser fingerprint data.
 *
 * @return array<string, string>
 */
function fn_netopia_get_3ds_data(): array
{
    return Bootstrap::instance()->threeDsFactory->fromRequest(Arr::stringKeys($_SERVER), Arr::stringKeys($_POST))->toArray();
}

/** Sanitize a 3DS browser fingerprint field value. */
function fn_netopia_sanitize_3ds_field(string $value): string
{
    return Sanitizer::threeDsField($value);
}

/** Sanitize and validate an IP address string. */
function fn_netopia_sanitize_ip(string $ip): string
{
    return Sanitizer::ipAddress($ip);
}

/** Validate that a URL returned by NETOPIA is a safe HTTPS URL. */
function fn_netopia_validate_url(string $url): bool
{
    return Sanitizer::isSafeHttpsUrl($url);
}

// ---------------------------------------------------------------------------
// Callback handlers (IPN & 3DS return)
// ---------------------------------------------------------------------------

/**
 * Handle IPN (Instant Payment Notification) from NETOPIA.
 */
function fn_netopia_handle_ipn(): void
{
    $raw_post = (string) (file_get_contents('php://input') ?: '');
    $token = fn_netopia_extract_verification_token();

    // Reject IPN requests that do not carry a Verification-Token header outright,
    // before any downstream parsing or lookups occur. Log the source IP so that
    // repeated probing is visible in the merchant's monitoring.
    if ($token === null || $token === '') {
        $remoteIp = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : 'unknown';
        Bootstrap::instance()->logger->warning(
            'NETOPIA IPN rejected: missing Verification-Token header',
            ['remote_ip' => $remoteIp],
        );
        fn_netopia_ipn_response(2, 1, 'Missing Verification-Token header');
    }

    $handler = Bootstrap::instance()->ipnHandler(
        orderLookup:         static fn (int $orderId): ?array => fn_get_order_info($orderId) ?: null,
        processorDataLookup: static fn (int $paymentId): ?array => (fn_netopia_get_payment_method_data($paymentId) ?: null),
        paymentInfoUpdater:  static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, Arr::stringKeys($info));
        },
        paymentFinalizer:    static function (int $orderId, array $response): void {
            fn_finish_payment($orderId, Arr::stringKeys($response));
        },
        orderStatusChanger:  static function (int $orderId, string $status, string $reason, bool $notify): void {
            // Direct transition for retry IPNs against already-terminal orders.
            // CS-Cart's fn_change_order_status signature is
            // ($order_id, $status_to, $status_from, $force_notification, ...);
            // the 3rd arg is the PREVIOUS status code, NOT a reason text.
            // Passing $reason there triggered "Undefined array key" warnings
            // because CS-Cart looks the value up in the order_statuses array.
            // The reason is still captured in payment_info.reason_text and
            // (for refunds) netopia_refund_log.
            unset($reason);
            fn_change_order_status($orderId, $status, '', $notify);
        },
        responder:           static function (int $errorType, int $errorCode, string $message): void {
            fn_netopia_ipn_response($errorType, $errorCode, $message);
        },
    );

    $handler->handle($raw_post, $token);
}

/**
 * Handle customer return from 3D Secure bank authentication.
 */
function fn_netopia_handle_3ds_return(): void
{
    /** @var \ArrayAccess<string, mixed> $sessionContainer */
    $sessionContainer = Tygh::$app['session'];
    $session = new ThreeDsSessionStore($sessionContainer);

    $handler = Bootstrap::instance()->threeDsReturnHandler(
        session:             $session,
        orderLookup:         static fn (int $orderId): ?array => fn_get_order_info($orderId) ?: null,
        processorDataLookup: static fn (int $paymentId): ?array => (fn_netopia_get_payment_method_data($paymentId) ?: null),
        paymentInfoUpdater:  static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, Arr::stringKeys($info));
        },
        paymentFinalizer:    static function (int $orderId, array $response): void {
            fn_finish_payment($orderId, Arr::stringKeys($response));
        },
        placementRouter:     static function (int $orderId): void {
            fn_order_placement_routines('route', $orderId);
        },
        checkoutRedirect:    static function (string $reason): void {
            fn_set_notification('E', __('error'), __($reason));
            fn_redirect(fn_url('checkout.checkout'));
        },
    );

    $handler->handle(Arr::stringKeys($_POST));
}

/**
 * Handle customer return from NETOPIA hosted payment page.
 *
 * The actual payment confirmation arrives via IPN (notify).
 * This just routes the customer to the order confirmation page.
 */
function fn_netopia_handle_hosted_return(): void
{
    /** @var \ArrayAccess<string, mixed> $sessionContainer */
    $sessionContainer = Tygh::$app['session'];
    $session = new ThreeDsSessionStore($sessionContainer);

    $orderId = $session->orderId();
    $session->clear();

    // Fallback: if no session (e.g. admin-regenerated link or expired session),
    // accept the order_id from the return URL only when it is accompanied by a
    // valid HMAC signature computed by PayloadBuilder::signOrderId() at the
    // time the redirectUrl was issued. This prevents third parties from
    // triggering placement routing on arbitrary order IDs.
    if ($orderId <= 0 && isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
        $candidateOrderId = (int) $_GET['order_id'];
        $candidateSig = isset($_GET['ntp_sig']) && is_string($_GET['ntp_sig']) ? $_GET['ntp_sig'] : '';

        if ($candidateSig !== '' && $candidateOrderId > 0) {
            $orderInfo = fn_get_order_info($candidateOrderId);
            if (is_array($orderInfo) && !empty($orderInfo['payment_id'])) {
                $paymentId = is_numeric($orderInfo['payment_id']) ? (int) $orderInfo['payment_id'] : 0;
                $processorData = fn_netopia_get_payment_method_data($paymentId);
                $apiKey = Arr::string(Arr::array($processorData, 'processor_params'), 'api_key');
                if (
                    $apiKey !== ''
                    && PayloadBuilder::verifyOrderIdSignature((string) $candidateOrderId, $candidateSig, $apiKey)
                ) {
                    $orderId = $candidateOrderId;
                }
            }
        }

        if ($orderId <= 0) {
            Bootstrap::instance()->logger->warning(
                'NETOPIA hosted return rejected: missing or invalid ntp_sig for order_id param',
                ['order_id' => $candidateOrderId],
            );
        }
    }

    if ($orderId > 0) {
        fn_order_placement_routines('route', $orderId);
        return;
    }

    // Last resort: redirect to customer-facing homepage, not admin checkout.
    fn_redirect(fn_url('', 'C', 'current'));
}

/**
 * Send a JSON response back to NETOPIA IPN and end the request.
 */
function fn_netopia_ipn_response(int $error_type, int $error_code, string $message): never
{
    header('Content-Type: application/json');
    echo json_encode([
        'errorType' => $error_type,
        'errorCode' => $error_code,
        'errorMessage' => $message,
    ]);
    exit;
}

/**
 * Extract the Verification-Token from HTTP headers.
 */
function fn_netopia_extract_verification_token(): ?string
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Verification-Token') === 0) {
                return is_string($value) ? $value : '';
            }
        }
    }

    $token = $_SERVER['HTTP_VERIFICATION_TOKEN'] ?? null;

    return is_string($token) ? $token : null;
}

// ---------------------------------------------------------------------------
// Status mapping
// ---------------------------------------------------------------------------

/**
 * Return NETOPIA status definitions: code, label, and default CS-Cart mapping.
 *
 * @param mixed $dummy Unused (required for Smarty modifier compatibility)
 * @return array<int, array{label: string, default: string, group: string}>
 */
function fn_netopia_get_status_definitions($dummy = null): array
{
    return Bootstrap::instance()->statusMapper->definitions();
}

/**
 * The settings screen's status table: common rows, rare rows, changed count.
 *
 * @param mixed $processor_params the saved processor_params (Smarty modifier input)
 * @return array{common: list<array<string, mixed>>, rare: list<array<string, mixed>>, changed: int}
 */
function fn_netopia_status_table($processor_params = []): array
{
    return \Netopia\CsCart\Status\StatusTable::build(is_array($processor_params) ? Arr::stringKeys($processor_params) : []);
}

/**
 * The API key and POS signature a mode uses (settings screen).
 *
 * @param mixed $processor_params
 * @param mixed $mode 'sandbox' or 'live'
 * @return array{api_key: string, pos_signature: string}
 */
function fn_netopia_credentials($processor_params = [], $mode = 'sandbox'): array
{
    return \Netopia\CsCart\Config\Credentials::forMode(
        is_array($processor_params) ? Arr::stringKeys($processor_params) : [],
        PaymentMode::fromMixed($mode),
    );
}

/**
 * Where the settings screen's POS signature comes from: 'typed', 'key_file'
 * (read from the uploaded key file's name) or ''.
 *
 * @param mixed $processor_params
 * @param mixed $mode 'sandbox' or 'live'
 */
function fn_netopia_pos_signature_source($processor_params = [], $mode = 'sandbox'): string
{
    return \Netopia\CsCart\Config\Credentials::posSignatureSource(
        is_array($processor_params) ? Arr::stringKeys($processor_params) : [],
        PaymentMode::fromMixed($mode),
    );
}

/**
 * The key cards: per mode and key type, where the key comes from and its state.
 *
 * @param mixed $processor_params
 * @param mixed $payment_id
 * @return array<string, array<string, array<string, mixed>>>
 */
function fn_netopia_key_overview($processor_params = [], $payment_id = 0): array
{
    $params = is_array($processor_params) ? Arr::stringKeys($processor_params) : [];
    $inspector = new \Netopia\CsCart\Key\KeyInspector(Bootstrap::instance()->keyStorage);

    return $inspector->overview($params, is_numeric($payment_id) ? (int) $payment_id : 0, time());
}

/**
 * Map NETOPIA payment status code to CS-Cart order status.
 *
 * @param array<string, mixed> $processor_params
 */
function fn_netopia_map_order_status(int $netopia_status, array $processor_params = []): string
{
    return Bootstrap::instance()->statusMapper->map($netopia_status, $processor_params);
}

/**
 * Parse `payment_info.netopia_refund_log` into a list of structured rows
 * for the admin order details view (refund modal history table). Each
 * line written by RefundFinalizer matches:
 *
 *   [YYYY-MM-DD HH:MM] AMOUNT — full refund|partial refund (ntpID) [origin]
 *
 * Lines that don't match (e.g. admin DB-edited the field by hand) are
 * skipped silently — the table still renders the parseable rows.
 *
 * @return list<array{date: string, amount: string, kind: string, ntp_id: string, origin: string}>
 */
function fn_netopia_parse_refund_log(string $log): array
{
    if ($log === '') {
        return [];
    }

    $rows = [];
    foreach (explode("\n", $log) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (
            !preg_match(
                '/^\[(?P<date>[^]]+)] (?P<amount>.+?) — (?P<kind>full refund|partial refund) \((?P<ntp_id>[^)]+)\) \[(?P<origin>[^]]+)]$/u',
                $line,
                $m,
            )
        ) {
            continue;
        }
        // The stored format is `Y-m-d H:i` (RefundFinalizer.php:182).
        // Reformat to Romanian convention `d.m.Y H:i` at display time
        // so older rows pick up the new format too without rewriting
        // the stored string. Falls back to the raw value if parsing
        // fails (e.g. someone hand-edited the field) — better to show
        // a slightly off-format date than to drop the row.
        $parsedDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $m['date']);
        $displayDate = $parsedDate === false ? $m['date'] : $parsedDate->format('d.m.Y H:i');
        $rows[] = [
            'date' => $displayDate,
            'amount' => $m['amount'],
            'kind' => $m['kind'] === 'full refund' ? 'full' : 'partial',
            'ntp_id' => $m['ntp_id'],
            'origin' => $m['origin'],
        ];
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Auto-generate payment link on order status change
// ---------------------------------------------------------------------------

/**
 * Hook: change_order_status
 *
 * Fires BEFORE CS-Cart sends the status change notification email.
 * When a NETOPIA order transitions to a configured status (e.g. Failed),
 * auto-generates a payment link so the customer can retry.
 *
 * @param string $status_to   New order status code
 * @param string $status_from Previous order status code
 * @param array<string, mixed> $order_info Order data
 * @param array<string, mixed>|bool $force_notification Notification settings
 * @param array<string, mixed> $order_statuses Available order statuses
 * @param mixed $edp_data EDP data
 */
function fn_netopia_payments_change_order_status(
    string $status_to,
    string $status_from,
    array $order_info,
    array|bool $force_notification,
    array $order_statuses,
    mixed $edp_data,
): void {
    // Check if auto payment link is enabled in addon settings
    $enabled = \Tygh\Registry::get('addons.netopia_payments.auto_payment_link');
    if ($enabled !== 'Y') {
        return;
    }

    // Check if this status is configured for auto payment link
    $configuredStatuses = \Tygh\Registry::get('addons.netopia_payments.auto_payment_link_statuses');
    if (!is_string($configuredStatuses) || $configuredStatuses === '') {
        return;
    }

    $statuses = array_map('trim', explode(',', $configuredStatuses));

    if (!in_array($status_to, $statuses, true)) {
        return;
    }

    // Check if this order uses the NETOPIA processor
    $paymentId = Arr::int($order_info, 'payment_id');
    if ($paymentId <= 0) {
        return;
    }

    /** @var array{processor_script?: string}|false $processor */
    $processor = db_get_row(
        'SELECT pp.processor_script FROM ?:payment_processors pp '
        . 'JOIN ?:payments p ON p.processor_id = pp.processor_id '
        . 'WHERE p.payment_id = ?i',
        $paymentId,
    );

    if (empty($processor) || $processor['processor_script'] !== 'netopia_payments.php') {
        return;
    }

    // Generate payment link without changing order status.
    // The status is already being set by CS-Cart (the caller).
    // We only need the link stored in payment_info before the notification email is sent.
    $order_id = Arr::int($order_info, 'order_id');
    $order_info_fresh = fn_get_order_info($order_id);
    if (empty($order_info_fresh)) {
        return;
    }

    $paymentId = is_numeric($order_info_fresh['payment_id'] ?? null) ? (int) $order_info_fresh['payment_id'] : 0;
    $processor_data = fn_netopia_get_payment_method_data($paymentId);
    if (empty($processor_data['processor_params'])) {
        return;
    }

    $service = Bootstrap::instance()->paymentLinkService(
        paymentInfoUpdater: static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, Arr::stringKeys($info));
        },
    );

    $existingLink = Arr::string(Arr::array($order_info_fresh, 'payment_info'), 'netopia_payment_link');
    $result = $service->generate(
        $order_info_fresh,
        Arr::array($processor_data, 'processor_params'),
        Arr::stringKeys($_SERVER),
    );

    if (!$result['success'] || $result['payment_url'] === '') {
        return;
    }

    if (\Tygh\Registry::get('addons.netopia_payments.auto_send_retry_email') !== 'Y') {
        return;
    }

    // Guard against repeated emails when an admin toggles the same status off
    // and back on: only send if the just-generated link differs from the one
    // already in payment_info (PaymentLinkService just overwrote it, so we
    // captured the previous value above).
    if ($result['payment_url'] === $existingLink) {
        return;
    }

    fn_netopia_send_payment_link_email($order_id, $result['payment_url']);
}

// ---------------------------------------------------------------------------
// Payment link
// ---------------------------------------------------------------------------

/**
 * Generate a NETOPIA payment link for an order.
 *
 * @return array{success: bool, payment_url: string, error: string}
 */
function fn_netopia_generate_payment_link(int $order_id): array
{
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        return ['success' => false, 'payment_url' => '', 'error' => 'Order not found.'];
    }

    $paymentId = is_numeric($order_info['payment_id'] ?? null) ? (int) $order_info['payment_id'] : 0;
    $processor_data = fn_netopia_get_payment_method_data($paymentId);

    // If the order's payment method link is broken (e.g. after addon reinstall),
    // find the current active NETOPIA payment method.
    if (empty($processor_data['processor_params'])) {
        $activePaymentId = (int) db_get_field(
            'SELECT p.payment_id FROM ?:payments p '
            . 'JOIN ?:payment_processors pp ON pp.processor_id = p.processor_id '
            . 'WHERE pp.processor_script = ?s AND p.status = ?s LIMIT 1',
            'netopia_payments.php',
            'A',
        );
        if ($activePaymentId > 0) {
            $processor_data = fn_netopia_get_payment_method_data($activePaymentId);
        }
    }

    if (empty($processor_data['processor_params'])) {
        return ['success' => false, 'payment_url' => '', 'error' => 'Payment processor not configured.'];
    }

    $service = Bootstrap::instance()->paymentLinkService(
        paymentInfoUpdater: static function (int $orderId, array $info): void {
            fn_update_order_payment_info($orderId, Arr::stringKeys($info));
        },
    );

    $processorParams = Arr::array($processor_data, 'processor_params');

    return $service->generate($order_info, $processorParams, Arr::stringKeys($_SERVER));
}

/**
 * Send a payment link email to the customer for an order.
 */
function fn_netopia_send_payment_link_email(int $order_id, string $payment_url): bool
{
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        return false;
    }

    $sender = Bootstrap::instance()->paymentLinkEmailSender(
        mailSender: static function (array $config, string $langCode): bool {
            // The `netopia_payment_retry` template_emails row is declared in
            // addon.xml with area 'C' (customer). CS-Cart's template mailer
            // filters template lookups by the area passed here, so sending
            // with area 'A' against a C-area template makes the lookup fail
            // silently and the mailer returns false. Use 'C' for this
            // customer-targeted email; pass the customer's lang_code so Twig
            // renders in their language.
            $mailer = Tygh::$app['mailer'] ?? null;
            if (!is_object($mailer) || !method_exists($mailer, 'send')) {
                Bootstrap::instance()->logger->error(
                    'NETOPIA payment link email failed: Tygh::$app[\'mailer\'] is not available or has no send() method',
                    ['mailer_type' => is_object($mailer) ? $mailer::class : gettype($mailer)],
                );
                return false;
            }
            return (bool) $mailer->send($config, 'C', $langCode);
        },
    );

    return $sender->send($order_info, $payment_url);
}
