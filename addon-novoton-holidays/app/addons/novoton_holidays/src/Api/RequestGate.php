<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Keeps every Novoton request of the store within what Novoton asked for
 * (28 Oct 2026): at most 5 at the same time and 5 a second, both settings
 * (Advanced → API resilience). The cron jobs and customers' searches run in
 * separate PHP processes, so the count lives in one small file on the
 * server, read and written under an exclusive lock.
 *
 * A caller takes tickets before it sends, and gives them back when the
 * answers are in. It gets as many as are free, at least one, waiting while
 * none is: a batch then sends what it got and asks again for the rest, so
 * no process holds tickets while waiting for more, and two never wait on
 * each other. A ticket a process never gave back (it died mid-request)
 * lapses after TICKET_TTL seconds, longer than any request may take.
 *
 * When the file cannot be used, requests go out ungated rather than not at
 * all: the gate protects Novoton, it must not stop the store.
 */
final class RequestGate
{
    /** Novoton's numbers, the settings' defaults. */
    public const int MAX_CONCURRENT = 5;

    public const int MAX_PER_SECOND = 5;

    /** Seconds a ticket counts at most: above NovotonHttpClient's 120-second timeout. */
    public const int TICKET_TTL = 150;

    /** Longest single wait for a ticket before trying again (seconds). */
    private const float POLL = 0.1;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    private bool $broken = false;

    private int $issued = 0;

    private readonly int $maxConcurrent;

    private readonly int $maxPerSecond;

    /**
     * @param (\Closure(): float)|null $clock seconds
     * @param (\Closure(float): void)|null $sleep seconds
     */
    public function __construct(
        private readonly string $path,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
        int $maxConcurrent = self::MAX_CONCURRENT,
        int $maxPerSecond = self::MAX_PER_SECOND,
    ) {
        $this->maxConcurrent = max(1, $maxConcurrent);
        $this->maxPerSecond = max(1, $maxPerSecond);
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** The store's gate, its file in CS-Cart's cache directory, its limits from the settings. */
    public static function shared(): self
    {
        $dir = defined('DIR_CACHE') && is_string(DIR_CACHE) ? DIR_CACHE : sys_get_temp_dir();

        return new self(
            rtrim($dir, '/') . '/novoton_request_gate.json',
            null,
            null,
            ConfigProvider::getApiMaxConcurrent(),
            ConfigProvider::getApiMaxPerSecond(),
        );
    }

    /**
     * Tickets for up to $want requests, at least one: waits while none is
     * free. An empty list means the gate is off (its file cannot be used).
     *
     * @return list<string>
     */
    public function acquire(int $want = 1): array
    {
        $want = max(1, min($this->maxConcurrent, $want));
        while (true) {
            if ($this->broken) {
                return [];
            }
            $now = ($this->clock)();
            $wait = 0.0;
            $tickets = $this->locked(function (array $state) use ($want, $now, &$wait): array {
                $active = array_filter($state['active'], static fn (float $until): bool => $until > $now);
                $starts = array_values(array_filter($state['starts'], static fn (float $at): bool => $at > $now - 1.0));
                $free = min($this->maxConcurrent - count($active), $this->maxPerSecond - count($starts));
                if ($free <= 0) {
                    $wait = count($starts) >= $this->maxPerSecond ? min($starts) + 1.0 - $now : self::POLL;
                    $state['active'] = $active;
                    $state['starts'] = $starts;

                    return [$state, []];
                }
                $tickets = [];
                for ($i = 0; $i < min($want, $free); $i++) {
                    $ticket = getmypid() . '-' . (++$this->issued) . '-' . bin2hex(random_bytes(3));
                    $active[$ticket] = $now + self::TICKET_TTL;
                    $starts[] = $now;
                    $tickets[] = $ticket;
                }
                $state['active'] = $active;
                $state['starts'] = $starts;

                return [$state, $tickets];
            });
            if ($tickets !== []) {
                return $tickets;
            }
            ($this->sleep)(max(0.01, min(self::POLL * 10, $wait)));
        }
    }

    /**
     * Gives tickets back once their requests are answered.
     *
     * @param list<string> $tickets
     */
    public function release(array $tickets): void
    {
        if ($tickets === [] || $this->broken) {
            return;
        }
        $this->locked(static function (array $state) use ($tickets): array {
            foreach ($tickets as $ticket) {
                unset($state['active'][$ticket]);
            }

            return [$state, []];
        });
    }

    /**
     * Runs $change on the state under an exclusive lock and saves what it
     * returns; on any file error the gate turns itself off.
     *
     * @param \Closure(array{active: array<string, float>, starts: list<float>}): array{0: array{active: array<string, float>, starts: list<float>}, 1: list<string>} $change
     *
     * @return list<string>
     */
    private function locked(\Closure $change): array
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            $this->broken = true;

            return [];
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                $this->broken = true;

                return [];
            }
            $raw = stream_get_contents($handle);
            [$state, $result] = $change(self::decode(is_string($raw) ? $raw : ''));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{active: array<string, float>, starts: list<float>} */
    private static function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        $active = [];
        $starts = [];
        if (is_array($data)) {
            foreach (is_array($data['active'] ?? null) ? $data['active'] : [] as $ticket => $until) {
                $active[(string) $ticket] = TypeCoerce::toFloat($until);
            }
            foreach (is_array($data['starts'] ?? null) ? $data['starts'] : [] as $at) {
                $starts[] = TypeCoerce::toFloat($at);
            }
        }

        return ['active' => $active, 'starts' => $starts];
    }
}
