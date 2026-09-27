<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\Geocoding\GeocodeBacklogRunner;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;
use Tygh\Addons\TravelCore\Services\Geocoding\ReverseGeocodeResult;

/**
 * Reverse-geocode hotel coordinates into approximate street addresses via
 * OSM Nominatim — the Novoton API has no street field, so this is the only
 * source for the "street, city, country" PDP location line.
 *
 * Every attempt stamps geocoded_at, even when no street was found, so the
 * backlog drains monotonically and re-runs are naturally resumable with no
 * state file: a candidate is simply a row with real coordinates and no
 * stamp.
 *
 * The loop, the 1 req/sec pacing and the abort-on-transport-failure rule
 * belong to travel_core's GeocodeBacklogRunner, NOT to this class: the
 * Nominatim policy limits requests per APPLICATION, so novoton and sphinx
 * must not each keep their own pacer — two of them would double the real
 * rate. This command supplies only what is novoton-specific: the candidate
 * rows, and what to store for each result.
 *
 * Params:
 *   limit=N   hotels per run (default 300 ≈ 5 min at 1 req/s)
 *   status=1  print backlog counters, no processing
 *   force=1   clear all stamps first (full re-geocode)
 */
class GeocodeAddressesCommand extends AbstractCronCommand
{
    private const int DEFAULT_LIMIT = 300;

    /** Rows with real coordinates that have never been attempted. */
    private const string ELIGIBLE_WHERE = 'latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0 AND longitude <> 0 AND geocoded_at IS NULL';

    private ?NominatimClient $client = null;

    /** Pacing hook (seconds), injectable for tests. */
    private ?\Closure $sleeper = null;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['geocode_addresses'];
    }

    public static function getDescription(): string
    {
        return 'Reverse-geocode hotel coordinates into street addresses (OSM Nominatim)';
    }

    public function setClient(NominatimClient $client): void
    {
        $this->client = $client;
    }

    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = $sleeper(...);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if (!empty($this->getParam('status'))) {
            return $this->printStatus();
        }

        if (!ConfigProvider::isGeocodingEnabled()) {
            $this->output('Geocoding is disabled — enable it in Travel Core settings first.');

            return ['success' => false, 'error' => 'Geocoding disabled in settings'];
        }

        $contactEmail = ConfigProvider::getGeocodingContactEmail();
        if ($contactEmail === '') {
            $this->output('Set the geocoding contact email in Travel Core settings first — the Nominatim usage policy requires identifying the application.');

            return ['success' => false, 'error' => 'Missing geocoding contact email'];
        }

        if (!empty($this->getParam('force'))) {
            db_query('UPDATE ?:novoton_hotels SET geocoded_at = NULL');
            $this->output('force=1: cleared all geocode stamps — full re-geocode.');
        }

        $limit = TypeCoerce::toInt($this->getParam('limit', self::DEFAULT_LIMIT));
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $hotels = $this->getCandidates($limit);

        if ($hotels === []) {
            $this->output('Nothing to geocode — every hotel with coordinates is already stamped.');
            $this->output('(Hotels without coordinates become eligible after a hotel_list sync.)');
            $stats = ['geocoded' => 0, 'no_street' => 0, 'remaining' => 0];
            $this->logComplete('geocode_addresses', $stats);

            return ['success' => true, 'stats' => $stats];
        }

        $endpoint = ConfigProvider::getGeocodingEndpoint();
        $this->output('Reverse geocoding ' . count($hotels) . " hotels via {$endpoint} (1 req/s)...");
        $this->output('');

        $runner = new GeocodeBacklogRunner(
            $this->client ?? new NominatimClient(null, $endpoint, $contactEmail, 'NovotonHolidays/1.0'),
            $this->sleeper,
            $this->output(...),
        );

        $withStreet = 0;
        $noStreet = 0;

        // Counted HERE rather than from the runner's found/empty tally: the
        // runner calls a result "found" when it carries anything at all,
        // while novoton only stores — and only counts — the street. A hit
        // that names the city but no street is `no_street` to us.
        $persist = static function (string $hotelId, ?ReverseGeocodeResult $result) use (&$withStreet, &$noStreet): void {
            $street = $result === null ? '' : $result->street;

            // Stamp even when no street was found so the hotel is not
            // retried every run; a NULL street keeps the display on the
            // city/region line.
            if ($street === '') {
                db_query(
                    'UPDATE ?:novoton_hotels SET street_address = NULL, geocoded_at = NOW() WHERE hotel_id = ?s',
                    $hotelId,
                );
                $noStreet++;

                return;
            }

            db_query(
                'UPDATE ?:novoton_hotels SET street_address = ?s, geocoded_at = NOW() WHERE hotel_id = ?s',
                $street,
                $hotelId,
            );
            $withStreet++;
        };

        $candidates = [];
        foreach ($hotels as $hotel) {
            $label = TypeCoerce::toString($hotel['hotel_name'] ?? '');
            $candidates[] = [
                'id' => TypeCoerce::toString($hotel['hotel_id'] ?? ''),
                'label' => $label !== '' ? $label : '(unnamed)',
                'lat' => TypeCoerce::toFloat($hotel['latitude'] ?? 0),
                'lng' => TypeCoerce::toFloat($hotel['longitude'] ?? 0),
            ];
        }

        $runStats = $runner->run($candidates, $persist);
        $aborted = (bool) $runStats['aborted'];
        $abortError = TypeCoerce::toString($runStats['error']);

        $remaining = $this->countCandidates();

        $this->output('');
        $this->output("Done: {$withStreet} with street, {$noStreet} without. Remaining backlog: {$remaining}.");

        $stats = [
            'geocoded' => $withStreet,
            'no_street' => $noStreet,
            'remaining' => $remaining,
            'aborted' => $aborted,
        ];
        $this->logComplete('geocode_addresses', $stats);
        $this->logToSyncTable('geocode_addresses', $withStreet + $noStreet, $aborted ? 1 : 0);

        if ($aborted) {
            return ['success' => false, 'error' => $abortError, 'stats' => $stats];
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * @return array<string, mixed>
     */
    private function printStatus(): array
    {
        $pending = $this->countCandidates();
        $attempted = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE geocoded_at IS NOT NULL',
        ));
        $withStreet = TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotels WHERE street_address IS NOT NULL AND street_address != ''",
        ));
        $noCoords = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE latitude IS NULL OR longitude IS NULL OR latitude = 0 OR longitude = 0',
        ));

        $this->output('Geocoding status:');
        $this->output("  Pending (have coordinates, not yet attempted): {$pending}");
        $this->output("  Attempted: {$attempted} (streets found: {$withStreet})");
        $this->output("  Without usable coordinates (run mode=hotel_list first): {$noCoords}");

        return [
            'success' => true,
            'stats' => [
                'pending' => $pending,
                'attempted' => $attempted,
                'with_street' => $withStreet,
                'no_coordinates' => $noCoords,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getCandidates(int $limit): array
    {
        return TypeCoerce::toRowList(db_get_array(
            'SELECT hotel_id, hotel_name, latitude, longitude FROM ?:novoton_hotels WHERE '
                . self::ELIGIBLE_WHERE . ' ORDER BY hotel_id LIMIT ?i',
            $limit,
        ));
    }

    private function countCandidates(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE ' . self::ELIGIBLE_WHERE,
        ));
    }
}
