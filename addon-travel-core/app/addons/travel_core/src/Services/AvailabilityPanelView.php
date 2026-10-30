<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The availability panel as the hotel page draws it
 * (components/availability_panel.tpl, above the search on both hotel page
 * templates): AvailabilityPanel's wording with its dates and months written
 * out in the visitor's language, and the similar hotels with what their
 * cards show (photo, stars, the indicative "from" price per night).
 *
 * Every figure comes from data the store already keeps: no supplier call.
 */
final class AvailabilityPanelView
{
    /** @var \Closure(int): array<string, mixed> */
    private readonly \Closure $mainPair;

    /** @var \Closure(string, string): string */
    private readonly \Closure $fromPrice;

    /** @var \Closure(string): string */
    private readonly \Closure $formatDate;

    /** @var \Closure(int): string */
    private readonly \Closure $productName;

    /**
     * @param string $lang the visitor's language (month names)
     * @param (\Closure(int): array<string, mixed>)|null $mainPair product id => its main image pair ([] when none)
     * @param (\Closure(string, string): string)|null $fromPrice (supplier, hotel id) => "85 €", '' when unknown
     * @param (\Closure(string): string)|null $formatDate Y-m-d => the store's date format
     * @param (\Closure(int): string)|null $productName product id => its name on the store ('' when none)
     */
    public function __construct(
        private readonly AvailabilityPanel $panels = new AvailabilityPanel(),
        private readonly string $lang = 'en',
        ?\Closure $mainPair = null,
        ?\Closure $fromPrice = null,
        ?\Closure $formatDate = null,
        ?\Closure $productName = null,
    ) {
        $this->mainPair = $mainPair ?? static fn (int $productId): array => TypeCoerce::toStringMap(fn_get_image_pairs($productId, 'product', 'M', true, true));
        $this->fromPrice = $fromPrice ?? static function (string $provider, string $hotelId): string {
            $nightly = TravelProviderRegistry::indicativeNightlyPrice($provider, $hotelId);

            return $nightly !== null ? MoneyFormatter::forStore(true)->format($nightly) : '';
        };
        $this->formatDate = $formatDate ?? static fn (string $day): string => DateHelper::formatStoreDate($day);
        $this->productName = $productName ?? static fn (int $productId): string => TypeCoerce::toString(fn_get_product_name($productId));
    }

    /**
     * The panel for the hotel page, or null when the product gets none.
     *
     * @return array{product_id: int, state: string, message: string, date: string, season_from: string, season_to: string, notify: string, privacy_url: string, similar: list<array{product_id: int, name: string, stars: int, price: string, image: array<string, mixed>}>}|null
     */
    public function forProduct(int $productId, \DateTimeImmutable $today): ?array
    {
        $panel = $this->panels->forProduct($productId, $today);
        if ($panel === null) {
            return null;
        }
        $season = $panel['last_season'];
        $from = $season !== null ? $this->month($season['from']) : '';
        $to = $season !== null && $season['to'] !== $season['from'] ? $this->month($season['to']) : '';

        $similar = [];
        foreach ($panel['similar'] as $hotel) {
            // The name customers know from the store; the supplier's spelling
            // only when the product has none.
            $name = trim(($this->productName)($hotel['product_id']));
            $similar[] = [
                'product_id' => $hotel['product_id'],
                'name' => $name !== '' ? $name : $hotel['hotel_name'],
                'stars' => max(0, min(5, $hotel['stars'] ?? 0)),
                'price' => ($this->fromPrice)($panel['provider'], $hotel['hotel_id']),
                'image' => ($this->mainPair)($hotel['product_id']),
            ];
        }

        return [
            'product_id' => $productId,
            'state' => $panel['state'],
            'message' => $panel['message'],
            'date' => $panel['published_from'] !== '' ? ($this->formatDate)($panel['published_from']) : '',
            'season_from' => $from,
            'season_to' => $to,
            'notify' => $panel['notify'],
            'privacy_url' => $panel['notify'] !== '' ? TravelCoreConfig::availabilityPrivacyUrl() : '',
            'similar' => $similar,
        ];
    }

    /** "2026-06" => "June" / "iunie". */
    private function month(string $yearMonth): string
    {
        return DateHelper::monthName(TypeCoerce::toInt(substr($yearMonth, 5, 2)), $this->lang);
    }
}
