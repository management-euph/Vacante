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
 * Month by month, one request a month (every page): the endpoint ignores
 * circuit_ids (seen live: it answers every circuit's rates for the month
 * asked), so it is not sent. A circuit found in a month needs no later
 * one, and the search stops once every candidate is found (or the months
 * run out).
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

    /** A stop for a runaway listing, per request. */
    private const int MAX_PAGES = 200;

    /** @var array<int, array{months: array<string, true>, departures: array<int, string>}> what the rates read so far say of each circuit */
    private array $facts = [];

    public function __construct(private readonly SphinxApi $api)
    {
    }

    /**
     * What the rates read by ids() say of each priced circuit: the months
     * (Y-m) with a departure from today on, and the cities it leaves from
     * (the static list has neither: its departures are dates with no city).
     * Complete only after ids() ran with $everyMonth.
     *
     * @return array<int, array{months: list<string>, departures: array<int, string>}> by circuit id
     */
    public function facts(): array
    {
        $facts = [];
        foreach ($this->facts as $id => $fact) {
            $months = array_keys($fact['months']);
            sort($months);
            $facts[$id] = ['months' => $months, 'departures' => $fact['departures']];
        }

        return $facts;
    }

    /**
     * @param string $today Y-m-d
     * @param list<int> $candidates the circuits to check (from the static list and the store)
     * @param bool $everyMonth read every month even once all candidates are found: facts() then knows each circuit's every month and departure city
     * @return list<int>|null those with a rate from $today on; null when unknown
     */
    public function ids(string $today, array $candidates, bool $everyMonth = false): ?array
    {
        $this->facts = [];
        $remaining = array_values(array_unique(array_filter($candidates, static fn (int $id): bool => $id > 0)));
        if ($remaining === []) {
            return null;
        }
        /** @var array<int, true> $found */
        $found = [];
        foreach (self::months($today) as $month) {
            $priced = $this->priced($month, $today);
            if ($priced === null) {
                return null;
            }
            $found += array_fill_keys(array_values(array_intersect($priced, $remaining)), true);
            $remaining = array_values(array_filter($remaining, static fn (int $id): bool => !isset($found[$id])));
            if ($remaining === [] && !$everyMonth) {
                break;
            }
        }

        return $found === [] ? null : array_map('intval', array_keys($found));
    }

    /**
     * The circuits Sphinx prices on a departure in $month from $today on:
     * every page; null when a page could not be read.
     *
     * @return list<int>|null
     */
    private function priced(string $month, string $today): ?array
    {
        $ids = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->api->getCircuitRates([
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
                    $this->facts[$id] ??= ['months' => [], 'departures' => []];
                    $this->facts[$id]['months'][$date !== '' ? substr($date, 0, 7) : $month] = true;
                    foreach (TypeCoerce::toRowList($rate['departures'] ?? []) as $departure) {
                        $departureId = TypeCoerce::toInt($departure['id'] ?? 0);
                        $name = trim(TypeCoerce::toString($departure['name'] ?? ''));
                        if ($departureId > 0 && $name !== '') {
                            $this->facts[$id]['departures'][$departureId] = $name;
                        }
                    }
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
