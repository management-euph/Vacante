<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A circuit's departure dates from one city, each with its price: what the
 * circuit bar shows under "Data plecării" (board 16.3).
 *
 * One circuits/rates call covers the next twelve months (the bar moves
 * between them without asking again), filtered to the circuit and the
 * departure point. Rates are priced for 2 adults (the doc's default): the
 * price for the customer's group comes from the quote, the next step. The
 * store's commission is added here, once. The answer is kept for this
 * visitor a quarter of an hour.
 */
final class CircuitDepartureDates
{
    public const string SESSION_KEY = 'sphinx_circuit_dates';

    /** The months one answer covers, starting with the current one. */
    public const int MONTHS = 12;

    /** How long a visitor's answer is reused, in seconds. */
    public const int TTL = 900;

    /** Rates pages read at most (100 departures each). */
    private const int MAX_PAGES = 5;

    /** At most this many answers kept per visitor (oldest dropped first). */
    private const int MAX_KEPT = 10;

    /**
     * @param SphinxApi $api the API
     * @param \Closure(float): float $commission the store's selling price for a supplier price
     * @param SessionAccessor $session where a visitor's answers are kept
     */
    public function __construct(
        private readonly SphinxApi $api,
        private readonly \Closure $commission,
        private readonly SessionAccessor $session = new SessionAccessor(),
    ) {
    }

    /**
     * @param int $circuitId the circuit
     * @param int $departureId the departure point (0: any)
     * @param \DateTimeImmutable $today the current date
     *
     * @return array{departures: list<array{date: string, price: float, currency: string}>, error: bool} by date; error when Sphinx gave no answer
     */
    public function forDeparture(int $circuitId, int $departureId, \DateTimeImmutable $today): array
    {
        $key = $circuitId . ':' . $departureId . ':' . $today->format('Y-m-d');
        $kept = TypeCoerce::toStringMap($this->session->get(self::SESSION_KEY));
        $entry = TypeCoerce::toStringMap($kept[$key] ?? null);
        if ($entry !== [] && time() - TypeCoerce::toInt($entry['at'] ?? 0) <= self::TTL) {
            return ['departures' => self::departureList($entry['departures'] ?? []), 'error' => false];
        }

        $rows = $this->rates($circuitId, $departureId, $today);
        if ($rows === null) {
            return ['departures' => [], 'error' => true];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $date = TypeCoerce::toString($row['departure_date'] ?? '');
            $pricing = TypeCoerce::toStringMap($row['pricing'] ?? null);
            $price = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
            if (TypeCoerce::toInt($row['circuit_id'] ?? 0) !== $circuitId
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
                || $date < $today->format('Y-m-d')
                || $price <= 0) {
                continue;
            }
            // The same date twice (another transport or offer): its lowest price.
            if (!isset($byDate[$date]) || $price < $byDate[$date]['supplier']) {
                $byDate[$date] = ['supplier' => $price, 'currency' => TypeCoerce::toString($pricing['currency'] ?? '')];
            }
        }
        ksort($byDate);

        $departures = [];
        foreach ($byDate as $date => $offer) {
            $departures[] = [
                'date' => (string) $date,
                'price' => round(($this->commission)($offer['supplier']), 2),
                'currency' => $offer['currency'],
            ];
        }

        $kept[$key] = ['at' => time(), 'departures' => $departures];
        $this->session->set(self::SESSION_KEY, array_slice($kept, -self::MAX_KEPT, null, true));

        return ['departures' => $departures, 'error' => false];
    }

    /**
     * The circuit's rates for the coming months, every page; null when
     * Sphinx gave no answer to the first.
     *
     * @param int $circuitId the circuit
     * @param int $departureId the departure point (0: any)
     * @param \DateTimeImmutable $today the current date
     *
     * @return list<array<string, mixed>>|null
     */
    private function rates(int $circuitId, int $departureId, \DateTimeImmutable $today): ?array
    {
        $months = [];
        $first = $today->modify('first day of this month');
        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[] = $first->modify('+' . $i . ' months')->format('Y-m');
        }
        $params = ['circuit_ids' => [$circuitId], 'months' => $months];
        if ($departureId > 0) {
            $params['departures'] = [$departureId];
        }

        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->api->getCircuitRates($params + ['pagination' => ['page' => $page, 'per_page' => 100]]);
            if ($response === null) {
                return $page === 1 ? null : $rows;
            }
            $rows = array_merge($rows, TypeCoerce::toRowList($response['results'] ?? []));
            $meta = TypeCoerce::toStringMap($response['meta'] ?? null);
            if ($page >= TypeCoerce::toInt($meta['last_page'] ?? 1)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param mixed $value a kept answer's departures
     *
     * @return list<array{date: string, price: float, currency: string}>
     */
    private static function departureList(mixed $value): array
    {
        $out = [];
        foreach (TypeCoerce::toRowList($value) as $row) {
            $out[] = [
                'date' => TypeCoerce::toString($row['date'] ?? ''),
                'price' => TypeCoerce::toFloat($row['price'] ?? 0),
                'currency' => TypeCoerce::toString($row['currency'] ?? ''),
            ];
        }

        return $out;
    }
}
