<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tygh\Tygh;

/**
 * controllers/backend/orders.post.php: the refund panel shows paid,
 * refunded and remaining in the currency the refund controller caps and
 * sends in (the start-request charge), not the IPN's post-FX amount.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrdersPostControllerTest extends TestCase
{
    private FakeView $view;

    protected function setUp(): void
    {
        ControllerRunner::boot();
        $this->view = new FakeView();
        Tygh::$app = ['view' => $this->view];
    }

    /**
     * @param array<string, mixed> $paymentInfo
     */
    private function renderPanel(array $paymentInfo): void
    {
        $this->view->assign('order_info', [
            'order_id' => 42,
            'payment_id' => 7,
            'status' => 'P',
            'payment_info' => ['transaction_id' => 'ntp-payment-1'] + $paymentInfo,
        ]);
        ControllerRunner::run('orders.post.php', 'details', 'GET');
    }

    public function testFxOrderPanelIsInTheStartCurrency(): void
    {
        $this->renderPanel([
            'netopia_start_amount' => '68,70 EUR',
            'netopia_amount' => '361,78 RON',
        ]);

        $vars = $this->view->vars;
        self::assertTrue($vars['netopia_refund_available']);
        self::assertTrue($vars['netopia_refund_can_submit']);
        self::assertSame('EUR', $vars['netopia_refund_currency']);
        self::assertSame(68.70, $vars['netopia_refund_paid']);
        self::assertSame('68,70 EUR', $vars['netopia_refund_paid_display']);
        // data-np-remaining and the "Refund all" value: the controller's cap.
        self::assertSame(68.70, $vars['netopia_refund_remaining']);
        self::assertSame('68,70 EUR', $vars['netopia_refund_remaining_display']);
        self::assertSame('0,00 EUR', $vars['netopia_refund_already_refunded_display']);
        self::assertSame(0.0, $vars['netopia_refund_refunded_pct']);
    }

    public function testFxOrderAfterAPartialRefundCountsRefundsInTheSameCurrency(): void
    {
        $this->renderPanel([
            'netopia_start_amount' => '68,70 EUR',
            'netopia_amount' => '361,78 RON',
            'netopia_refunded_amount' => '20,00 EUR',
        ]);

        $vars = $this->view->vars;
        self::assertSame('20,00 EUR', $vars['netopia_refund_already_refunded_display']);
        self::assertSame('48,70 EUR', $vars['netopia_refund_remaining_display']);
        self::assertEqualsWithDelta(48.70, $vars['netopia_refund_remaining'], 0.0001);
        self::assertSame(29.1, $vars['netopia_refund_refunded_pct']);
    }

    public function testLegacyOrderWithoutStartAmountUsesTheIpnAmount(): void
    {
        $this->renderPanel(['netopia_amount' => '361,78 RON']);

        $vars = $this->view->vars;
        self::assertSame('RON', $vars['netopia_refund_currency']);
        self::assertSame('361,78 RON', $vars['netopia_refund_remaining_display']);
    }

    public function testNoRecordedAmountHidesTheRefundForm(): void
    {
        $this->renderPanel([]);

        self::assertArrayNotHasKey('netopia_refund_available', $this->view->vars);
        self::assertTrue($this->view->vars['netopia_payment_link_available']);
    }
}
