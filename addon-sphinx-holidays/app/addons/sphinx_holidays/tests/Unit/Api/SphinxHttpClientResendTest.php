<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

/**
 * A booking is sent once. The client resent every POST after an HTTP 5xx or
 * a network error (three retries), so a booking Sphinx had made but whose
 * answer was lost could be made again. postOnce() resends only what surely
 * never left, and says when a request without an answer may have been
 * processed.
 */
#[CoversClass(SphinxHttpClient::class)]
final class SphinxHttpClientResendTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: ?string}> */
    private array $sent = [];

    /**
     * @param list<array{body: string|false, code: int, errno?: int, error?: string}> $answers one per exchange, in order
     */
    private function client(array $answers, ?ResiliencePolicy $resilience = null): SphinxHttpClient
    {
        $this->sent = [];

        return new SphinxHttpClient(
            baseUrl: 'https://api.example.com',
            apiKey: 'test-key',
            maxRetries: 3,
            retryDelayMs: 0,
            retryMultiplier: 1.0,
            resilience: $resilience,
            exec: function (string $method, string $url, array $headers, ?string $body) use (&$answers): array {
                $this->sent[] = ['method' => $method, 'url' => $url, 'body' => $body];
                self::assertContains('Authorization: Bearer test-key', $headers);
                $answer = array_shift($answers);
                self::assertNotNull($answer, 'more requests than answers');

                return $answer;
            },
        );
    }

    public function testABookingThatGotAServerErrorIsNotResent(): void
    {
        $client = $this->client([['body' => 'Bad gateway', 'code' => 502]]);

        self::assertNull($client->postOnce('/api/v1/hotels/book', ['offer_id' => 'O-1']));
        self::assertCount(1, $this->sent);
        self::assertTrue($client->maybeProcessed());
        self::assertStringContainsString('Server error HTTP 502', $client->getLastError());
        self::assertStringContainsString('not resent', $client->getLastError());
    }

    public function testABookingThatTimedOutIsNotResent(): void
    {
        $client = $this->client([['body' => false, 'code' => 0, 'errno' => 28, 'error' => 'Operation timed out after 30001 milliseconds']]);

        self::assertNull($client->postOnce('/api/v1/packages/book', ['offer_id' => 'O-2']));
        self::assertCount(1, $this->sent);
        self::assertTrue($client->maybeProcessed());
    }

    public function testABookingThatNeverConnectedIsResent(): void
    {
        $client = $this->client([
            ['body' => false, 'code' => 0, 'errno' => 7, 'error' => 'Failed to connect'],
            ['body' => '{"booking_confirmation_number":"V-1","status":"confirmed"}', 'code' => 200],
        ]);

        self::assertSame(['booking_confirmation_number' => 'V-1', 'status' => 'confirmed'], $client->postOnce('/api/v1/hotels/book', ['offer_id' => 'O-3']));
        self::assertCount(2, $this->sent);
        self::assertFalse($client->maybeProcessed());
    }

    public function testARefusedBookingIsNotProcessedAndKeepsTheSuppliersAnswer(): void
    {
        $client = $this->client([['body' => '{"message":"The price has changed"}', 'code' => 422]]);

        self::assertNull($client->postOnce('/api/v1/hotels/book', ['offer_id' => 'O-4']));
        self::assertCount(1, $this->sent);
        self::assertFalse($client->maybeProcessed());
        self::assertStringContainsString('HTTP 422', $client->getLastError());
        self::assertStringContainsString('The price has changed', $client->getLastError());
    }

    public function testABookingSendsItsBodyOnceToItsEndpoint(): void
    {
        $client = $this->client([['body' => '{"status":"confirmed"}', 'code' => 200]]);

        self::assertSame(['status' => 'confirmed'], $client->postOnce('/api/v1/circuits/book', ['offer_id' => 'O-5']));
        self::assertSame([['method' => 'POST', 'url' => 'https://api.example.com/api/v1/circuits/book', 'body' => '{"offer_id":"O-5"}']], $this->sent);
        self::assertFalse($client->maybeProcessed());
    }

    public function testAnOpenCircuitSendsNothingAndProcessedNothing(): void
    {
        $now = 1_000_000;
        $policy = new ResiliencePolicy(failureThreshold: 1, cooldownSeconds: 60, initialDelayMs: 0, delayMultiplier: 1.0, resetCountAtHalfOpen: true, clock: static fn (): int => $now);
        $policy->recordFailure();
        $client = $this->client([], $policy);

        self::assertNull($client->postOnce('/api/v1/hotels/book', ['offer_id' => 'O-6']));
        self::assertSame([], $this->sent);
        self::assertFalse($client->maybeProcessed());
        self::assertStringContainsString('Circuit breaker is open', $client->getLastError());
    }

    /** Searches and other repeatable calls keep their retries. */
    public function testAnOrdinaryPostIsStillRetriedAfterAServerError(): void
    {
        $client = $this->client([
            ['body' => 'Unavailable', 'code' => 503],
            ['body' => '{"cursor":"c-1"}', 'code' => 200],
        ]);

        self::assertSame(['cursor' => 'c-1'], $client->post('/api/v1/hotels/search', ['destination_id' => 1]));
        self::assertCount(2, $this->sent);
    }

    public function testTheFlagIsTheLastRequestsOnly(): void
    {
        $client = $this->client([
            ['body' => 'Bad gateway', 'code' => 502],
            ['body' => '{"ok":true}', 'code' => 200],
        ]);

        $client->postOnce('/api/v1/hotels/book', ['offer_id' => 'O-7']);
        self::assertTrue($client->maybeProcessed());
        $client->get('/api/v1/ping');
        self::assertFalse($client->maybeProcessed());
    }
}
