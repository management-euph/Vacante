<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Services\CatalogPrice;

/** The catalog price is the indicative nightly price, at every supplier; 0 when there is none. */
#[CoversClass(CatalogPrice::class)]
final class CatalogPriceTest extends TestCase
{
    /** @var list<array{int, float}> */
    private array $written = [];

    /** @param array<string, float|null> $prices "supplier:hotel" => nightly price */
    private function catalog(array $prices, ?int $productId = 40): CatalogPrice
    {
        return new CatalogPrice(
            static fn (string $provider, string $hotelId): ?float => $prices[$provider . ':' . $hotelId] ?? null,
            static fn (string $provider, string $hotelId): ?int => $productId,
            function (int $id, float $price): void {
                $this->written[] = [$id, $price];
            },
        );
    }

    public function testTheProductGetsTheIndicativeNightlyPrice(): void
    {
        $catalog = $this->catalog(['eurosite:RO0440' => 144.82857142]);

        self::assertSame(144.83, $catalog->syncHotel('eurosite', 'RO0440'));
        self::assertSame([[40, 144.83]], $this->written);
    }

    public function testNoPriceWritesZero(): void
    {
        self::assertSame(0.0, $this->catalog(['sphinx:1' => 0.0])->syncHotel('sphinx', '1'));
        self::assertSame(0.0, $this->catalog([])->syncHotel('novoton', '2'));
        self::assertSame([[40, 0.0], [40, 0.0]], $this->written);
    }

    public function testAHotelWithoutAProductIsLeftAlone(): void
    {
        self::assertNull($this->catalog(['novoton:7' => 80.0], null)->syncHotel('novoton', '7'));
        self::assertNull($this->catalog(['novoton:7' => 80.0], 0)->syncHotel('novoton', '7'));
        self::assertNull($this->catalog(['novoton:7' => 80.0])->syncHotel('novoton', ''));
        self::assertSame([], $this->written);
    }

    public function testForHotelRoundsToTheCent(): void
    {
        self::assertSame(64.4, $this->catalog(['novoton:1' => 64.4049])->forHotel('novoton', '1'));
        self::assertSame(0.0, $this->catalog([])->forHotel('novoton', '1'));
    }

    public function testSyncAllRepricesEveryHotelProductInBatches(): void
    {
        // 1 001 products: three batches of 500; every third one is a hotel.
        $pages = [];
        $catalog = new CatalogPrice(
            static fn (string $provider, string $hotelId): ?float => $hotelId === 'h3' ? null : 70.0,
            null,
            function (int $id, float $price): void {
                $this->written[] = [$id, $price];
            },
            static fn (int $productId, string $code): ?HotelSeoData => $productId % 3 === 0
                ? new HotelSeoData(hotelId: 'h' . $productId, providerName: 'sphinx', name: 'x')
                : null,
            static function (int $after, int $limit) use (&$pages): array {
                $pages[] = $after;
                $rows = [];
                for ($id = $after + 1; $id <= min(1001, $after + $limit); $id++) {
                    $rows[] = ['product_id' => (string) $id, 'product_code' => 'C' . $id];
                }

                return $rows;
            },
        );

        $stats = $catalog->syncAll();
        self::assertSame(333, $stats['products']);
        self::assertSame(332, $stats['priced']);
        self::assertSame(1, $stats['unpriced']);
        self::assertSame(['sphinx' => ['products' => 333, 'priced' => 332, 'unpriced' => 1, 'unpriced_codes' => ['C3']]], $stats['by_provider']);
        self::assertSame([0, 500, 1000], $pages);
        self::assertSame([3, 0.0], $this->written[0]);
        self::assertSame([6, 70.0], $this->written[1]);
        self::assertCount(333, $this->written);
    }

    public function testTheReportNamesEachSupplier(): void
    {
        $stats = ['products' => 14, 'priced' => 2, 'unpriced' => 12, 'by_provider' => [
            'eurosite' => ['products' => 12, 'priced' => 0, 'unpriced' => 12, 'unpriced_codes' => ['EUS-LA-RO1', 'EUS-LA-RO2', 'EUS-LA-RO3', 'EUS-LA-RO4', 'EUS-LA-RO5', 'EUS-LA-RO6', 'EUS-LA-RO7', 'EUS-LA-RO8', 'EUS-LA-RO9', 'EUS-LA-RO10']],
            'novoton' => ['products' => 2, 'priced' => 2, 'unpriced' => 0, 'unpriced_codes' => []],
        ]];
        $labels = ['eurosite' => 'Eurosite', 'novoton' => 'Novoton Holidays'];

        self::assertSame(
            "Catalog prices: 14 hotel product(s), 2 with a price, 12 without\n"
            . "  Eurosite (eurosite): 12 hotel product(s), 0 with a price, 12 without — without a price: EUS-LA-RO1, EUS-LA-RO2, EUS-LA-RO3, EUS-LA-RO4, EUS-LA-RO5, EUS-LA-RO6, EUS-LA-RO7, EUS-LA-RO8, EUS-LA-RO9, EUS-LA-RO10 and 2 more\n"
            . '  Novoton Holidays (novoton): 2 hotel product(s), 2 with a price, 0 without',
            CatalogPrice::report($stats, static fn (string $p): string => $labels[$p]),
        );
        self::assertStringContainsString('no supplier owns any product', CatalogPrice::report(['products' => 0, 'priced' => 0, 'unpriced' => 0, 'by_provider' => []], static fn (string $p): string => $p));
    }
}
