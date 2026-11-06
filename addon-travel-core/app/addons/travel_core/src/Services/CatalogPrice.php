<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The CS-Cart catalog price of a hotel product (board 15, decision 5): the
 * indicative price the list card and the hotel page show without dates —
 * one night, 2 adults, the store's primary currency, commission included —
 * on the same basis at every supplier, so CS-Cart's own "sort by price" and
 * price filter compare like with like. Each supplier calls it where it used
 * to write its own figure (Novoton a night per person, Eurosite a 7-night
 * stay, Sphinx nothing).
 *
 * A hotel the supplier has no price for gets 0: the card then says the
 * price comes with the dates. A supplier's own cron only reprices the hotels
 * whose prices it refreshed; syncAll() reprices every hotel product (the
 * daily exchange-rates line, and the catalog_prices mode on demand), since
 * a new exchange rate moves the prices too.
 */
final class CatalogPrice
{
    /** @var \Closure(string, string): ?float */
    private readonly \Closure $nightly;

    /** @var \Closure(string, string): ?int */
    private readonly \Closure $productId;

    /** @var \Closure(int, float): void */
    private readonly \Closure $write;

    /** @var \Closure(int, string): ?HotelSeoData */
    private readonly \Closure $owner;

    /** @var \Closure(int, int): list<array<string, mixed>> */
    private readonly \Closure $products;

    /** Its cron mode, recorded under 'travel_core'. */
    public const string JOB = 'catalog_prices';

    /** Product codes listed per supplier for the hotels left without a price. */
    private const int LISTED_UNPRICED = 10;

    /** Products read at a time by syncAll(). */
    private const int BATCH = 500;

    /**
     * @param (\Closure(string, string): ?float)|null $nightly supplier + hotel id => the indicative nightly price
     * @param (\Closure(string, string): ?int)|null $productId supplier + hotel id => its product id
     * @param (\Closure(int, float): void)|null $write product id + price => the base price row written
     * @param (\Closure(int, string): ?HotelSeoData)|null $owner product id + code => the supplier's hotel, or null
     * @param (\Closure(int, int): list<array<string, mixed>>)|null $products after product id + limit => the next product rows (product_id, product_code)
     */
    public function __construct(
        ?\Closure $nightly = null,
        ?\Closure $productId = null,
        ?\Closure $write = null,
        ?\Closure $owner = null,
        ?\Closure $products = null,
    ) {
        $this->nightly = $nightly ?? static fn (string $provider, string $hotelId): ?float => TravelProviderRegistry::indicativeNightlyPrice($provider, $hotelId);
        $this->productId = $productId ?? static fn (string $provider, string $hotelId): ?int => TravelProviderRegistry::getHotelProductProvider($provider)?->productIdForHotelId($hotelId);
        $this->write = $write ?? static function (int $productId, float $price): void {
            self::writeBase($productId, $price);
        };
        $this->owner = $owner ?? static fn (int $productId, string $productCode): ?HotelSeoData => TravelProviderRegistry::resolveProductOwner($productId, $productCode);
        $this->products = $products ?? static fn (int $after, int $limit): array => TypeCoerce::toRowList(db_get_array(
            'SELECT product_id, product_code FROM ?:products WHERE product_id > ?i ORDER BY product_id LIMIT ?i',
            $after,
            $limit,
        ));
    }

    /** The catalog price of a supplier's hotel, to the cent; 0.0 when the supplier has none. */
    public function forHotel(string $provider, string $hotelId): float
    {
        $nightly = $hotelId !== '' ? ($this->nightly)($provider, $hotelId) : null;

        return $nightly !== null && $nightly > 0 ? round($nightly, 2) : 0.0;
    }

    /**
     * Write it to the hotel's product.
     *
     * @param string $provider the supplier ('novoton', 'sphinx', 'eurosite')
     * @param string $hotelId the supplier's own hotel id
     *
     * @return float|null the price written, or null when the hotel has no product
     */
    public function syncHotel(string $provider, string $hotelId): ?float
    {
        $productId = $hotelId !== '' ? TypeCoerce::toInt(($this->productId)($provider, $hotelId)) : 0;
        if ($productId <= 0) {
            return null;
        }
        $price = $this->forHotel($provider, $hotelId);
        ($this->write)($productId, $price);

        return $price;
    }

    /**
     * Reprice every product a supplier owns; products no supplier owns are
     * left alone. The totals come per supplier too, with the first product
     * codes left without a price, so the run says where they are.
     *
     * @return array{products: int, priced: int, unpriced: int, by_provider: array<string, array{products: int, priced: int, unpriced: int, unpriced_codes: list<string>}>}
     */
    public function syncAll(): array
    {
        $stats = ['products' => 0, 'priced' => 0, 'unpriced' => 0, 'by_provider' => []];
        $after = 0;
        do {
            $rows = ($this->products)($after, self::BATCH);
            foreach ($rows as $row) {
                $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
                $after = max($after, $productId);
                $productCode = TypeCoerce::toString($row['product_code'] ?? '');
                $hotel = $productId > 0 ? ($this->owner)($productId, $productCode) : null;
                if ($hotel === null) {
                    continue;
                }
                $price = $this->forHotel($hotel->providerName, $hotel->hotelId);
                ($this->write)($productId, $price);
                $kind = $price > 0 ? 'priced' : 'unpriced';
                $stats['products']++;
                $stats[$kind]++;
                $provider = $stats['by_provider'][$hotel->providerName] ?? ['products' => 0, 'priced' => 0, 'unpriced' => 0, 'unpriced_codes' => []];
                $provider['products']++;
                $provider[$kind]++;
                if ($kind === 'unpriced' && count($provider['unpriced_codes']) < self::LISTED_UNPRICED) {
                    $provider['unpriced_codes'][] = $productCode !== '' ? $productCode : '#' . $productId;
                }
                $stats['by_provider'][$hotel->providerName] = $provider;
            }
        } while (count($rows) === self::BATCH);
        ksort($stats['by_provider']);

        return $stats;
    }

    /**
     * The daily exchange-rates line's step (and the catalog_prices mode):
     * reprice every hotel product, recorded for Travel Core -> Tools. The
     * report names each supplier (its add-on's label) with its own totals.
     */
    public static function runDaily(): string
    {
        $result = \Tygh\Addons\TravelCore\Cron\CronRunLog::record(
            'travel_core',
            self::JOB,
            static fn (): array => ['success' => true] + (new self())->syncAll(),
        );

        return self::report($result, static fn (string $provider): string => TypeCoerce::toString(TravelProviderRegistry::get($provider)['label'] ?? '') ?: $provider);
    }

    /**
     * The run's report: the totals, then one line per supplier.
     *
     * @param array{products: int, priced: int, unpriced: int, by_provider: array<string, array{products: int, priced: int, unpriced: int, unpriced_codes: list<string>}>} $stats
     * @param \Closure(string): string $label supplier => its name in the report
     *
     * @return string the lines, newline-separated
     */
    public static function report(array $stats, \Closure $label): string
    {
        $lines = [sprintf(
            'Catalog prices: %d hotel product(s), %d with a price, %d without',
            $stats['products'],
            $stats['priced'],
            $stats['unpriced'],
        )];
        foreach ($stats['by_provider'] as $provider => $row) {
            $line = sprintf(
                '  %s (%s): %d hotel product(s), %d with a price, %d without',
                $label($provider),
                $provider,
                $row['products'],
                $row['priced'],
                $row['unpriced'],
            );
            if ($row['unpriced_codes'] !== []) {
                $more = $row['unpriced'] - count($row['unpriced_codes']);
                $line .= ' — without a price: ' . implode(', ', $row['unpriced_codes']) . ($more > 0 ? sprintf(' and %d more', $more) : '');
            }
            $lines[] = $line;
        }
        if ($stats['by_provider'] === []) {
            $lines[] = '  no supplier owns any product (are the supplier add-ons active?)';
        }

        return implode("\n", $lines);
    }

    /**
     * A product's base price row in ?:product_prices (quantity 1, all
     * customer groups), created when the product has none. CS-Cart 4 keeps
     * prices there: ?:products has no price column.
     */
    public static function writeBase(int $productId, float $price): void
    {
        if ($productId <= 0) {
            return;
        }
        db_query(
            'INSERT INTO ?:product_prices (product_id, price, lower_limit, usergroup_id)
             VALUES (?i, ?d, 1, 0)
             ON DUPLICATE KEY UPDATE price = VALUES(price)',
            $productId,
            $price,
        );
    }
}
