<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The circuits Sphinx still sells: those it prices (circuits/rates, the
 * endpoint the circuit bar reads) on a departure from today on, within the
 * next MONTHS_AHEAD months. The static circuits list keeps circuits whose
 * departures are all past ("… 2024 (charter avion)"); the circuits sync
 * saves only the ones named here (CircuitSyncService).
 *
 * Month by month, and only for the circuits not found yet: a circuit with
 * a departure this month is not asked about next month, and the search
 * stops once every circuit is found (or the months run out).
 *
 * ids() is null when the rates could not be read (an API failure, too
 * many pages) or priced no circuit at all: the sync then keeps what it
 * has, never drops every circuit on a bad answer.
 */
final class BookableCircuits
{
    public const int MONTHS_AHEAD = 18;

    /** The most the endpoint gives per page. */
    private const int PER_PAGE = 100;

    /** Circuits asked about in one request. */
    private const int IDS_PER_REQUEST = 200;

    /** A stop for a runaway listing, per request. */
    private const int MAX_PAGES = 200;

    public function __construct(private readonly SphinxApi $api)
    {
    }

    /**
     * @param string $today Y-m-d
     * @param list<int> $candidates the circuits to check (from the static list and the store)
     * @return list<int>|null those with a rate from $today on; null when unknown
     */
    public function ids(string $today, array $candidates): ?array
    {
        $remaining = array_values(array_unique(array_filter($candidates, static fn (int $id): bool => $id > 0)));
        if ($remaining === []) {
            return null;
        }
        /** @var array<int, true> $found */
        $found = [];
        foreach (self::months($today) as $month) {
            foreach (array_chunk($remaining, self::IDS_PER_REQUEST) as $chunk) {
                $priced = $this->priced($chunk, $month, $today);
                if ($priced === null) {
                    return null;
                }
                $found += array_fill_keys($priced, true);
            }
            $remaining = array_values(array_filter($remaining, static fn (int $id): bool => !isset($found[$id])));
            if ($remaining === []) {
                break;
            }
        }

        return $found === [] ? null : array_map('intval', array_keys($found));
    }

    /**
     * Which of $circuitIds Sphinx prices on a departure in $month from
     * $today on: every page; null when a page could not be read.
     *
     * @param list<int> $circuitIds
     * @return list<int>|null
     */
    private function priced(array $circuitIds, string $month, string $today): ?array
    {
        $ids = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->api->getCircuitRates([
                'circuit_ids' => $circuitIds,
                'months' => [$month],
                'pagination' => ['page' => $page, 'per_page' => self::PER_PAGE],
            ]);
            if ($response === null) {
                return null;
            }
            $rates = TypeCoerce::toRowList($response['results'] ?? []);
            foreach ($rates as $rate) {
                $id = TypeCoerce::toInt($rate['circuit_id'] ?? 0);
                $date = TypeCoerce::toString($rate['departure_date'] ?? '');
                if ($id > 0 && ($date === '' || $date >= $today)) {
                    $ids[$id] = true;
                }
            }
            $lastPage = TypeCoerce::toInt(TypeCoerce::toStringMap($response['meta'] ?? null)['last_page'] ?? 0);
            if ($rates === [] || ($lastPage > 0 ? $page >= $lastPage : count($rates) < self::PER_PAGE)) {
                return array_keys($ids);
            }
        }

        return null;
    }

    /**
     * The months asked for: this one and the next MONTHS_AHEAD - 1, as Y-m.
     *
     * @return list<string>
     */
    public static function months(string $today): array
    {
        $first = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        $months = [];
        for ($i = 0; $i < self::MONTHS_AHEAD; $i++) {
            $months[] = $first->modify("+{$i} months")->format('Y-m');
        }

        return $months;
    }
}
