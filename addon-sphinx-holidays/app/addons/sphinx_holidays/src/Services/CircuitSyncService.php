<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CircuitSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Helpers\CircuitProductFactory;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Fetches circuits from the Sphinx static API and syncs them into the local DB.
 *
 * Simpler than HotelSyncService — circuits are a flat catalog without
 * country-based partitioning, and the destination whitelist does not apply.
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
            $languages = ConfigProvider::getProductLanguages();
            $refreshed = (new CircuitProductFactory(new CircuitRepository()))->refreshDescriptions($allCircuits, $languages !== [] ? $languages : [CART_LANGUAGE]);
            if ($refreshed > 0) {
                $this->output("Descriptions updated on {$refreshed} circuit products.");
            }
        }

        // The departure cities and months of the circuits on sale, then
        // their products' features (Feature Mappings › Circuits): the months
        // change as departures pass, so every run writes them again.
        if ($bookable !== null) {
            (new CircuitRepository())->saveDepartureFacts(array_intersect_key($rates->facts(), array_flip($bookable)));
            $withFeatures = (new CircuitFeatures())->applyAll();
            if ($withFeatures > 0) {
                $this->output("Features written for {$withFeatures} circuit products.");
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

    /** @var array<int, string> destination_id => name, filled as circuits are read */
    private array $destinationNames = [];

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
        $names = [];
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
        foreach ($ids as $id) {
            $name = $this->destinationNames[$id] ?? '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return [$ids, $names];
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

        $duration = TypeCoerce::toStringMap($raw['duration'] ?? []);
        $pricing = TypeCoerce::toStringMap($raw['pricing'] ?? []);

        // The static list's departures are dates with no city, so the two
        // columns are written only when it does name cities: otherwise they
        // keep what the rates gave (saveDepartureFacts), not NULL.
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
            'image_url' => TypeCoerce::toString($raw['image'] ?? $raw['image_url'] ?? ''),
            'itinerary_json' => !empty($raw['itinerary']) ? json_encode($raw['itinerary']) : null,
            'details_json' => self::details($raw),
            'tags_json' => !empty($raw['tags']) ? json_encode($raw['tags']) : null,
            'min_price' => isset($pricing['selling_price']) ? TypeCoerce::toFloat($pricing['selling_price']) : null,
            'currency' => TypeCoerce::toString($pricing['currency'] ?? 'EUR'),
            'sync_status' => 'active',
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * What a circuit's page shows besides its itinerary (board 16.3): what
     * the price includes and does not, the things to know, the group's size
     * and the travellers' age when the circuit asks one; null when the API
     * says none of it. Only these fields are kept.
     *
     * @param array<string, mixed> $raw one circuit of static/circuits
     */
    public static function details(array $raw): ?string
    {
        $items = static function (mixed $list): array {
            $out = [];
            foreach (TypeCoerce::toRowList($list) as $item) {
                $title = trim(TypeCoerce::toString($item['title'] ?? ''));
                $description = trim(TypeCoerce::toString($item['description'] ?? ''));
                if ($title !== '' || $description !== '') {
                    $out[] = ['title' => $title, 'description' => $description];
                }
            }

            return $out;
        };
        $range = static fn (array $limits): array => array_filter(
            ['min' => max(0, TypeCoerce::toInt($limits['min'] ?? 0)), 'max' => max(0, TypeCoerce::toInt($limits['max'] ?? 0))],
            static fn (int $value): bool => $value > 0,
        );
        // The API spells it "age_requirments".
        $age = TypeCoerce::toStringMap($raw['age_requirments'] ?? $raw['age_requirements'] ?? null);

        $details = array_filter([
            'included' => $items($raw['included'] ?? []),
            'not_included' => $items($raw['not_included'] ?? []),
            'additional_info' => $items($raw['additional_info'] ?? []),
            'group_size' => $range(TypeCoerce::toStringMap($raw['group_size'] ?? null)),
            'age' => ($age['is_required'] ?? false) === true ? $range($age) : [],
        ]);

        return $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_UNICODE);
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
