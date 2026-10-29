<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Api;

use Tygh\Addons\Eurosite\Services\ConfigProvider;

/**
 * How fast the scheduled jobs talk to Eurosite.
 *
 * Eurosite set no numbers (28 Oct 2026): there are no fixed limits, but a
 * connection that looks like abuse is closed. We use Sphinx's: no limit on
 * static data, 10 a minute for the rest. The jobs already send one request
 * at a time; on top of that a price search (getHotelPriceRequest, the heavy
 * one) starts at most 10 times a minute, any other request at most once a
 * second. Both are settings (Scheduled runs: pace and limits).
 *
 * Only for the scheduled runs: cron.php and the cron URL start it before the
 * job. A customer's search, or a run started from the dashboard, is never
 * held back.
 */
final class RequestPacer
{
    /** Seconds between two price searches: 10 a minute, the setting's default. */
    public const float SEARCH_GAP = 6.0;

    /** Seconds between any two requests: one a second, the setting's default. */
    public const float GAP = 1.0;

    private const array SEARCHES = ['getHotelPriceRequest'];

    private static ?self $active = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    private float $lastAt = 0.0;

    private float $lastSearchAt = 0.0;

    /**
     * @param (\Closure(): float)|null $clock seconds
     * @param (\Closure(float): void)|null $sleep seconds
     * @param float $searchGap seconds between two price searches
     * @param float $gap seconds between any two requests
     */
    public function __construct(
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
        private readonly float $searchGap = self::SEARCH_GAP,
        private readonly float $gap = self::GAP,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** A pacer from the settings' numbers: price searches and other requests a minute. */
    public static function perMinute(int $searches, int $requests, ?\Closure $clock = null, ?\Closure $sleep = null): self
    {
        return new self($clock, $sleep, 60.0 / max(1, $searches), 60.0 / max(1, $requests));
    }

    /** Paces every request this process sends from now on, at the settings' pace. */
    public static function start(?self $pacer = null): void
    {
        self::$active = $pacer ?? self::perMinute(
            ConfigProvider::getScheduledSearchesPerMinute(),
            ConfigProvider::getScheduledRequestsPerMinute(),
        );
    }

    public static function stop(): void
    {
        self::$active = null;
    }

    /** The pacer of a scheduled run, null otherwise (EurositeApiClient asks before each request). */
    public static function active(): ?self
    {
        return self::$active;
    }

    /** Waits until a request of $requestType may go, and notes it went. */
    public function wait(string $requestType): void
    {
        $now = ($this->clock)();
        $search = in_array($requestType, self::SEARCHES, true);
        $ready = max($this->lastAt + $this->gap, $search ? $this->lastSearchAt + $this->searchGap : 0.0);
        if ($ready > $now) {
            ($this->sleep)($ready - $now);
            $now = $ready;
        }
        $this->lastAt = $now;
        if ($search) {
            $this->lastSearchAt = $now;
        }
    }
}
