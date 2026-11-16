<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CircuitSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Helpers\CircuitProductFactory;
use Tygh\Addons\SphinxHolidays\Providers\SphinxCircuitProductProvider;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;

/**
 * Fetches circuits from the Sphinx static API and syncs them into the local DB.
 *
 * Simpler than HotelSyncService — circuits are a flat catalog without
 * country-based partitioning, and the destination whitelist does not apply.
 *
 * Besides the circuits it saves their departures (each date, city and price
 * the rates have, in Travel Core's shared ?:travel_circuit_departures), from
 * the rates it reads anyway to know which circuits are still on sale
 * (docs/circuit-page-plan.md).
 *
 * @since 1.2.0
 */
class CircuitSyncService extends AbstractSyncService implements CircuitSyncServiceInterface
{
    private const int UPSERT_BATCH_SIZE = 100;
    private const int PER_PAGE = 1000;

    public function __construct(SphinxApi $api)
    {
        parent::__construct($api);
    }

    #[\Override]
    protected function getSyncType(): string
    {
        return 'circuits';
    }

    /**
     * Run circuit sync from static API.
     *
     * @param bool $fullSync When false, only circuits updated since the last
     *                       successful sync are re-fetched (updated_since).
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    #[\Override]
    public function sync(bool $fullSync = true): array
    {
        $context = [];
        if (!$fullSync) {
            $lastSynced = (new CircuitRepository())->getLastSyncedAt();
            if ($lastSynced !== null) {
                $context['updated_since'] = $lastSynced;
            }
        }

        return $this->runSync($fullSync, $context);
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[\Override]
    protected function doSync(bool $fullSync, array $stats, array $context): array
    {
        // Every active circuit is synced: the destination whitelist is for
        // hotels; circuits are not filtered by it (decided 2026-11).
        $updatedSince = TypeCoerce::toString($context['updated_since'] ?? '');
        if ($updatedSince !== '') {
            $stats['sync_mode'] = 'incremental';
            $this->output('Circuit sync starting incrementally (updated since ' . $updatedSince . ')...');
        } else {
            $this->output('Circuit sync starting...');
        }

        $allCircuits = [];
        $page = 1;

        while (true) {
            $response = $this->api->getCircuits($page, self::PER_PAGE, $updatedSince !== '' ? $updatedSince : null);
            if ($response === null) {
                $stats['error'] = 'API request failed on page ' . $page;
                break;
            }

            $items = $this->extractItems($response);
            if (empty($items)) {
                break;
            }

            foreach ($items as $raw) {
                $normalized = $this->normalizeCircuit(TypeCoerce::toStringMap($raw));
                if ($normalized === null) {
                    $stats['failed'] = TypeCoerce::toInt($stats['failed']) + 1;
                    continue;
                }

                $allCircuits[] = $normalized;
                $stats['total'] = TypeCoerce::toInt($stats['total']) + 1;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, TypeCoerce::toInt($stats['total']) + TypeCoerce::toInt($stats['failed']))) {
                break;
            }
            $page++;
        }

        // Only the circuits Sphinx still prices from today on: the static list
        // keeps circuits whose departures are all past. Unknown (the rates
        // could not be read): keep everything, drop nothing this run.
        // Asked about: the circuits just fetched and those stored already (an
        // incremental run fetches only the changed ones; a stored circuit's
        // departures pass without a change).
        $candidates = array_merge(
            array_map(static fn (array $circuit): int => TypeCoerce::toInt($circuit['circuit_id'] ?? 0), $allCircuits),
            (new CircuitRepository())->allIds(),
        );
        // Every month is read: the rates also say each circuit's departure
        // cities and months (the static list has neither).
        $rates = new BookableCircuits($this->api);
        $bookable = $rates->ids(date('Y-m-d'), $candidates, true);
        $past = 0;
        if ($bookable === null) {
            $this->output('WARNING: the circuit rates could not be read; no circuit is left out or removed this run.');
        } else {
            [$allCircuits, $past] = self::bookableOnly($allCircuits, $bookable);
            $stats['total'] = count($allCircuits);
        }

        if (!empty($allCircuits)) {
            $total = TypeCoerce::toInt($stats['total']);
            $this->output("Upserting {$total} circuits...");
            $batches = array_chunk($allCircuits, self::UPSERT_BATCH_SIZE);
            foreach ($batches as $batch) {
                $stats['synced'] = TypeCoerce::toInt($stats['synced']) + $this->upsertBatch($batch);
            }
        }

        // The stored circuits Sphinx no longer prices leave the list: removed,
        // or set inactive when a product was made for them (its link stays).
        $pruneMsg = '';
        if ($bookable !== null && TypeCoerce::toString($stats['error']) === '') {
            $pruned = (new CircuitRepository())->pruneUnbookable($bookable);
            $pruneMsg = ", {$past} skipped (no rate from today on), {$pruned['removed']} stored ones removed, {$pruned['deactivated']} set inactive (they have a product; {$pruned['hidden']} products hidden), {$pruned['shown']} products on sale again";
        }

        // The products of the circuits just read follow Sphinx's text: an
        // incremental run reads only the changed circuits, a full one all.
        if (!empty($allCircuits)) {
            $languages = TypeCoerce::toStringList(ConfigProvider::getProductLanguages());
            $refreshed = (new CircuitProductFactory(new CircuitRepository()))->refreshDescriptions($allCircuits, $languages !== [] ? $languages : [TypeCoerce::toString(CART_LANGUAGE)]);
            if ($refreshed > 0) {
                $this->output("Descriptions updated on {$refreshed} circuit products.");
            }
        }

        // The departure cities and months of the circuits on sale, then
        // their products' features (Feature Mappings › Circuits): the months
        // change as departures pass, so every run writes them again.
        if ($bookable !== null) {
            (new CircuitRepository())->saveDepartureFacts(self::withStaticCities(
                array_intersect_key($rates->facts(), array_flip($bookable)),
                $this->staticCities,
            ));
            $this->saveDepartures($rates->departures(), $bookable);
            $features = new CircuitFeatures();
            $withFeatures = $features->applyAll();
            if ($withFeatures > 0) {
                $this->output("Features written for {$withFeatures} circuit products.");
            }
            foreach ($features->summary() as $line) {
                $this->output('Features not written: ' . $line);
            }
        }

        $stats['success'] = true;
        $synced = TypeCoerce::toInt($stats['synced']);
        $total = TypeCoerce::toInt($stats['total']);
        $failed = TypeCoerce::toInt($stats['failed']);
        $this->output("Circuit sync complete: {$synced}/{$total} synced, {$failed} failed{$pruneMsg}.");

        return $stats;
    }

    /**
     * The circuits of $circuits Sphinx still prices (BookableCircuits), and
     * how many were left out.
     *
     * @param list<array<string, mixed>> $circuits normalized rows (circuit_id)
     * @param list<int> $bookable
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public static function bookableOnly(array $circuits, array $bookable): array
    {
        $keep = array_flip($bookable);
        $kept = array_values(array_filter(
            $circuits,
            static fn (array $circuit): bool => isset($keep[TypeCoerce::toInt($circuit['circuit_id'] ?? 0)]),
        ));

        return [$kept, count($circuits) - count($kept)];
    }

    /**
     * The departure cities to save for each circuit: the rates' (facts),
     * and, for a circuit whose rates name no city, the ones the static list
     * gave this run. Without either the circuit keeps none, as before.
     *
     * @param array<int, array{months: list<string>, departures: array<int, string>}> $facts by circuit id (BookableCircuits::facts())
     * @param array<int, array<int, string>> $staticCities circuit id => destination id => name, for the circuits read this run
     *
     * @return array<int, array{months: list<string>, departures: array<int, string>}>
     */
    public static function withStaticCities(array $facts, array $staticCities): array
    {
        foreach ($facts as $circuitId => $fact) {
            $cities = $staticCities[$circuitId] ?? [];
            if ($fact['departures'] === [] && $cities !== []) {
                $facts[$circuitId]['departures'] = $cities;
            }
        }

        return $facts;
    }

    /**
     * Saves the departures of the circuits on sale (one complete reading of
     * the rates: every month, every page) to Travel Core's shared table,
     * where the circuit's page and the admin read them. What the reading no
     * longer has is removed there. A failure here is reported and the sync
     * goes on: the circuits themselves are already saved.
     *
     * @param array<int, list<array{code: string, name: string, date: string, price: float, currency: string}>> $departures by circuit id (BookableCircuits::departures())
     * @param list<int> $bookable the circuits on sale
     */
    private function saveDepartures(array $departures, array $bookable): void
    {
        try {
            $names = $this->cityNames(CircuitDepartureRows::unnamedCities($departures, $bookable, $this->staticCityByDate));
            $rows = CircuitDepartureRows::build($departures, $bookable, $this->staticCityByDate, $names);
            $saved = (new CircuitDepartureRepository())->replace(SphinxCircuitProductProvider::PROVIDER, $rows, date('Y-m-d H:i:s'));
            $this->output("Departures saved: {$saved['written']} ({$saved['removed']} past or withdrawn removed).");
        } catch (\Throwable $e) {
            $this->output('WARNING: the departures could not be saved: ' . $e->getMessage());
        }
    }

    /** @var array<int, string> destination_id => name, filled as circuits are read */
    private array $destinationNames = [];

    /** @var array<int, array<int, string>> circuit id => destination id => name: the cities the static list's departures name, for the circuits read this run */
    private array $staticCities = [];

    /** @var array<int, array<string, int>> circuit id => Y-m-d => destination id: the city of each dated departure of the static list, for the circuits read this run */
    private array $staticCityByDate = [];

    /**
     * The names of static destinations, by id: from what the circuits read
     * so far said, else the stored destination tree (sphinx_destinations).
     * An id not stored yet has no entry.
     *
     * @param list<int> $ids
     *
     * @return array<int, string> destination id => name
     */
    private function cityNames(array $ids): array
    {
        $missing = array_values(array_filter($ids, fn (int $id): bool => !isset($this->destinationNames[$id])));
        if ($missing !== []) {
            foreach ((array) db_get_hash_single_array(
                'SELECT destination_id, name FROM ?:sphinx_destinations WHERE destination_id IN (?n)',
                ['destination_id', 'name'],
                $missing,
            ) as $id => $name) {
                $this->destinationNames[(int) $id] = TypeCoerce::toString($name);
            }
        }

        $names = [];
        foreach ($ids as $id) {
            $name = $this->destinationNames[$id] ?? '';
            if ($name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * The destinations of one circuit. The static API lists them as plain
     * ids (`[122417, 2440]`, ids of the static destinations); an object
     * `{id, name}` is read as well. Names come from the stored destination
     * tree (sphinx_destinations); an id not stored yet keeps no name.
     *
     * @return array{0: list<int>, 1: list<string>} ids, names
     */
    private function destinations(mixed $raw): array
    {
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $dest) {
            if (is_array($dest)) {
                $id = TypeCoerce::toInt($dest['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                    if (isset($dest['name']) && TypeCoerce::toString($dest['name']) !== '') {
                        $this->destinationNames[$id] = TypeCoerce::toString($dest['name']);
                    }
                }
            } elseif (is_numeric($dest) && (int) $dest > 0) {
                $ids[] = (int) $dest;
            }
        }
        $ids = array_values(array_unique($ids));

        return [$ids, array_values($this->cityNames($ids))];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeCircuit(array $raw): ?array
    {
        $id = TypeCoerce::toInt($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $name = TypeCoerce::toString($raw['name'] ?? $raw['title'] ?? '');
        if ($name === '') {
            return null;
        }

        [$destIds, $destNames] = $this->destinations($raw['destinations'] ?? $raw['destinatons'] ?? []);

        $departures = $raw['departures'] ?? [];
        $depIds = [];
        $depNames = [];
        foreach (TypeCoerce::toRowList($departures) as $dep) {
            if (isset($dep['id'])) {
                $depIds[] = TypeCoerce::toInt($dep['id']);
            }
            if (isset($dep['name'])) {
                $depNames[] = TypeCoerce::toString($dep['name']);
            }
        }
        if ($depIds === []) {
            // As the static list sends them, {date, time, destination_id}:
            // the city is a static destination, named from the stored tree.
            // Only departures from today on count (the list keeps past ones).
            $today = date('Y-m-d');
            $cities = $this->cityNames(CircuitStaticContent::departureCities($raw, $today));
            $this->staticCities[$id] = $cities;
            $this->staticCityByDate[$id] = CircuitStaticContent::departureCityByDate($raw, $today);
            $depIds = array_keys($cities);
            $depNames = array_values($cities);
        }

        $duration = TypeCoerce::toStringMap($raw['duration'] ?? []);
        $pricing = TypeCoerce::toStringMap($raw['pricing'] ?? []);

        // The two columns are written only when the static list names
        // cities: otherwise they keep what the rates gave
        // (saveDepartureFacts), not NULL. The rates, read next, have the
        // last word on them.
        $departureColumns = $depIds !== [] ? [
            'departure_ids' => json_encode($depIds),
            'departure_names' => $depNames !== [] ? implode(', ', $depNames) : null,
        ] : [];

        return $departureColumns + [
            'circuit_id' => $id,
            'name' => $name,
            'summary' => TypeCoerce::toString($raw['summary'] ?? ''),
            'description' => TypeCoerce::toString($raw['description'] ?? ''),
            'duration_days' => TypeCoerce::toInt($duration['days'] ?? $raw['duration_days'] ?? 0),
            'duration_nights' => TypeCoerce::toInt($duration['nights'] ?? $raw['duration_nights'] ?? 0),
            'transport_type' => TypeCoerce::toString($raw['transport_type'] ?? ''),
            'destination_ids' => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names' => !empty($destNames) ? implode(', ', $destNames) : null,
            'image_url' => CircuitStaticContent::imageUrl($raw),
            'itinerary_json' => !empty($raw['itinerary']) ? json_encode($raw['itinerary']) : null,
            'details_json' => CircuitStaticContent::details($raw),
            'tags_json' => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price' => isset($pricing['selling_price']) ? TypeCoerce::toFloat($pricing['selling_price']) : null,
            'currency' => TypeCoerce::toString($pricing['currency'] ?? 'EUR'),
            'sync_status' => 'active',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function upsertBatch(array $batch): int
    {
        $repo = new CircuitRepository();
        $affected = 0;
        foreach ($batch as $row) {
            $repo->upsert(TypeCoerce::toInt($row['circuit_id']), $row);
            $affected++;
        }
        return $affected;
    }
}
