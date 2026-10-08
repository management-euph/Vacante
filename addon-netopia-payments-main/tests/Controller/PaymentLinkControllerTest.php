<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * controllers/backend/netopia_payment_link.php: links are generated and
 * e-mailed on POST only (CS-Cart checks security_hash on POST alone).
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PaymentLinkControllerTest extends TestCase
{
    protected function setUp(): void
    {
        ControllerRunner::boot();
        CsCartStubState::$orderInfo = ['order_id' => 42, 'email' => 'client@example.com'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonPostRequests(): iterable
    {
        yield 'GET send' => ['GET', 'send'];
        yield 'GET generate' => ['GET', 'generate'];
        yield 'HEAD send' => ['HEAD', 'send'];
    }

    #[DataProvider('nonPostRequests')]
    public function testNonPostRequestDoesNotGenerateOrSend(string $method, string $mode): void
    {
        $result = ControllerRunner::run('netopia_payment_link.php', $mode, $method, ['order_id' => '42']);

        self::assertSame([CONTROLLER_STATUS_NO_PAGE], $result);
        self::assertSame([], CsCartStubState::$calls);
    }

    public function testPostSendGeneratesAndEmailsTheLink(): void
    {
        $result = ControllerRunner::run('netopia_payment_link.php', 'send', 'POST', ['order_id' => '42']);

        self::assertSame([CONTROLLER_STATUS_REDIRECT, 'admin.php?dispatch=orders.details?order_id=42'], $result);
        self::assertContains('fn_netopia_generate_payment_link', CsCartStubState::calledFunctions());
        self::assertContains('fn_netopia_send_payment_link_email', CsCartStubState::calledFunctions());
    }

    public function testPostGenerateDoesNotEmail(): void
    {
        ControllerRunner::run('netopia_payment_link.php', 'generate', 'POST', ['order_id' => '42']);

        self::assertContains('fn_netopia_generate_payment_link', CsCartStubState::calledFunctions());
        self::assertNotContains('fn_netopia_send_payment_link_email', CsCartStubState::calledFunctions());
    }

    public function testPostWithAnotherModeIsNoPage(): void
    {
        $result = ControllerRunner::run('netopia_payment_link.php', 'manage', 'POST', ['order_id' => '42']);

        self::assertSame([CONTROLLER_STATUS_NO_PAGE], $result);
        self::assertSame([], CsCartStubState::$calls);
    }
}
