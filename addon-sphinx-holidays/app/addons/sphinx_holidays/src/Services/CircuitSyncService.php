<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CircuitSyncServiceInterface;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Fetches circuits from the Sphinx static API and syncs them into the local DB.
 *
 * Simpler than HotelSyncService — circuits are a flat catalog without
 * country-based partitioning.
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
        $allowedDestIds = ConfigProvider::getAllowedDestinationIds();
        if (empty($allowedDestIds)) {
            $stats['error'] = 'No sync targets configured. Configure destinations in Sphinx Holidays > Whitelist.';
            $this->output('ERROR: ' . $stats['error']);
            return $stats;
        }

        $updatedSince = TypeCoerce::toString($context['updated_since'] ?? '');
        if ($updatedSince !== '') {
            $stats['sync_mode'] = 'incremental';
            $this->output('Circuit sync starting incrementally (updated since ' . $updatedSince . ', filtering by ' . count($allowedDestIds) . ' allowed destinations)...');
        } else {
            $this->output('Circuit sync starting (filtering by ' . count($allowedDestIds) . ' allowed destinations)...');
        }

        $allCircuits = [];
        $filtered = 0;
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

                // Client-side filtering: skip circuits outside sync targets
                $circuitDestIds = !empty($normalized['destination_ids'])
                    ? TypeCoerce::toIntList(json_decode(TypeCoerce::toString($normalized['destination_ids']), true) ?: [])
                    : [];
                if (!empty($circuitDestIds) && empty(array_intersect($circuitDestIds, $allowedDestIds))) {
                    $filtered++;
                    continue;
                }

                $allCircuits[] = $normalized;
                $stats['total'] = TypeCoerce::toInt($stats['total']) + 1;
            }

            if (!$this->hasMorePages($response, $page, self::PER_PAGE, TypeCoerce::toInt($stats['total']) + TypeCoerce::toInt($stats['failed']) + $filtered)) {
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
        $bookable = (new BookableCircuits($this->api))->ids(date('Y-m-d'), $candidates);
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
            $pruneMsg = ", {$past} skipped (no rate from today on), {$pruned['removed']} stored ones removed, {$pruned['deactivated']} set inactive (they have a product)";
        }

        $stats['success'] = true;
        $filterMsg = $filtered > 0 ? ", {$filtered} filtered (outside sync targets)" : '';
        $synced = TypeCoerce::toInt($stats['synced']);
        $total = TypeCoerce::toInt($stats['total']);
        $failed = TypeCoerce::toInt($stats['failed']);
        $this->output("Circuit sync complete: {$synced}/{$total} synced, {$failed} failed{$filterMsg}{$pruneMsg}.");

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

        $destinations = $raw['destinations'] ?? $raw['destinatons'] ?? [];
        $destIds = [];
        $destNames = [];
        foreach (TypeCoerce::toRowList($destinations) as $dest) {
            if (isset($dest['id'])) {
                $destIds[] = TypeCoerce::toInt($dest['id']);
            }
            if (isset($dest['name'])) {
                $destNames[] = TypeCoerce::toString($dest['name']);
            }
        }

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

        return [
            'circuit_id' => $id,
            'name' => $name,
            'summary' => TypeCoerce::toString($raw['summary'] ?? ''),
            'description' => TypeCoerce::toString($raw['description'] ?? ''),
            'duration_days' => TypeCoerce::toInt($duration['days'] ?? $raw['duration_days'] ?? 0),
            'duration_nights' => TypeCoerce::toInt($duration['nights'] ?? $raw['duration_nights'] ?? 0),
            'transport_type' => TypeCoerce::toString($raw['transport_type'] ?? ''),
            'destination_ids' => !empty($destIds) ? json_encode($destIds) : null,
            'destination_names' => !empty($destNames) ? implode(', ', $destNames) : null,
            'departure_ids' => !empty($depIds) ? json_encode($depIds) : null,
            'departure_names' => !empty($depNames) ? implode(', ', $depNames) : null,
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
