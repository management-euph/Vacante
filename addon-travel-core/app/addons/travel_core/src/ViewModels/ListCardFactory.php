<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\HotelLocationLine;
use Tygh\Addons\TravelCore\Services\ListCardFacts;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * The hotel list card (board 15: one card for every supplier) for the
 * products of one list page — a category, or the store's product search —
 * from what the store already keeps: no supplier call.
 *
 * Per product: the stars, the place ("Mamaia, Constanța · România"), the
 * meal plans and three facilities from its CS-Cart features
 * (Services\ListCardFacts, one query for the page), and the indicative
 * "from" price — the one the hotel page shows without dates: one
 * night for two adults, the store's currency, commission included. The card
 * never names the supplier. A product no supplier owns gets no card (the
 * list draws it as CS-Cart does).
 */
final class ListCardFactory
{
    public const string KIND_HOTEL = 'hotel';

    /** Language key of each kind's button: hotels show availability, offers (packages, circuits) the offer. */
    private const array CTA = [
        self::KIND_HOTEL => 'travel_core.list_cta_hotel',
    ];

    /** @var \Closure(int, string): ?HotelSeoData */
    private readonly \Closure $owner;

    /** @var \Closure(string, string): ?float */
    private readonly \Closure $nightly;

    /** @var \Closure(float): string */
    private readonly \Closure $money;

    /** @var \Closure(list<int>): array<int, array{meals: string, highlights: list<string>}> */
    private readonly \Closure $facts;

    /**
     * @param (\Closure(int, string): ?HotelSeoData)|null $owner product id + code => the supplier's hotel, or null
     * @param (\Closure(string, string): ?float)|null $nightly supplier + hotel id => the indicative nightly price, or null
     * @param (\Closure(float): string)|null $money price => "64 €" in the shopper's currency, whole units
     * @param (\Closure(list<int>): array<int, array{meals: string, highlights: list<string>}>)|null $facts hotel product ids => their meals and facilities
     */
    public function __construct(?\Closure $owner = null, ?\Closure $nightly = null, ?\Closure $money = null, ?\Closure $facts = null)
    {
        $this->owner = $owner ?? static fn (int $productId, string $productCode): ?HotelSeoData => TravelProviderRegistry::resolveProductOwner($productId, $productCode);
        $this->nightly = $nightly ?? static fn (string $provider, string $hotelId): ?float => TravelProviderRegistry::indicativeNightlyPrice($provider, $hotelId);
        $this->money = $money ?? static fn (float $price): string => MoneyFormatter::forStore(true)->format($price);
        $this->facts = $facts ?? static fn (array $productIds): array => (new ListCardFacts())->forProducts(array_values(array_map(TypeCoerce::toInt(...), $productIds)));
    }

    /**
     * @param iterable<mixed> $products the page's CS-Cart product rows (product_id, product_code)
     *
     * @return array<int, array{kind: string, stars: int, place: string, meals: string, highlights: list<string>, from_price: string, cta: string}> product id => card
     */
    public function forProducts(iterable $products): array
    {
        $hotels = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productId = TypeCoerce::toInt($product['product_id'] ?? 0);
            if ($productId <= 0 || array_key_exists($productId, $hotels)) {
                continue;
            }
            $hotels[$productId] = ($this->owner)($productId, TypeCoerce::toString($product['product_code'] ?? ''));
        }
        $hotels = array_filter($hotels);
        $facts = $hotels !== [] ? ($this->facts)(array_keys($hotels)) : [];

        $cards = [];
        foreach ($hotels as $productId => $hotel) {
            $cards[$productId] = $this->card($hotel, $facts[$productId] ?? ['meals' => '', 'highlights' => []]);
        }

        return $cards;
    }

    /**
     * @param HotelSeoData $hotel the supplier's hotel
     * @param array{meals: string, highlights: list<string>} $facts its meals and facilities
     *
     * @return array{kind: string, stars: int, place: string, meals: string, highlights: list<string>, from_price: string, cta: string}
     */
    private function card(HotelSeoData $hotel, array $facts): array
    {
        $nightly = ($this->nightly)($hotel->providerName, $hotel->hotelId);

        return [
            'kind' => self::KIND_HOTEL,
            'stars' => max(0, min(5, $hotel->classification ?? 0)),
            'place' => self::place($hotel),
            'meals' => $facts['meals'],
            'highlights' => $facts['highlights'],
            'from_price' => $nightly !== null && $nightly > 0 ? ($this->money)($nightly) : '',
            'cta' => self::CTA[self::KIND_HOTEL],
        ];
    }

    /** "Mamaia, Constanța · România"; "România" alone; '' when the supplier gives no place. */
    public static function place(HotelSeoData $hotel): string
    {
        $destination = HotelLocationLine::destination($hotel);
        $places = implode(', ', $destination['places']);
        if ($destination['country'] === '') {
            return $places;
        }

        return $places !== '' ? $places . ' · ' . $destination['country'] : $destination['country'];
    }
}
