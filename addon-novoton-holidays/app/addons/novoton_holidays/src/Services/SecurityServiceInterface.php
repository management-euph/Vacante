<?php

declare(strict_types=1);

/**
 * Novoton Security Service Interface
 *
 * Extends the travel_core SecurityServiceInterface with Novoton-specific
 * security features: CSRF protection, encryption, rate limiting,
 * HTML escaping, and event logging.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\SecurityServiceInterface as BaseSecurityServiceInterface;

interface SecurityServiceInterface extends BaseSecurityServiceInterface
{
    /**
     * Verify CSRF token.
     *
     * @param string $token Token to verify
     * @return bool Is valid
     */
    public function verifyCsrfToken(string $token): bool;

    /**
     * Generate CSRF token.
     *
     * @return string Token
     */
    public function generateCsrfToken(): string;

    /**
     * Check rate limit.
     *
     * @param string $key Rate limit key (e.g., IP, user_id)
     * @param int|null $maxRequests Max requests per window
     * @param int|null $window Window in seconds
     * @return array{allowed: bool, remaining: int, reset: int}
     */
    public function checkRateLimit(string $key, ?int $maxRequests = null, ?int $window = null): array;

    /**
     * Whether this visitor's address may make another $action request: at
     * most $max within $window seconds.
     */
    public function checkVisitorLimit(string $action, int $max, int $window): bool;

    /** Whether a visitor ($identity: account or session) may send another booking-type request ($mode). */
    public function allowsBookingRequest(string $mode, string $identity): bool;

    /**
     * Encrypt sensitive data.
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data
     */
    public function encrypt(string $data): string;

    /**
     * Decrypt sensitive data.
     *
     * @param string $data Encrypted data
     * @return string|null Decrypted data or null on failure
     */
    public function decrypt(string $data): ?string;

    /**
     * Sanitize output for HTML.
     *
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    public function escapeHtml(string $string): string;

    /**
     * Log security event.
     *
     * @param string $event Event type
     * @param array<string, mixed> $data Event data
     */
    public function logSecurityEvent(string $event, array $data = []): void;
}
