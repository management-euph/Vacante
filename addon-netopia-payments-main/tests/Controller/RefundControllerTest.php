<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * controllers/backend/netopia_refund.php: refunds run on POST only (CS-Cart
 * checks security_hash on POST alone), and the cap is in the same currency
 * the order panel shows (RefundBasis).
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RefundControllerTest extends TestCase
{
    protected function setUp(): void
    {
        ControllerRunner::boot();
        CsCartStubState::$orderInfo = [
            'order_id' => 42,
            'payment_id' => 7,
            'status' => 'P',
            'payment_info' => [
                'transaction_id' => 'ntp-payment-1',
                'netopia_start_amount' => '68,70 EUR',
                'netopia_amount' => '361,78 RON',
            ],
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPostMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'PUT' => ['PUT'];
    }

    #[DataProvider('nonPostMethods')]
    public function testNonPostRequestDoesNotRunTheRefund(string $method): void
    {
        $result = ControllerRunner::run('netopia_refund.php', 'process', $method, [
            'order_id' => '42',
            'amount' => '68.70',
        ]);

        self::assertSame([CONTROLLER_STATUS_NO_PAGE], $result);
        self::assertSame([], CsCartStubState::$calls, 'a non-POST request must not load the order, claim or refund');
    }

    public function testPostWithAnotherModeIsNoPage(): void
    {
        $result = ControllerRunner::run('netopia_refund.php', 'manage', 'POST', ['order_id' => '42']);

        self::assertSame([CONTROLLER_STATUS_NO_PAGE], $result);
        self::assertSame([], CsCartStubState::$calls);
    }

    public function testPostReadsOrderIdFromTheRequest(): void
    {
        // The guard passes a POST through; order_id may come from the query
        // string ({btn method="POST"}), so $_REQUEST is still read.
        CsCartStubState::$orderInfo = [];

        $result = ControllerRunner::run('netopia_refund.php', 'process', 'POST', ['order_id' => '42']);

        self::assertSame([CONTROLLER_STATUS_REDIRECT, 'admin.php?dispatch=orders.manage'], $result);
        self::assertSame(['fn_get_order_info', 'fn_set_notification'], CsCartStubState::calledFunctions());
        self::assertSame([42], CsCartStubState::$calls[0][1]);
    }

    public function testFxOrderCapIsTheStartChargeInEur(): void
    {
        // 361,78 is the IPN's RON figure; the cap is 68,70 EUR, the same
        // "remaining" the order panel shows (OrdersPostControllerTest).
        $result = ControllerRunner::run('netopia_refund.php', 'process', 'POST', [
            'order_id' => '42',
            'amount' => '361,78',
        ]);

        self::assertSame([CONTROLLER_STATUS_REDIRECT, 'admin.php?dispatch=orders.details?order_id=42'], $result);
        $notifications = CsCartStubState::notifications();
        self::assertCount(1, $notifications);
        self::assertSame('E', $notifications[0]['type']);
        self::assertSame(
            'netopia_refund_amount_exceeds_remaining {"[remaining]":"68,70 EUR"}',
            $notifications[0]['message'],
        );
    }

    public function testInCapPostReachesTheRefundClaim(): void
    {
        // 50 EUR is within the 68,70 EUR start charge: the guard and the cap
        // let it through to the refund-attempt claim (db_query stops there).
        try {
            ControllerRunner::run('netopia_refund.php', 'process', 'POST', [
                'order_id' => '42',
                'amount' => '50',
            ]);
            self::fail('expected the run to reach the refund-attempt claim');
        } catch (\RuntimeException $e) {
            self::assertSame('stub: stopped at db_query', $e->getMessage());
        }

        self::assertSame([], CsCartStubState::notifications());
        $claim = CsCartStubState::$calls[array_key_last(CsCartStubState::$calls)];
        self::assertSame('db_query', $claim[0]);
        self::assertStringContainsString('netopia_refund_attempts', (string) $claim[1][0]);
        self::assertSame(42, $claim[1][2]);
        self::assertSame(50.0, $claim[1][3]);
    }
}
