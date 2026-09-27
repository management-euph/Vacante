<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services\Geocoding;

use Tygh\Addons\TravelCore\Exceptions\GeocodeTransportException;

/**
 * Walks a provider's geocode backlog at a policy-compliant pace.
 *
 * The OSM Nominatim usage policy caps an APPLICATION at one request per
 * second. Each provider addon knows its own table (how to find candidates,
 * how to store a result) but must NOT own the pacing — two addons pacing
 * themselves would run two requests per second against the public instance
 * and risk the shop's IP being blocked. So the loop, the sleep and the
 * abort-on-transport-failure rule live here, once.
 *
 * The addon supplies two closures:
 *   $candidates(int $limit): list<array{id, lat, lng, label}>
 *   $persist(string $id, ?ReverseGeocodeResult $result): void
 *
 * $persist is called with null when the lookup found nothing usable, so the
 * addon can still stamp the row and stop re-trying it every run.
 */
final class GeocodeBacklogRunner
{
    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(string): void */
    private $output;

    /**
     * @param callable(int): void|null $sleeper Injectable so tests run without real sleeps
     * @param callable(string): void|null $output Progress sink
     */
    public function __construct(
        private readonly NominatimClient $client,
        ?callable $sleeper = null,
        ?callable $output = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /**
     * @param list<array<string, mixed>> $candidates Rows of {id, lat, lng, label}
     * @param callable(string, ReverseGeocodeResult|null): void $persist
     * @return array{found: int, empty: int, aborted: bool, error: string}
     */
    public function run(array $candidates, callable $persist): array
    {
        $found = 0;
        $empty = 0;
        $aborted = false;
        $error = '';

        foreach ($candidates as $i => $row) {
            if ($i > 0) {
                // Policy: max 1 request/second against the public instance.
                ($this->sleeper)(1);
            }

            $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            if ($id === '') {
                continue;
            }

            try {
                $result = $this->client->reverseDetailed(
                    is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : 0.0,
                    is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : 0.0,
                );
            } catch (GeocodeTransportException $e) {
                // Stop the whole run: a failing endpoint (or a rate-limit
                // block) would otherwise burn the entire backlog on errors.
                $aborted = true;
                $error = $e->getMessage();
                ($this->output)("ABORT: {$error} — remaining rows stay unstamped for the next run.");
                break;
            }

            $label = is_scalar($row['label'] ?? null) ? (string) $row['label'] : '';

            if ($result->isEmpty()) {
                // Stamp anyway so the row is not retried every run.
                $persist($id, null);
                $empty++;
                ($this->output)("  {$id} | {$label} -> (nothing found)");
                continue;
            }

            $persist($id, $result);
            $found++;
            ($this->output)(sprintf(
                '  %s | %s -> %s',
                $id,
                $label,
                implode(', ', array_filter([$result->city, $result->region, $result->street])),
            ));
        }

        return ['found' => $found, 'empty' => $empty, 'aborted' => $aborted, 'error' => $error];
    }
}
