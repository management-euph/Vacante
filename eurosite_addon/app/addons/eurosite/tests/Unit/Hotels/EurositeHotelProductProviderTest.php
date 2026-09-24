<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Hotels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Providers\EurositeHotelProductProvider;
use Tygh\Addons\Eurosite\Repository\HotelRepository;

/**
 * Travel Core asks every provider "is this product yours?" on every product
 * page; Eurosite answers from the product code before touching its table.
 */
final class EurositeHotelProductProviderTest extends TestCase
{
    public function testOtherProvidersProductsCostNoQuery(): void
    {
        $repo = $this->createMock(HotelRepository::class);
        $repo->expects(self::never())->method('findByProductId');
        $provider = new EurositeHotelProductProvider($repo);

        foreach (['HR59843', 'NVT123', 'RO0363', ''] as $code) {
            self::assertNull($provider->resolveProduct(10, $code), $code);
        }
        self::assertNull($provider->resolveProduct(0, 'EUS-LA-RO0363'));
    }

    public function testAnEurositeProductResolvesToItsHotel(): void
    {
        $repo = $this->createStub(HotelRepository::class);
        $repo->method('findByProductId')->willReturn([
            'product_code' => 'RO0363', 'name' => 'EAGLE&#039;S NEST', 'category' => 4,
            'city_name' => 'Mamaia', 'country_name' => '', 'pictures_json' => '["http://img/1.jpg"]', 'first_image' => '',
        ]);
        $hotel = (new EurositeHotelProductProvider($repo))->resolveProduct(10, 'EUS-LA-RO0363');

        self::assertNotNull($hotel);
        self::assertSame('RO0363', $hotel->hotelId, 'the booking form searches with this');
        self::assertSame('eurosite', $hotel->providerName);
        self::assertSame("EAGLE'S NEST", $hotel->name);
        self::assertSame(4, $hotel->classification);
        self::assertSame('Mamaia', $hotel->city);
        self::assertNull($hotel->country);
        self::assertSame('http://img/1.jpg', $hotel->imageUrl);
    }

    public function testAProductWithOurCodeButNoHotelIsNotOurs(): void
    {
        $repo = $this->createStub(HotelRepository::class);
        $repo->method('findByProductId')->willReturn(null);

        self::assertNull((new EurositeHotelProductProvider($repo))->resolveProduct(10, 'EUS-LA-RO0363'));
    }

    public function testTheHotelsProductIdIsFound(): void
    {
        $repo = $this->createStub(HotelRepository::class);
        $repo->method('findByProductCode')->willReturnMap([['RO0363', ['product_id' => 77]], ['RO0001', ['product_id' => null]]]);
        $p = new EurositeHotelProductProvider($repo);

        self::assertSame(77, $p->productIdForHotelId('RO0363'));
        self::assertNull($p->productIdForHotelId('RO0001'));
    }
}
