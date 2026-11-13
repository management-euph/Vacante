<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitFeatureFacts;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
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
 *  - months, departure cities: from the rates (the static list's departures
 *    are dates with no city, and keep past dates)
 *  - tags: the tag names
 *  - included, not included: the titles of what the price includes and does
 *    not (details_json, saved from the static list)
 */
final class CircuitFeatures
{
    /** Months counted from this one: a year, so a calendar month means one departure month. */
    private const int MONTHS_AHEAD = 12;

    private readonly CircuitRepository $circuits;

    public function __construct(?CircuitRepository $circuits = null, private ?CircuitFeatureAssigner $assigner = null)
    {
        $this->circuits = $circuits ?? new CircuitRepository();
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
        foreach ($this->circuits->findLinkedForFeatures() as $row) {
            $written += $this->applyRow($row) ? 1 : 0;
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

        return $this->applyRow($this->circuits->findForFeatures($circuitId));
    }

    /**
     * @param array<string, mixed> $row a sphinx_circuits row (findLinkedForFeatures())
     */
    private function applyRow(array $row): bool
    {
        $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
        if ($productId <= 0) {
            return false;
        }
        $destinationIds = TypeCoerce::toIntList(json_decode(TypeCoerce::toString($row['destination_ids'] ?? ''), true) ?: []);
        $facts = self::facts($row, $this->circuits->countryNames($destinationIds), date('Y-m'));
        $this->assigner ??= new CircuitFeatureAssigner();

        return $this->assigner->assign($productId, $facts) > 0;
    }

    /**
     * @param array<string, mixed> $row a sphinx_circuits row
     * @param list<string> $countries its destinations' countries (CircuitRepository::countryNames())
     * @param string $thisMonth Y-m
     */
    public static function facts(array $row, array $countries, string $thisMonth): CircuitFeatureFacts
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
            months: self::months(TypeCoerce::toStringList(json_decode(TypeCoerce::toString($row['departure_months'] ?? ''), true) ?: []), $thisMonth),
            departureCities: array_values(array_filter(array_map('trim', explode(',', TypeCoerce::toString($row['departure_names'] ?? ''))), static fn (string $name): bool => $name !== '')),
            tags: array_values(array_unique($tags)),
            included: self::titles($details['included'] ?? []),
            notIncluded: self::titles($details['not_included'] ?? []),
        );
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
