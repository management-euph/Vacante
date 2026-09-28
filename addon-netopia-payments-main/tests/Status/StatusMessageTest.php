<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Status;

use Netopia\CsCart\Status\StatusMessage;
use Netopia\Payment2\Enum\PaymentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusMessage::class)]
final class StatusMessageTest extends TestCase
{
    /**
     * @param array{0: PaymentStatus|null, 1: string, 2: string} $args
     */
    #[DataProvider('customerCases')]
    public function testCustomerCopyMapsStatusToFriendlyLangKey(array $args, string $expectedKey): void
    {
        [$status, $netopiaMessage] = $args;
        $message = new StatusMessage(
            translator: static fn (string $key): string => $key,
        );

        self::assertSame($expectedKey, $message->forCustomer($status, $netopiaMessage));
    }

    /**
     * @return iterable<string, array{array{0: PaymentStatus|null, 1: string}, string}>
     */
    public static function customerCases(): iterable
    {
        yield 'paid' => [[PaymentStatus::Paid, ''], 'netopia_customer_msg_approved'];
        yield 'confirmed' => [[PaymentStatus::Confirmed, ''], 'netopia_customer_msg_approved'];
        yield 'canceled' => [[PaymentStatus::Canceled, ''], 'netopia_customer_msg_canceled'];
        yield 'reversed (cancel group)' => [[PaymentStatus::Reversed, ''], 'netopia_customer_msg_canceled'];
        yield 'credit (refund group)' => [[PaymentStatus::Credit, ''], 'netopia_customer_msg_refunded'];
        yield 'declined - generic' => [[PaymentStatus::Declined, ''], 'netopia_customer_msg_declined'];
        yield 'declined - other message' => [[PaymentStatus::Declined, 'Card expired'], 'netopia_customer_msg_declined'];
        yield 'declined - insufficient funds (en)' => [
            [PaymentStatus::Declined, 'Insufficient funds'],
            'netopia_customer_msg_declined_insufficient_funds',
        ];
        yield 'declined - insufficient funds case-insensitive' => [
            [PaymentStatus::Declined, 'INSUFFICIENT FUNDS'],
            'netopia_customer_msg_declined_insufficient_funds',
        ];
        yield 'declined - fonduri insuficiente (ro)' => [
            [PaymentStatus::Declined, 'Fonduri insuficiente'],
            'netopia_customer_msg_declined_insufficient_funds',
        ];
        yield 'error' => [[PaymentStatus::Error, ''], 'netopia_customer_msg_declined'];
        yield 'fraud is in fail group' => [[PaymentStatus::Fraud, ''], 'netopia_customer_msg_declined'];
        yield 'pending' => [[PaymentStatus::Pending, ''], 'netopia_customer_msg_pending'];
        yield 'opened' => [[PaymentStatus::Opened, ''], 'netopia_customer_msg_pending'];
        yield 'unknown enum (null)' => [[null, ''], 'netopia_customer_msg_error'];
    }

    public function testInsufficientFundsOnlyTriggersForDeclinedStatus(): void
    {
        // Guards against a NETOPIA payload that pairs an inconsistent message
        // with a non-declined status code from leaking the declined copy.
        $message = new StatusMessage(
            translator: static fn (string $key): string => $key,
        );

        self::assertSame(
            'netopia_customer_msg_approved',
            $message->forCustomer(PaymentStatus::Paid, 'Insufficient funds'),
        );
    }

    public function testAdminCopySuffixesStatusCodeForReconciliation(): void
    {
        $message = new StatusMessage(
            translator: static fn (string $key): string => $key,
        );

        self::assertSame(
            'netopia_customer_msg_declined_insufficient_funds (status: 12)',
            $message->forAdmin(PaymentStatus::Declined, 12, 'Insufficient funds'),
        );
        self::assertSame(
            'netopia_customer_msg_approved (status: 3)',
            $message->forAdmin(PaymentStatus::Paid, 3, ''),
        );
    }

    public function testAdminCopyForUnknownEnumStillCarriesRawCode(): void
    {
        $message = new StatusMessage(
            translator: static fn (string $key): string => $key,
        );

        self::assertSame(
            'netopia_customer_msg_error (status: 9999)',
            $message->forAdmin(null, 9999, ''),
        );
    }
}
