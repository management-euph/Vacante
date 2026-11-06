<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Services\HotelLocationLine;
use Tygh\Addons\TravelCore\ViewModels\ListCardFactory;

/**
 * Board 15: one list card for every supplier, from stored data only — the
 * stars, the place without the street, the indicative nightly price; a
 * product no supplier owns gets no card.
 */
#[CoversClass(ListCardFactory::class)]
#[CoversClass(HotelLocationLine::class)]
final class ListCardFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $priced = [];

    /** @param array<int, HotelSeoData> $hotels product id => owner */
    private function factory(array $hotels, ?float $nightly = 64.4): ListCardFactory
    {
        return new ListCardFactory(
            static fn (int $productId, string $code): ?HotelSeoData => $hotels[$productId] ?? null,
            function (string $provider, string $hotelId) use ($nightly): ?float {
                $this->priced[] = $provider . ':' . $hotelId;

                return $nightly;
            },
            static fn (float $price): string => number_format($price, 0) . ' €',
        );
    }

    public function testEachSupplierGetsTheSameCard(): void
    {
        $cards = $this->factory([
            11 => new HotelSeoData(hotelId: '4535', providerName: 'novoton', name: 'Albatros', classification: 4, city: 'Mamaia', region: 'Constanța', country: 'România', address: 'Bd. Mamaia 1'),
            12 => new HotelSeoData(hotelId: '3612', providerName: 'sphinx', name: 'Falez', classification: 5, city: 'Antalya City', region: 'Antalya', country: 'Turkey'),
            13 => new HotelSeoData(hotelId: 'RO0440', providerName: 'eurosite', name: 'ALTUS CM', classification: 3, propertyType: 'hotel', city: 'Praid', country: 'Romania'),
        ])->forProducts([
            ['product_id' => 11, 'product_code' => 'NVT4535'],
            ['product_id' => '12', 'product_code' => 'TR3612'],
            ['product_id' => 13, 'product_code' => 'LA-RO0440'],
        ]);

        self::assertSame([11, 12, 13], array_keys($cards));
        self::assertSame(['kind' => 'hotel', 'stars' => 4, 'place' => 'Mamaia, Constanța · România', 'from_price' => '64 €', 'cta' => 'travel_core.list_cta_hotel'], $cards[11]);
        self::assertSame('Antalya City, Antalya · Turkey', $cards[12]['place']);
        self::assertSame('Praid · Romania', $cards[13]['place']);
        self::assertSame(3, $cards[13]['stars']);
        // The price is asked of the owning supplier, by its own hotel id.
        self::assertSame(['novoton:4535', 'sphinx:3612', 'eurosite:RO0440'], $this->priced);
    }

    public function testNoPriceAndNoStarsStayEmpty(): void
    {
        $cards = $this->factory([5 => new HotelSeoData(hotelId: '1', providerName: 'novoton', name: 'Vila Mara', classification: 9)], null)
            ->forProducts([['product_id' => 5, 'product_code' => 'NVT1']]);

        self::assertSame('', $cards[5]['from_price']);
        self::assertSame(5, $cards[5]['stars']); // clamped to five
        self::assertSame('', $cards[5]['place']);
    }

    public function testProductsNoSupplierOwnsAndBadRowsGetNoCard(): void
    {
        $cards = $this->factory([7 => new HotelSeoData(hotelId: '7', providerName: 'sphinx', name: 'A')])->forProducts([
            ['product_id' => 3, 'product_code' => 'GIFT'],
            'not a row',
            ['product_id' => 0],
            ['product_id' => 7, 'product_code' => 'TR7'],
            ['product_id' => 7, 'product_code' => 'TR7'],
        ]);

        self::assertSame([7], array_keys($cards));
        self::assertCount(1, $this->priced);
    }

    public function testPlaceIsCasedDeduplicatedAndNeverShowsTheStreet(): void
    {
        self::assertSame('Golem · Albania', ListCardFactory::place(new HotelSeoData(hotelId: '1', providerName: 'novoton', name: 'x', city: 'GOLEM', region: 'golem', country: 'ALBANIA', address: 'Rruga e Plazhit')));
        self::assertSame('Baile Olanesti, Valcea', ListCardFactory::place(new HotelSeoData(hotelId: '2', providerName: 'eurosite', name: 'x', city: 'baile olanesti', region: 'Valcea')));
        self::assertSame('Bulgaria', ListCardFactory::place(new HotelSeoData(hotelId: '3', providerName: 'novoton', name: 'x', city: 'Bulgaria', country: 'Bulgaria')));
    }
}
