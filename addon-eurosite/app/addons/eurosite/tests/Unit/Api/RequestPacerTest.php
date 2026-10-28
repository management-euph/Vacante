<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\RequestPacer;

/**
 * Eurosite set no numbers (28 Oct 2026) and closes connections that look
 * like abuse; we use Sphinx's: no limit on static data, 10 a minute for the
 * rest. Scheduled runs only.
 */
final class RequestPacerTest extends TestCase
{
    private float $now = 1_000.0;

    /** @var list<float> */
    private array $slept = [];

    protected function tearDown(): void
    {
        RequestPacer::stop();
    }

    private function pacer(): RequestPacer
    {
        return new RequestPacer(
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
        );
    }

    public function testPriceSearchesGoAtMostTenAMinute(): void
    {
        $pacer = $this->pacer();
        foreach (range(1, 11) as $ignored) {
            $pacer->wait('getHotelPriceRequest');
        }

        self::assertEqualsWithDelta(60.0, $this->now - 1_000.0, 0.001, 'the eleventh search starts a minute after the first');
    }

    public function testOtherRequestsGoAtMostOnceASecond(): void
    {
        $pacer = $this->pacer();
        $pacer->wait('getOwnHotelsRequest');
        $this->now += 0.25; // the answer took a quarter of a second
        $pacer->wait('getProductInfoRequest');

        self::assertEqualsWithDelta([0.75], $this->slept, 0.001);
    }

    public function testASlowAnswerIsNotWaitedForTwice(): void
    {
        $pacer = $this->pacer();
        $pacer->wait('getHotelPriceRequest');
        $this->now += 8.0; // longer than the gap
        $pacer->wait('getHotelPriceRequest');

        self::assertSame([], $this->slept);
    }

    /** A customer's search, or a dashboard run: nothing started the pacer. */
    public function testOnlyAScheduledRunIsPaced(): void
    {
        self::assertNull(RequestPacer::active());

        RequestPacer::start($this->pacer());
        self::assertInstanceOf(RequestPacer::class, RequestPacer::active());
    }

    public function testTheClientWaitsItsTurn(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Api/EurositeApiClient.php');
        self::assertStringContainsString('RequestPacer::active()?->wait($requestType);', $src);

        foreach (['cron.php', 'controllers/frontend/eurosite_cron.php'] as $entry) {
            self::assertStringContainsString('RequestPacer::start();', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $entry), $entry);
        }
    }
}
