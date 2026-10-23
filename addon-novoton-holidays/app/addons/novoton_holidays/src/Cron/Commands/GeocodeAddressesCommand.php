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
 * state file.
 *
 * Which hotels a run takes (only hotels with real coordinates):
 *  - by default, hotels WITHOUT a street address: never tried (geocoded_at
 *    NULL: new hotels, and hotels whose coordinates moved, which the
 *    hotel_list sync un-stamps), or tried without a street more than
 *    RETRY_AFTER_DAYS ago (OpenStreetMap keeps filling in). A hotel that has
 *    a street is not asked again, so the hourly run costs nothing once the
 *    backlog is done;
 *  - force=1 re-geocodes EVERY hotel, streets included: it records "redo
 *    everything stamped before now" (REDO_KEY, storage_data) and every run,
 *    the scheduled ones too, takes the next batch until none is left. It
 *    used to wipe all stamps up front, which also lost which hotels had
 *    already been tried without a street.
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
 *   status=1  print the counters, no processing
 *   force=1   re-geocode every hotel, streets included (in batches, over runs)
 */
class GeocodeAddressesCommand extends AbstractCronCommand
{
    private const int DEFAULT_LIMIT = 300;

    /** A hotel tried without finding a street is tried again after this many days. */
    public const int RETRY_AFTER_DAYS = 30;

    /** storage_data: while set, hotels stamped before this (database) time are redone (force=1). */
    public const string REDO_KEY = 'novoton_geocode_redo_before';

    private const string HAS_COORDINATES = 'latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0 AND longitude <> 0';

    private const string NO_STREET = "(street_address IS NULL OR street_address = '')";

    private ?NominatimClient $client = null;

    /** Pacing hook (seconds), injectable for tests. */
    private ?\Closure $sleeper = null;

    /** @var (\Closure(): string)|null reads REDO_KEY, injectable for tests */
    private ?\Closure $readRedo = null;

    /** @var (\Closure(string): void)|null writes REDO_KEY ('' clears it), injectable for tests */
    private ?\Closure $writeRedo = null;

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
     * Where force=1 keeps its "redo before" time (default: CS-Cart storage_data).
     *
     * @param \Closure(): string $read
     * @param \Closure(string): void $write '' clears it
     */
    public function setRedoStore(\Closure $read, \Closure $write): void
    {
        $this->readRedo = $read;
        $this->writeRedo = $write;
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

        $limit = TypeCoerce::toInt($this->getParam('limit', self::DEFAULT_LIMIT));
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        if (!empty($this->getParam('force'))) {
            // The database's clock: the stamps are written with NOW().
            $this->writeRedo(TypeCoerce::toString(db_get_field('SELECT NOW()')));
            $this->output("force=1: every hotel with coordinates is geocoded again, streets included, {$limit} per run until done.");
        }
        $redoBefore = $this->readRedo();

        $hotels = $this->getCandidates($limit, $redoBefore);

        if ($hotels === []) {
            if ($redoBefore !== '') {
                $this->writeRedo('');
                $this->output('The re-geocode of every hotel is complete.');
            }
            $this->output('Nothing to geocode: every hotel with coordinates has a street, or was tried without one in the last ' . self::RETRY_AFTER_DAYS . ' days.');
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

        $remaining = $this->countCandidates($redoBefore);
        if ($redoBefore !== '' && $remaining === 0) {
            $this->writeRedo('');
            $this->output('The re-geocode of every hotel is complete.');
        }

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
        $redoBefore = $this->readRedo();
        $pending = $this->countCandidates($redoBefore);
        $withStreet = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE ' . self::HAS_COORDINATES . ' AND NOT ' . self::NO_STREET,
        ));
        $neverTried = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE ' . self::HAS_COORDINATES . ' AND geocoded_at IS NULL',
        ));
        $triedNoStreet = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE ' . self::HAS_COORDINATES . ' AND geocoded_at IS NOT NULL AND ' . self::NO_STREET,
        ));
        $noCoords = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE latitude IS NULL OR longitude IS NULL OR latitude = 0 OR longitude = 0',
        ));

        $this->output('Geocoding status:');
        $this->output("  With a street address: {$withStreet} (not asked again)");
        $this->output("  Never tried (new, or coordinates moved): {$neverTried}");
        $this->output("  Tried, no street found: {$triedNoStreet} (tried again after " . self::RETRY_AFTER_DAYS . ' days)');
        $this->output("  Due now: {$pending}" . ($redoBefore !== '' ? " (re-geocoding every hotel, since {$redoBefore})" : ''));
        $this->output("  Without usable coordinates (run mode=hotel_list first): {$noCoords}");

        return [
            'success' => true,
            'stats' => [
                'pending' => $pending,
                'with_street' => $withStreet,
                'never_tried' => $neverTried,
                'tried_no_street' => $triedNoStreet,
                'attempted' => $withStreet + $triedNoStreet,
                'redo_all_since' => $redoBefore,
                'no_coordinates' => $noCoords,
            ],
        ];
    }

    /**
     * The hotels due: see the class doc. While a force=1 re-geocode runs
     * ($redoBefore set), every hotel stamped before it, streets included.
     *
     * @return array{0: string, 1: list<int|string>} WHERE clause and its parameters
     */
    private static function dueWhere(string $redoBefore): array
    {
        if ($redoBefore !== '') {
            return [self::HAS_COORDINATES . ' AND (geocoded_at IS NULL OR geocoded_at < ?s)', [$redoBefore]];
        }

        return [
            self::HAS_COORDINATES . ' AND (geocoded_at IS NULL OR (' . self::NO_STREET . ' AND geocoded_at < DATE_SUB(NOW(), INTERVAL ?i DAY)))',
            [self::RETRY_AFTER_DAYS],
        ];
    }

    /**
     * Never-tried hotels first (NULL sorts first), then the oldest attempts.
     *
     * @return list<array<string, mixed>>
     */
    private function getCandidates(int $limit, string $redoBefore): array
    {
        [$where, $params] = self::dueWhere($redoBefore);

        return TypeCoerce::toRowList(db_get_array(
            'SELECT hotel_id, hotel_name, latitude, longitude FROM ?:novoton_hotels WHERE '
                . $where . ' ORDER BY geocoded_at, hotel_id LIMIT ?i',
            ...[...$params, $limit],
        ));
    }

    private function countCandidates(string $redoBefore): int
    {
        [$where, $params] = self::dueWhere($redoBefore);

        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:novoton_hotels WHERE ' . $where, ...$params));
    }

    private function readRedo(): string
    {
        if ($this->readRedo !== null) {
            return trim(($this->readRedo)());
        }

        return function_exists('fn_get_storage_data') ? trim(TypeCoerce::toString(fn_get_storage_data(self::REDO_KEY))) : '';
    }

    private function writeRedo(string $before): void
    {
        if ($this->writeRedo !== null) {
            ($this->writeRedo)($before);
        } elseif (function_exists('fn_set_storage_data')) {
            fn_set_storage_data(self::REDO_KEY, $before);
        }
    }
}
