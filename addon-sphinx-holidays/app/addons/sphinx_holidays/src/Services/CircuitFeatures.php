<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Providers\SphinxCircuitProductProvider;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitFeatureFacts;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;
use Tygh\Addons\TravelCore\Services\CircuitFeatureAssigner;
use Tygh\Addons\TravelCore\Services\CircuitFeatureConfig;

/**
 * A Sphinx circuit's facts for its product's CS-Cart features (Feature
 * Mappings › Circuits): what Sphinx says, read from the stored circuit;
 * which feature each lands in is Travel Core's mapping.
 *
 *  - transport: transport_type as sent ("flight", "bus")
 *  - nights: duration.nights
 *  - countries: the destination ids, each rolled up to its country in the
 *    static destinations
 *  - months, departure cities: from the departures the sync saved
 *    (?:travel_circuit_departures, read from the rates), from today on
 *  - tags: the tag names
 *  - included, not included: the titles of what the price includes and does
 *    not (details_json, saved from the static list)
 *  - difficulty: details_json difficulty.level (1–5)
 *  - group size: details_json group_size.max (min when there is no max)
 *  - stars: the itinerary days' hotel categories and the trip's own hotel
 *    (details_json accommodation.category)
 */
final class CircuitFeatures
{
    /** Months counted from this one: a year, so a calendar month means one departure month. */
    private const int MONTHS_AHEAD = 12;

    /** A circuit with no departure saved from today on. */
    private const array NO_DEPARTURES = ['cities' => [], 'months' => []];

    private readonly CircuitRepository $circuits;

    /** @var array<string, int> "fact: reason" => how many circuits (every circuit applied by this object) */
    private array $reasons = [];

    /** @var list<string> "fact: reason" of the last circuit applied */
    private array $lastNotes = [];

    /** @var \Closure(list<string>|null): array<string, array{cities: list<array{id: string, name: string}>, months: list<string>}> circuit ids (null: all) => their saved departures' cities and months */
    private readonly \Closure $departures;

    /**
     * @param (\Closure(list<string>|null): array<string, array{cities: list<array{id: string, name: string}>, months: list<string>}>)|null $departures tests inject them; the default reads ?:travel_circuit_departures from today on
     */
    public function __construct(?CircuitRepository $circuits = null, private ?CircuitFeatureAssigner $assigner = null, ?\Closure $departures = null)
    {
        $this->circuits = $circuits ?? new CircuitRepository();
        $this->departures = $departures ?? static fn (?array $circuitIds): array => (new CircuitDepartureRepository())
            ->summaries(SphinxCircuitProductProvider::PROVIDER, date('Y-m-d'), $circuitIds === null ? null : TypeCoerce::toStringList($circuitIds));
    }

    /**
     * Writes the features of every active circuit that has a product.
     *
     * @return int how many products got at least one feature
     */
    public function applyAll(): int
    {
        if (CircuitFeatureConfig::isEmpty(CircuitFeatureConfig::load())) {
            return 0;
        }
        $written = 0;
        $departures = ($this->departures)(null);
        foreach ($this->circuits->findLinkedForFeatures() as $row) {
            $written += $this->applyRow($row, $departures[TypeCoerce::toString($row['circuit_id'] ?? '')] ?? self::NO_DEPARTURES) ? 1 : 0;
        }

        return $written;
    }

    /**
     * Writes one circuit's features (a product just made for it).
     */
    public function applyTo(int $circuitId): bool
    {
        if (CircuitFeatureConfig::isEmpty(CircuitFeatureConfig::load())) {
            return false;
        }

        $id = (string) $circuitId;

        return $this->applyRow($this->circuits->findForFeatures($circuitId), ($this->departures)([$id])[$id] ?? self::NO_DEPARTURES);
    }

    /**
     * @param array<string, mixed> $row a sphinx_circuits row (findLinkedForFeatures())
     * @param array{cities: list<array{id: string, name: string}>, months: list<string>} $departures its saved departures (CircuitDepartureRepository::summaries())
     */
    private function applyRow(array $row, array $departures): bool
    {
        $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
        if ($productId <= 0) {
            return false;
        }
        $destinationIds = TypeCoerce::toIntList(json_decode(TypeCoerce::toString($row['destination_ids'] ?? ''), true) ?: []);
        $facts = self::facts($row, $this->circuits->countryNames($destinationIds), date('Y-m'), $departures);
        $this->assigner ??= new CircuitFeatureAssigner();

        $written = $this->assigner->assign($productId, $facts);
        $this->lastNotes = [];
        foreach ($this->assigner->report() as $field => $result) {
            if ($result !== CircuitFeatureAssigner::REPORT_WRITTEN) {
                $note = $field . ': ' . $result;
                $this->lastNotes[] = $note;
                $this->reasons[$note] = ($this->reasons[$note] ?? 0) + 1;
            }
        }

        return $written > 0;
    }

    /**
     * Why mapped features were not written, over every circuit applied by
     * this object: "countries: no data from the provider (89 circuits)".
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $lines = [];
        foreach ($this->reasons as $note => $count) {
            $lines[] = $note . ' (' . $count . ($count === 1 ? ' circuit)' : ' circuits)');
        }

        return $lines;
    }

    /**
     * Why the last circuit applied (applyTo) lacks some mapped features.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->lastNotes;
    }

    /**
     * @param array<string, mixed> $row a sphinx_circuits row
     * @param list<string> $countries its destinations' countries (CircuitRepository::countryNames())
     * @param string $thisMonth Y-m
     * @param array{cities: list<array{id: string, name: string}>, months: list<string>} $departures its saved departures (CircuitDepartureRepository::summaries())
     */
    public static function facts(array $row, array $countries, string $thisMonth, array $departures = self::NO_DEPARTURES): CircuitFeatureFacts
    {
        $tags = [];
        foreach (TypeCoerce::toRowList(json_decode(TypeCoerce::toString($row['tags_json'] ?? ''), true) ?: []) as $tag) {
            $name = trim(TypeCoerce::toString($tag['name'] ?? ''));
            if ($name !== '') {
                $tags[] = $name;
            }
        }

        $details = json_decode(TypeCoerce::toString($row['details_json'] ?? ''), true);
        $details = is_array($details) ? $details : [];

        return new CircuitFeatureFacts(
            transport: strtolower(trim(TypeCoerce::toString($row['transport_type'] ?? ''))),
            nights: TypeCoerce::toInt($row['duration_nights'] ?? 0),
            countries: $countries,
            months: self::months($departures['months'], $thisMonth),
            departureCities: array_values(array_unique(array_filter(array_column($departures['cities'], 'name'), static fn (string $name): bool => $name !== ''))),
            tags: array_values(array_unique($tags)),
            included: self::titles($details['included'] ?? []),
            notIncluded: self::titles($details['not_included'] ?? []),
            difficulty: self::difficulty($details['difficulty'] ?? null),
            groupSize: self::groupSize($details['group_size'] ?? null),
            stars: self::stars($row['itinerary_json'] ?? null, $details['accommodation'] ?? null),
        );
    }

    /** details_json difficulty.level, 1 to 5; 0 for anything else. */
    private static function difficulty(mixed $difficulty): int
    {
        $level = TypeCoerce::toInt(TypeCoerce::toStringMap($difficulty)['level'] ?? 0);

        return $level >= 1 && $level <= 5 ? $level : 0;
    }

    /** details_json group_size: its max, else its min; 0 when it gives neither. */
    private static function groupSize(mixed $group): int
    {
        $group = TypeCoerce::toStringMap($group);
        $max = TypeCoerce::toInt($group['max'] ?? 0);

        return $max > 0 ? $max : max(0, TypeCoerce::toInt($group['min'] ?? 0));
    }

    /**
     * The stars of the circuit's hotels: those on the route and the trip's
     * own hotel, each once, lowest first.
     *
     * @return list<int>
     */
    private static function stars(mixed $itinerary, mixed $accommodation): array
    {
        $stars = SphinxCircuitProductProvider::routeStars($itinerary);
        $own = SphinxCircuitProductProvider::stars(TypeCoerce::toString(TypeCoerce::toStringMap($accommodation)['category'] ?? ''));
        if ($own > 0) {
            $stars[] = $own;
        }
        $stars = array_values(array_unique($stars));
        sort($stars);

        return $stars;
    }

    /**
     * The titles of a details list ("included", "not_included"): each once,
     * the empty ones left out.
     *
     * @return list<string>
     */
    public static function titles(mixed $items): array
    {
        $titles = [];
        foreach (TypeCoerce::toRowList($items) as $item) {
            $title = trim(TypeCoerce::toString($item['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return array_values(array_unique($titles));
    }

    /**
     * The calendar months (1–12) of the stored departure months, from this
     * month on and a year ahead: past months are left out, and a month a
     * year away is not taken for this year's.
     *
     * @param list<string> $stored Y-m
     * @param string $thisMonth Y-m
     *
     * @return list<int>
     */
    public static function months(array $stored, string $thisMonth): array
    {
        $last = (new \DateTimeImmutable($thisMonth . '-01'))->modify('+' . (self::MONTHS_AHEAD - 1) . ' months')->format('Y-m');
        $months = [];
        foreach ($stored as $month) {
            if (preg_match('/^\d{4}-(\d{2})$/', $month, $m) === 1 && $month >= $thisMonth && $month <= $last) {
                $months[] = (int) $m[1];
            }
        }
        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }
}
