<?php

declare(strict_types=1);

/**
 * Novoton Security Service
 *
 * Handles input validation, CSRF protection, rate limiting,
 * and secure data handling for the addon.
 *
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\CacheRepository;
use Tygh\Addons\NovotonHolidays\Repository\CacheRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;
use Tygh\Addons\TravelCore\TravelConstants;

class SecurityService implements SecurityServiceInterface
{
    /** @var int Rate limit window in seconds */
    private const int RATE_LIMIT_WINDOW = 60;

    private CacheRepositoryInterface $cacheRepo;

    public function __construct(?CacheRepositoryInterface $cacheRepo = null)
    {
        $this->cacheRepo = $cacheRepo ?? new CacheRepository();
    }

    /**
     * Validate booking data
     *
     * @param array<string, mixed> $data Booking data
     * @return array{valid: bool, errors: array<int, string>} [valid => bool, errors => array]
     */
    public function validateBookingData(array $data): array
    {
        $errors = [];

        // Required fields
        $required = ['hotel_id', 'check_in', 'check_out', 'adults'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate hotel_id format
        $hotelIdStr = PriceInfoFormatter::toScalar($data['hotel_id'] ?? '');
        if (!empty($hotelIdStr) && !$this->isValidHotelId($hotelIdStr)) {
            $errors[] = 'Invalid hotel ID format';
        }

        // Validate dates
        $checkInStr = PriceInfoFormatter::toScalar($data['check_in'] ?? '');
        if (!empty($checkInStr)) {
            if (!$this->isValidDate($checkInStr)) {
                $errors[] = 'Invalid check-in date format';
            } elseif ((int) strtotime($checkInStr) < (int) strtotime('today')) {
                $errors[] = 'Check-in date cannot be in the past';
            }
        }

        $checkOutStr = PriceInfoFormatter::toScalar($data['check_out'] ?? '');
        if (!empty($checkOutStr)) {
            if (!$this->isValidDate($checkOutStr)) {
                $errors[] = 'Invalid check-out date format';
            } elseif (!empty($checkInStr) && (int) strtotime($checkOutStr) <= (int) strtotime($checkInStr)) {
                $errors[] = 'Check-out must be after check-in';
            }
        }

        // Validate adults/children
        if (isset($data['adults'])) {
            $adults = PriceInfoFormatter::toInt($data['adults']);
            if ($adults < 1 || $adults > TravelConstants::MAX_ADULTS) {
                $errors[] = 'Adults must be between 1 and ' . TravelConstants::MAX_ADULTS;
            }
        }

        if (isset($data['children'])) {
            $children = PriceInfoFormatter::toInt($data['children']);
            if ($children < 0 || $children > TravelConstants::MAX_CHILDREN) {
                $errors[] = 'Children must be between 0 and ' . TravelConstants::MAX_CHILDREN;
            }
        }

        // Validate children ages
        if (!empty($data['children_ages'])) {
            $ages = is_array($data['children_ages'])
                ? $data['children_ages']
                : explode(',', PriceInfoFormatter::toScalar($data['children_ages']));

            foreach ($ages as $age) {
                $age = PriceInfoFormatter::toFloat($age);
                if ($age < TravelConstants::MIN_CHILD_AGE || $age > TravelConstants::MAX_CHILD_AGE) {
                    $errors[] = 'Child age must be between ' . TravelConstants::MIN_CHILD_AGE . ' and ' . TravelConstants::MAX_CHILD_AGE;
                    break;
                }
            }
        }

        // Validate price (prevent manipulation)
        if (isset($data['total_price'])) {
            $price = PriceInfoFormatter::toFloat($data['total_price']);
            if ($price < 0 || $price > 100000) {
                $errors[] = 'Invalid price value';
            }
        }

        // Validate guest names (basic XSS prevention)
        $nameFields = ['holder_name', 'guest_name'];
        foreach ($nameFields as $field) {
            if (!empty($data[$field]) && !$this->isValidName(PriceInfoFormatter::toScalar($data[$field]))) {
                $errors[] = "Invalid characters in {$field}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate search parameters
     *
     * @param array<string, mixed> $params Search parameters
     * @return array<string, mixed> [valid => bool, errors => array, sanitized => array]
     */
    public function validateSearchParams(array $params): array
    {
        $sanitized = [];

        // Sanitize and validate check_in
        $pCheckIn = PriceInfoFormatter::toScalar($params['check_in'] ?? '');
        if (!empty($pCheckIn)) {
            if ($this->isValidDate($pCheckIn)) {
                $sanitized['check_in'] = $pCheckIn;
            }
        }

        // Sanitize and validate check_out
        $pCheckOut = PriceInfoFormatter::toScalar($params['check_out'] ?? '');
        if (!empty($pCheckOut)) {
            if ($this->isValidDate($pCheckOut)) {
                $sanitized['check_out'] = $pCheckOut;
            }
        }

        // Sanitize nights
        $sanitized['nights'] = max(1, min(TravelConstants::MAX_NIGHTS, PriceInfoFormatter::toInt($params['nights'] ?? TravelConstants::DEFAULT_NIGHTS)));

        // Sanitize adults
        $sanitized['adults'] = max(1, min(TravelConstants::MAX_ADULTS, PriceInfoFormatter::toInt($params['adults'] ?? TravelConstants::DEFAULT_ADULTS)));

        // Sanitize children
        $sanitized['children'] = max(0, min(TravelConstants::MAX_CHILDREN, PriceInfoFormatter::toInt($params['children'] ?? TravelConstants::DEFAULT_CHILDREN)));

        // Sanitize rooms
        $sanitized['rooms'] = max(1, min(TravelConstants::MAX_ROOMS, PriceInfoFormatter::toInt($params['rooms'] ?? TravelConstants::DEFAULT_ROOMS)));

        // Sanitize destination (alphanumeric, spaces, common punctuation)
        if (!empty($params['destination'])) {
            $sanitized['destination'] = $this->sanitizeString(PriceInfoFormatter::toScalar($params['destination']), 100);
        }

        // Sanitize hotel_id
        if (!empty($params['hotel_id'])) {
            $sanitized['hotel_id'] = $this->sanitizeHotelId(PriceInfoFormatter::toScalar($params['hotel_id']));
        }

        // Pass through product_id (integer)
        if (!empty($params['product_id'])) {
            $sanitized['product_id'] = PriceInfoFormatter::toInt($params['product_id']);
        }

        // Pass through children_ages (comma-separated numbers, may include decimals like 1.5)
        $childAgesRaw = $params['children_ages'] ?? '';
        if (!empty($childAgesRaw) && is_string($childAgesRaw)) {
            // Only allow digits, dots (decimals), and commas
            $sanitized['children_ages'] = preg_replace('/[^0-9.,]/', '', $childAgesRaw);
        }

        // Pass through rooms_data (JSON string - will be decoded by controller)
        if (!empty($params['rooms_data'])) {
            $sanitized['rooms_data'] = is_string($params['rooms_data']) ? $params['rooms_data'] : json_encode($params['rooms_data']);
        }
        if (!empty($params['room_data'])) {
            $sanitized['room_data'] = is_string($params['room_data']) ? $params['room_data'] : json_encode($params['room_data']);
        }

        // Pass through meal_plan
        if (!empty($params['meal_plan']) && is_string($params['meal_plan'])) {
            $sanitized['meal_plan'] = preg_replace('/[^a-zA-Z0-9_ &+]/', '', substr($params['meal_plan'], 0, 50));
        }

        // Pass through flex_days (integer)
        if (!empty($params['flex_days'])) {
            $sanitized['flex_days'] = max(0, min(30, PriceInfoFormatter::toInt($params['flex_days'])));
        }

        // Pass through search query
        if (!empty($params['q'])) {
            $sanitized['q'] = $this->sanitizeString(PriceInfoFormatter::toScalar($params['q']), 200);
        }

        // Note: debug mode is gated by server-side ConfigProvider::isDebugLogging(),
        // not by URL parameters. The 'debug' and 'reset_circuit' URL params are no longer accepted.

        // Legacy child_age_N parameters
        for ($i = 1; $i <= 6; $i++) {
            $key = 'child_age_' . $i;
            if (isset($params[$key])) {
                $sanitized[$key] = max(0, min(17, PriceInfoFormatter::toInt($params[$key])));
            }
        }

        return $sanitized;
    }

    /**
     * Validate and sanitize guest data
     *
     * @param array<string, mixed> $guests Guest data
     * @return array<string, mixed> Sanitized guest data
     */
    public function sanitizeGuestData(array $guests): array
    {
        $sanitized = [];

        foreach ($guests as $key => $guest) {
            if (!is_array($guest)) {
                continue;
            }

            $guestType = PriceInfoFormatter::toScalar($guest['type'] ?? '');
            $sanitized[$key] = [
                'first_name' => $this->sanitizeName(PriceInfoFormatter::toScalar($guest['first_name'] ?? '')),
                'last_name' => $this->sanitizeName(PriceInfoFormatter::toScalar($guest['last_name'] ?? '')),
                'name' => $this->sanitizeName(PriceInfoFormatter::toScalar($guest['name'] ?? '')),
                'api_name' => $this->sanitizeName(PriceInfoFormatter::toScalar($guest['api_name'] ?? '')),
                'type' => in_array($guestType, ['adult', 'child']) ? $guestType : 'adult',
                'age' => isset($guest['age']) ? max(0, min(99, PriceInfoFormatter::toInt($guest['age']))) : null,
                'room' => max(1, min(5, PriceInfoFormatter::toInt($guest['room'] ?? 1))),
                'is_holder' => !empty($guest['is_holder']),
            ];

            // Validate and pass through DOB (DD/MM/YYYY format from form)
            if (!empty($guest['dob'])) {
                $dob = trim(PriceInfoFormatter::toScalar($guest['dob']));
                // Accept DD/MM/YYYY or YYYY-MM-DD format
                if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob) === 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1) {
                    $sanitized[$key]['dob'] = $dob;
                }
            }

            // Validate birthday format (YYYY-MM-DD)
            if (!empty($guest['birthday'])) {
                $birthday = PriceInfoFormatter::toScalar($guest['birthday']);
                if ($this->isValidDate($birthday)) {
                    $sanitized[$key]['birthday'] = $birthday;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Check CSRF token
     *
     * @param string $token Token to verify
     * @return bool Is valid
     */
    public function verifyCsrfToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        // CS-Cart's built-in CSRF check
        if (defined('CSRF_TOKEN_NAME')) {
            return TypeCoerce::toBool(fn_csrf_validate_request([TypeCoerce::toString(CSRF_TOKEN_NAME) => $token]));
        }

        // Fallback: check session token
        $session_token = TypeCoerce::toString($_SESSION['nvt_csrf_token'] ?? '');
        return hash_equals($session_token, $token);
    }

    /**
     * Generate CSRF token
     *
     * @return string Token
     */
    public function generateCsrfToken(): string
    {
        if (!isset($_SESSION['nvt_csrf_token'])) {
            $_SESSION['nvt_csrf_token'] = bin2hex(random_bytes(32));
        }
        return TypeCoerce::toString($_SESSION['nvt_csrf_token']);
    }

    /**
     * Check rate limit
     *
     * @param string $key Rate limit key (e.g., IP, user_id)
     * @param int $maxRequests Max requests per window
     * @param int $window Window in seconds
     * @return array{allowed: bool, remaining: int, reset: int} [allowed => bool, remaining => int, reset => int]
     */
    public function checkRateLimit(string $key, ?int $maxRequests = null, ?int $window = null): array
    {
        $maxRequests ??= ConfigProvider::getRateLimitRequestsPerMin();
        $window ??= self::RATE_LIMIT_WINDOW;

        $cacheKey = 'nvt_rate_' . md5($key);
        $now = time();

        // Get current count
        $data = $this->getRateLimitData($cacheKey);

        // Reset if window expired
        if ($data['reset'] <= $now) {
            $data = [
                'count' => 0,
                'reset' => $now + $window,
            ];
        }

        // Check if allowed
        $allowed = $data['count'] < $maxRequests;

        // Increment counter
        if ($allowed) {
            $data['count']++;
            $this->setRateLimitData($cacheKey, $data);
        }

        return [
            'allowed' => $allowed,
            'remaining' => max(0, $maxRequests - $data['count']),
            'reset' => $data['reset'],
        ];
    }

    /**
     * The visitor's address (REMOTE_ADDR, never a forwarded-for header a
     * client can write): what a client cannot change by dropping its cookie.
     * '' outside a web request.
     */
    public static function visitorIp(): string
    {
        $ip = TypeCoerce::toString($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * Whether this visitor's address may make another $action request: at
     * most $max within $window seconds. The session-keyed limits gave a
     * client without a cookie a fresh allowance on every request; the
     * address does not reset. No address (CLI): not limited.
     */
    public function checkVisitorLimit(string $action, int $max, int $window): bool
    {
        $ip = self::visitorIp();
        if ($ip === '') {
            return true;
        }

        return $this->checkRateLimit($action . '_ip_' . $ip, $max, $window)['allowed'];
    }

    /**
     * The current visitor's account (user id), else its session id: the key
     * of the per-visitor booking limit.
     */
    public static function sessionIdentity(): string
    {
        $userId = TypeCoerce::toInt((new SessionAccessor())->auth()['user_id'] ?? 0);
        if ($userId > 0) {
            return (string) $userId;
        }

        return TypeCoerce::toString(session_id());
    }

    /**
     * Whether a visitor may send another booking-type request ($mode:
     * add_to_cart, request_alternatives): within the limit of its account or
     * session ($identity) AND of its address. A client that sends no cookie
     * gets a new session each time; its address stays. A refusal is logged.
     */
    public function allowsBookingRequest(string $mode, string $identity): bool
    {
        $max = ConfigProvider::getRateLimitBookingsPerHour();
        if ($this->checkBookingRateLimit($identity) && $this->checkVisitorLimit($mode, $max, 3600)) {
            return true;
        }
        $this->logSecurityEvent('rate_limit_exceeded', ['mode' => $mode, 'identifier' => $identity, 'ip' => self::visitorIp()]);

        return false;
    }

    /**
     * Check booking rate limit (stricter)
     *
     * @param string $identifier User ID or session ID
     * @return bool Is allowed
     */
    public function checkBookingRateLimit(string $identifier): bool
    {
        $result = $this->checkRateLimit(
            'booking_' . $identifier,
            ConfigProvider::getRateLimitBookingsPerHour(),
            3600,
        );

        return $result['allowed'];
    }

    /**
     * Encrypt sensitive data
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data
     */
    public function encrypt(string $data): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);

        $encrypted = openssl_encrypt(
            $data,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
        );

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     *
     * @param string $data Encrypted data
     * @return string|null Decrypted data or null on failure
     */
    public function decrypt(string $data): ?string
    {
        $key = $this->getEncryptionKey();
        $data = base64_decode($data);

        if (strlen($data) < 17) {
            return null;
        }

        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
        );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Sanitize output for HTML
     *
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    public function escapeHtml(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Log security event
     *
     * @param string $event Event type
     * @param array<string, mixed> $data Event data
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $logData = array_merge([
            'event' => $event,
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
        ], $data);

        fn_log_event('general', 'runtime', [
            'message' => 'NovotonSecurity: ' . $event,
            'data' => $logData,
        ]);
    }

    // ========== Private Helper Methods ==========

    private function isValidDate(string $date): bool
    {
        return ValidationHelpers::isValidDate($date);
    }

    private function isValidHotelId(string $hotelId): bool
    {
        return ValidationHelpers::isValidEntityId($hotelId);
    }

    private function isValidName(string $name): bool
    {
        return ValidationHelpers::isValidName($name);
    }

    private function sanitizeName(string $name): string
    {
        return ValidationHelpers::sanitizeName($name);
    }

    /**
     * Sanitize general string (HTML tags stripped at input time; HTML-encoding
     * is done at output time by escapeHtml() / Smarty).
     */
    private function sanitizeString(string $string, int $maxLength = 255): string
    {
        return ValidationHelpers::sanitizeString($string, $maxLength);
    }

    /**
     * Sanitize hotel ID
     */
    private function sanitizeHotelId(string $hotelId): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_-]/', '', substr($hotelId, 0, 50));
    }

    /**
     * Get rate limit data from cache
     * @return array{count: int, reset: int}
     */
    private function getRateLimitData(string $key): array
    {
        $row = $this->cacheRepo->findByKey($key);

        if ($row !== null && $row['expires_at'] > time()) {
            $decoded = json_decode($row['cache_data'], true);
            if (is_array($decoded)) {
                return [
                    'count' => TypeCoerce::toInt($decoded['count'] ?? 0),
                    'reset' => TypeCoerce::toInt($decoded['reset'] ?? 0),
                ];
            }
        }

        return ['count' => 0, 'reset' => time() + self::RATE_LIMIT_WINDOW];
    }

    /**
     * Set rate limit data in cache
     * @param array<string, mixed> $data
     */
    private function setRateLimitData(string $key, array $data): void
    {
        $this->cacheRepo->upsert(
            $key,
            json_encode($data, JSON_UNESCAPED_UNICODE) ?: '',
            TypeCoerce::toInt($data['reset'] ?? 0) + 60,
        );
    }

    /**
     * Get encryption key
     *
     * Priority: CS-Cart crypt_key → addon api_key → persisted random key.
     * Never derives keys from predictable inputs (hostname, path, etc.).
     */
    private function getEncryptionKey(): string
    {
        // Use CS-Cart's crypt key first
        $key = ConfigProvider::getCryptKey();

        if (empty($key)) {
            $key = ConfigProvider::getApiKey();
        }

        if (empty($key)) {
            $key = $this->getOrCreatePersistedKey();
        }

        return hash('sha256', $key, true);
    }

    /**
     * Get or generate a persisted random encryption key.
     *
     * Stores the key in a protected file under var/novoton/.encryption_key
     * so it survives across requests but is not predictable.
     */
    private function getOrCreatePersistedKey(): string
    {
        $keyDir = TypeCoerce::toString(DIR_ROOT) . '/var/novoton';
        $keyFile = $keyDir . '/.encryption_key';

        if (file_exists($keyFile)) {
            $key = trim((string) file_get_contents($keyFile));
            // bin2hex(random_bytes(32)) produces 64 hex chars (256-bit key)
            if ($key !== '' && strlen($key) >= 64) {
                return $key;
            }
        }

        // Generate a cryptographically secure random key
        $key = bin2hex(random_bytes(32));

        if (!is_dir($keyDir) && !mkdir($keyDir, 0o700, true) && !is_dir($keyDir)) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton SecurityService: failed to create key directory: ' . $keyDir,
            ]);
        }

        if (file_put_contents($keyFile, $key, LOCK_EX) === false) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton SecurityService: failed to persist encryption key to ' . $keyFile,
            ]);
        } else {
            chmod($keyFile, 0o600);
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton SecurityService: generated and persisted new encryption key',
            ]);
        }

        return $key;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = TypeCoerce::toString($_SERVER[$header]);
                // Handle comma-separated IPs
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
