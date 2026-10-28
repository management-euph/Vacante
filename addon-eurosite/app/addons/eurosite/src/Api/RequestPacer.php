<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Api;

/**
 * How fast the scheduled jobs talk to Eurosite.
 *
 * Eurosite set no numbers (28 Oct 2026): there are no fixed limits, but a
 * connection that looks like abuse is closed. We use Sphinx's: no limit on
 * static data, 10 a minute for the rest. The jobs already send one request
 * at a time; on top of that a price search (getHotelPriceRequest, the heavy
 * one) starts at most every SEARCH_GAP seconds, any other request at most
 * every GAP seconds.
 *
 * Only for the scheduled runs: cron.php and the cron URL start it before the
 * job. A customer's search, or a run started from the dashboard, is never
 * held back.
 */
final class RequestPacer
{
    /** Seconds between two price searches: 10 a minute. */
    public const float SEARCH_GAP = 6.0;

    /** Seconds between any two requests. */
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
     */
    public function __construct(?\Closure $clock = null, ?\Closure $sleep = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** Paces every request this process sends from now on. */
    public static function start(?self $pacer = null): void
    {
        self::$active = $pacer ?? new self();
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
        $ready = max($this->lastAt + self::GAP, $search ? $this->lastSearchAt + self::SEARCH_GAP : 0.0);
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
