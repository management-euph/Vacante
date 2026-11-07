<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * The package results listed packages whose hotel the store does not sell:
 * the guest filled in every traveller, and add-to-cart then refused the
 * package ("Package product not found"). Only the packages whose hotel has
 * a buyable product (resolveProductId()'s rule) are listed now.
 */
final class CartServiceSellablePackagesTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param list<array<string, mixed>> $rows what ?:sphinx_hotels joined to ?:products answers
     */
    private function store(array $rows): CartService
    {
        DbStub::$getArray = function (string $query, mixed ...$params) use ($rows): array {
            $this->queries[] = [$query, $params];

            return $rows;
        };

        return new CartService();
    }

    /** @return array<string, mixed> */
    private static function package(int|string $hotelId, string $offerId): array
    {
        return ['offer_id' => $offerId, 'hotel' => ['id' => $hotelId, 'name' => 'Hotel ' . $hotelId]];
    }

    public function testOnlyPackagesOfHotelsTheStoreSellsAreListed(): void
    {
        $store = $this->store([
            ['hotel_id' => '101', 'status' => 'A', 'gate_hidden' => '0'],
            ['hotel_id' => '102', 'status' => 'H', 'gate_hidden' => '1'],
            ['hotel_id' => '103', 'status' => 'H', 'gate_hidden' => '0'],
            ['hotel_id' => '105', 'status' => 'D', 'gate_hidden' => '0'],
        ]);

        $listed = $store->sellablePackages([
            self::package(101, 'p1'),
            self::package(103, 'p2'),
            self::package(102, 'p3'),
            self::package(104, 'p4'),
            self::package(105, 'p5'),
            ['offer_id' => 'p6'],
            self::package(101, 'p7'),
        ]);

        self::assertSame(['p1', 'p3', 'p7'], array_column($listed, 'offer_id'), 'active, or hidden by the availability gate; in the order Sphinx gave them');
        self::assertCount(1, $this->queries, 'one query for every result');
        self::assertSame([['101', '103', '102', '104', '105']], $this->queries[0][1], 'each hotel asked once; no empty id');
        self::assertStringContainsString('INNER JOIN ?:products p ON p.product_id = h.product_id', $this->queries[0][0]);
    }

    public function testNoHotelNoQuery(): void
    {
        $store = $this->store([]);

        self::assertSame([], $store->sellablePackages([]));
        self::assertSame([], $store->sellablePackages([['offer_id' => 'p1', 'hotel' => []]]));
        self::assertSame([], $this->queries);
    }

    public function testAPackagesHotelIdIsReadAsTheOfferNamesIt(): void
    {
        self::assertSame('123', CartService::packageHotelId(['hotel' => ['id' => 123]]));
        self::assertSame('', CartService::packageHotelId(['hotel' => 'Hotel Name']));
        self::assertSame('', CartService::packageHotelId([]));
    }
}
