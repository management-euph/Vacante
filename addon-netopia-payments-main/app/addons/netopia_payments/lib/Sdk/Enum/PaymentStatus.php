<?php

declare(strict_types=1);

namespace Netopia\Payment2\Enum;

/**
 * NETOPIA payment status codes.
 *
 * These map directly to the `payment.status` field in NETOPIA API responses
 * and IPN callbacks.
 */
enum PaymentStatus: int
{
    case New = 1;
    case Opened = 2;
    case Paid = 3;
    case Canceled = 4;
    case Confirmed = 5;
    case Pending = 6;
    case Scheduled = 7;
    case Credit = 8;
    case ChargebackInit = 9;
    case ChargebackAccept = 10;
    case Error = 11;
    case Declined = 12;
    case Fraud = 13;
    case PendingAuth = 14;
    case ThreeDAuth = 15;
    case ChargebackRepresentment = 16;
    case Reversed = 17;
    case PendingAny = 18;
    case ProgrammedRecurrentPayment = 19;
    case CanceledProgrammedRecurrent = 20;
    case TrialPending = 21;
    case Trial = 22;
    case Expired = 23;

    /**
     * Human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Opened => 'Opened',
            self::Paid => 'Paid',
            self::Canceled => 'Canceled',
            self::Confirmed => 'Confirmed',
            self::Pending => 'Pending',
            self::Scheduled => 'Scheduled',
            self::Credit => 'Refund',
            self::ChargebackInit => 'Chargeback init',
            self::ChargebackAccept => 'Chargeback accept',
            self::Error => 'Error',
            self::Declined => 'Declined',
            self::Fraud => 'Fraud',
            self::PendingAuth => 'PendingAuth',
            self::ThreeDAuth => '3D Secure',
            self::ChargebackRepresentment => 'Chargeback representment',
            self::Reversed => 'Reversed',
            self::PendingAny => 'PendingAny',
            self::ProgrammedRecurrentPayment => 'Programmed recurrent payment',
            self::CanceledProgrammedRecurrent => 'Canceled programmed recurrent',
            self::TrialPending => 'Trial pending',
            self::Trial => 'Trial',
            self::Expired => 'Expired',
        };
    }

    /**
     * Logical group for display / status mapping.
     *
     * `refund` is kept distinct from `cancel` because the two have different
     * inventory and accounting semantics: `cancel` covers pre-capture
     * cancellation / authorization reversal (no money moved), while `refund`
     * covers post-capture credit back to the customer (money moved and
     * returned, stock must be restocked).
     *
     * @return 'success'|'pending'|'cancel'|'refund'|'fail'
     */
    public function group(): string
    {
        return match ($this) {
            self::Paid, self::Confirmed => 'success',
            self::Credit => 'refund',
            self::Canceled, self::Reversed => 'cancel',
            self::Error, self::Declined, self::Fraud, self::Expired => 'fail',
            // In flight, plus the scheduled / chargeback / recurring / trial
            // codes, which settle nothing for a CS-Cart order on their own.
            self::New, self::Opened, self::Pending, self::PendingAuth,
            self::ThreeDAuth, self::PendingAny, self::Scheduled,
            self::ChargebackInit, self::ChargebackAccept, self::ChargebackRepresentment,
            self::ProgrammedRecurrentPayment, self::CanceledProgrammedRecurrent,
            self::TrialPending, self::Trial => 'pending',
        };
    }

    /**
     * Whether this status indicates a successful payment.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Paid || $this === self::Confirmed;
    }

    /**
     * Whether this status indicates a failed payment.
     */
    public function isFailed(): bool
    {
        return $this->group() === 'fail';
    }
}
