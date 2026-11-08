<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The circuits Sphinx still sells: those it prices (circuits/rates, the
 * endpoint the circuit bar reads) on a departure from today on, over the
 * next MONTHS_AHEAD months. The static circuits list keeps circuits whose
 * departures are all past ("… 2024 (charter avion)"); the circuits sync
 * saves only the ones named here (CircuitSyncService).
 *
 * ids() is null when the rates could not be read all through (an API
 * failure, too many pages) or priced no circuit at all: the sync then
 * keeps what it has, never drops every circuit on a bad answer.
 */
final class BookableCircuits
{
    public const int MONTHS_AHEAD = 18;

    /** The most the endpoint gives per page. */
    private const int PER_PAGE = 100;

    /** A stop for a runaway listing (100 rates a page). */
    private const int MAX_PAGES = 1000;

    public function __construct(private readonly SphinxApi $api)
    {
    }

    /**
     * @param string $today Y-m-d
     * @return list<int>|null the circuits with a rate from $today on; null when unknown
     */
    public function ids(string $today): ?array
    {
        $months = self::months($today);
        /** @var array<int, true> $ids */
        $ids = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->api->getCircuitRates([
                'months' => $months,
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
                return $ids === [] ? null : array_keys($ids);
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
