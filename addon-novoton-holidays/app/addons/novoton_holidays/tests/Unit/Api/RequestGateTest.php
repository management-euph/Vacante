<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Registry;

/**
 * Novoton asked us (28 Oct 2026) to stay within 5 requests at the same time
 * and 5 a second, for the whole store: every job and customers' searches.
 */
final class RequestGateTest extends TestCase
{
    private string $path;

    private float $now = 1_000.0;

    /** @var list<float> */
    private array $slept = [];

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
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
        );
    }

    public function testNoMoreThanFiveAtOnceAcrossProcesses(): void
    {
        $jobA = $this->gate();
        $jobB = $this->gate(); // another process: the same file

        $a = $jobA->acquire(5);
        self::assertCount(5, $a);

        $this->now += 2.0; // the per-second count is no longer the limit
        $releasedLater = false;
        $jobB2 = new RequestGate(
            $this->path,
            fn (): float => $this->now,
            function (float $seconds) use ($jobA, $a, &$releasedLater): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
                if (!$releasedLater) {
                    $jobA->release(array_slice($a, 0, 2)); // job A's first answers arrive
                    $releasedLater = true;
                }
            },
        );
        $b = $jobB2->acquire(5);

        self::assertCount(2, $b, 'it waits, then takes what is free: two, not five');
        self::assertNotEmpty($this->slept);
        unset($jobB);
    }

    public function testNoMoreThanFiveStartsInAnySecond(): void
    {
        $gate = $this->gate();

        $first = $gate->acquire(5);
        $gate->release($first); // all answered at once
        $second = $gate->acquire(1);

        self::assertCount(1, $second);
        self::assertGreaterThanOrEqual(1.0, $this->now - 1_000.0, 'the sixth start waits for the second to pass');
    }

    public function testATicketNeverGivenBackLapses(): void
    {
        $gate = $this->gate();
        $gate->acquire(5); // a process died holding them

        $this->now += RequestGate::TICKET_TTL + 1;

        self::assertCount(5, $this->gate()->acquire(5));
    }

    /** Both numbers are settings (Advanced → API resilience): a store set to fewer sends fewer. */
    public function testTheLimitsAreTheGatesOwn(): void
    {
        $gate = new RequestGate(
            $this->path,
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
            2,
            3,
        );

        $first = $gate->acquire(5);
        self::assertCount(2, $first, 'two at the same time');
        $gate->release($first);
        $second = $gate->acquire(5);
        self::assertCount(1, $second, 'the third start in this second');
        $gate->release($second);
        $gate->acquire();

        self::assertGreaterThanOrEqual(1.0, $this->now - 1_000.0, 'the fourth waits for the second to pass');
    }

    public function testTheSettingsReachTheSharedGate(): void
    {
        Registry::set('addons.novoton_holidays', ['api_max_concurrent' => '3', 'api_max_per_second' => '0']);
        ConfigProvider::resetSettingsCache();
        try {
            self::assertSame(3, ConfigProvider::getApiMaxConcurrent());
            self::assertSame(5, ConfigProvider::getApiMaxPerSecond(), 'an empty or zero value keeps Novoton\'s number');
        } finally {
            Registry::set('addons.novoton_holidays', []);
            ConfigProvider::resetSettingsCache();
        }
    }

    public function testWithoutItsFileTheGateStepsAside(): void
    {
        $gate = new RequestGate('/nonexistent-dir/novoton_gate.json');

        self::assertSame([], $gate->acquire(3), 'requests go out ungated rather than not at all');
        $gate->release(['x']);
    }
}
