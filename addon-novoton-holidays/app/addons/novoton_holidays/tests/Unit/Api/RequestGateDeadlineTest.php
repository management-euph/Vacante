<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;

/**
 * The store shares one Novoton allowance (5 at once, 5 a second). A
 * customer's page waited for a free slot with no end, holding a PHP worker
 * while others kept the gate busy. It now gives up after a few seconds.
 */
final class RequestGateDeadlineTest extends TestCase
{
    private string $path;
    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/novoton_gate_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function gate(): RequestGate
    {
        return new RequestGate(
            $this->path,
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->now += $seconds;
            },
            1,
            5,
        );
    }

    public function testAPageGivesUpWhenNoSlotFreesInTime(): void
    {
        $busy = $this->gate()->acquire();
        self::assertCount(1, $busy, 'the one slot is taken');

        $start = $this->now;
        self::assertNull($this->gate()->acquireWithin(10.0));
        self::assertGreaterThanOrEqual(10.0, $this->now - $start);
        self::assertLessThan(12.0, $this->now - $start, 'it gave up instead of waiting for ever');
    }

    public function testAFreeSlotIsTakenAtOnce(): void
    {
        self::assertCount(1, (array) $this->gate()->acquireWithin(10.0));
    }

    public function testOnlyCustomersPagesAskingForPricesWaitBriefly(): void
    {
        self::assertFalse(defined('NOVOTON_CUSTOMER_PAGE'));
        self::assertFalse(NovotonHttpClient::waitsBriefly(Constants::API_FUNCTION_ROOM_PRICE), 'jobs and the checkout wait their turn');
    }

    #[RunInSeparateProcess]
    public function testOnACustomersPageAReservationStillWaitsItsTurn(): void
    {
        define('NOVOTON_CUSTOMER_PAGE', true);

        self::assertTrue(NovotonHttpClient::waitsBriefly(Constants::API_FUNCTION_ROOM_PRICE));
        self::assertFalse(NovotonHttpClient::waitsBriefly(Constants::API_FUNCTION_RESERVATION));
    }
}
