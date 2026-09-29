<?php

declare(strict_types=1);

namespace Netopia\CsCart\Status;

use Closure;
use Netopia\Payment2\Enum\PaymentStatus;

/**
 * Builds human-readable, localised messages for a NETOPIA payment status.
 *
 * Two flavours are produced:
 *   - customer text: friendly storefront banner copy ("Transaction declined —
 *     Insufficient funds!"). Never leaks NETOPIA jargon, ntpIDs, or status codes.
 *   - admin text: same friendly text suffixed with the raw status number so
 *     reconciliation against the NETOPIA dashboard stays trivial.
 *
 * The mapping inspects the optional NETOPIA `message` field to pick a
 * sub-variant (currently used to surface "Insufficient funds" within the
 * generic Declined status). Unknown messages fall back to the generic copy
 * for the status group.
 */
final class StatusMessage
{
    /**
     * @param Closure(string $key): string $translator
     */
    public function __construct(
        private readonly Closure $translator,
    ) {
    }

    /**
     * Customer-facing storefront text.
     */
    public function forCustomer(?PaymentStatus $status, string $netopiaMessage = ''): string
    {
        return $this->translate(self::customerLangKey($status, $netopiaMessage));
    }

    /**
     * Admin-facing text. Same friendly copy as forCustomer with the raw
     * status number appended, so reconciliation against the NETOPIA
     * dashboard stays a one-grep job. The number is preserved even when
     * the enum is unknown — gives merchants something to investigate.
     */
    public function forAdmin(?PaymentStatus $status, int $rawStatus, string $netopiaMessage = ''): string
    {
        return $this->forCustomer($status, $netopiaMessage) . ' (status: ' . $rawStatus . ')';
    }

    private static function customerLangKey(?PaymentStatus $status, string $netopiaMessage): string
    {
        if ($status === null) {
            return 'netopia_customer_msg_error';
        }

        if ($status === PaymentStatus::Declined && self::looksLikeInsufficientFunds($netopiaMessage)) {
            return 'netopia_customer_msg_declined_insufficient_funds';
        }

        return match ($status->group()) {
            'success' => 'netopia_customer_msg_approved',
            'refund' => 'netopia_customer_msg_refunded',
            'cancel' => 'netopia_customer_msg_canceled',
            'fail' => 'netopia_customer_msg_declined',
            default => 'netopia_customer_msg_pending',
        };
    }

    /**
     * NETOPIA returns the literal string "Insufficient funds" (or a localised
     * variant) for declined-due-to-balance transactions. Match leniently — case
     * insensitive, trimmed, both English and Romanian — so we surface the
     * specific reason whenever NETOPIA gives it to us.
     */
    private static function looksLikeInsufficientFunds(string $netopiaMessage): bool
    {
        $message = strtolower(trim($netopiaMessage));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'insufficient funds')
            || str_contains($message, 'fonduri insuficiente');
    }

    private function translate(string $key): string
    {
        return ($this->translator)($key);
    }
}
