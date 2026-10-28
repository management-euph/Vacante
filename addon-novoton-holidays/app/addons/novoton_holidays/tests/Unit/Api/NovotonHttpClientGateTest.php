<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;

/**
 * Every request the client sends goes through the store's RequestGate:
 * Novoton asked (28 Oct 2026) for at most 5 at the same time and 5 a second.
 * Nothing listens on the address used here, so each request fails at once;
 * the gate counts it all the same.
 */
final class NovotonHttpClientGateTest extends TestCase
{
    private string $path;

    private float $now = 1_000.0;

    private float $slept = 0.0;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/novoton_client_gate_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function client(): NovotonHttpClient
    {
        $gate = new RequestGate(
            $this->path,
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->slept += $seconds;
                $this->now += $seconds;
            },
        );

        return new NovotonHttpClient([
            'api_url' => 'http://127.0.0.1:9',
            'api_key' => 'k',
            'api_user' => 'u',
            'api_password' => 'p',
            'api_max_retries' => 1,
            'circuit_breaker_threshold' => 1000,
        ], null, $gate);
    }

    /** @return array{active: array<string, float>, starts: list<float>} */
    private function state(): array
    {
        $data = json_decode((string) file_get_contents($this->path), true);
        self::assertIsArray($data);
        self::assertIsArray($data['active'] ?? null);
        self::assertIsArray($data['starts'] ?? null);

        /** @var array{active: array<string, float>, starts: list<float>} $data */
        return $data;
    }

    public function testARequestTakesATicketAndGivesItBack(): void
    {
        try {
            $this->client()->sendRequest('hotelinfo', '<x/>');
            self::fail('nothing listens there');
        } catch (ApiException) {
        }

        $state = $this->state();
        self::assertSame([], $state['active'], 'given back once answered (here: refused)');
        self::assertCount(1, $state['starts']);
    }

    public function testABatchSendsNoMoreThanFiveInAnySecond(): void
    {
        $requests = [];
        foreach (range(1, 7) as $i) {
            $requests['h' . $i] = ['function' => 'hotelinfo', 'xml' => '<x/>'];
        }

        $results = $this->client()->sendBatchRequests($requests, 5);

        self::assertSame(array_keys($requests), array_keys($results), 'every request answered, in its key');
        self::assertGreaterThanOrEqual(1.0, $this->slept, 'the sixth waited for the second to pass');
        self::assertSame([], $this->state()['active']);
    }
}
