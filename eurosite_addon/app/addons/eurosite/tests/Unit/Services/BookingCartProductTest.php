<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\Eurosite\Services\BookingCartProduct;
use Tygh\Addons\Eurosite\Services\OfferContextStore;

/**
 * "Booking checkout is not fully configured yet": every Eurosite booking went
 * into the cart on a hidden EUROSITE-BOOKING product, found by product code
 * and created only when the add-on was installed. A store without it could
 * not take a single booking, even for a hotel that is a store product.
 *
 * The cart line now goes on a product chosen by product_id: the product page
 * the guest booked from, else the hotel's own product, else the carrier —
 * which add_to_cart creates when it is missing.
 */
final class BookingCartProductTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @var list<string> */
    private array $carrierCalls = [];

    /** @param array<int, string> $statuses */
    private function resolve(int $page, int $hotel, array $statuses, int $carrier = 900): array
    {
        return BookingCartProduct::resolve(
            $page,
            $hotel,
            static fn (int $id): string => $statuses[$id] ?? '',
            function () use ($carrier): int {
                $this->carrierCalls[] = 'ensure';

                return $carrier;
            },
        );
    }

    public function testTheProductPageTheGuestBookedFromWins(): void
    {
        self::assertSame(['product_id' => 41, 'source' => 'page'], $this->resolve(41, 42, [41 => 'A', 42 => 'A']));
        self::assertSame([], $this->carrierCalls, 'no carrier is created when a product is used');
    }

    public function testAGateHiddenProductCanStillBeBooked(): void
    {
        self::assertSame(['product_id' => 41, 'source' => 'page'], $this->resolve(41, 0, [41 => 'H']));
    }

    public function testADisabledOrDeletedPageProductFallsBackToTheHotelsProduct(): void
    {
        self::assertSame(['product_id' => 42, 'source' => 'hotel'], $this->resolve(41, 42, [41 => 'D', 42 => 'A']));
        self::assertSame(['product_id' => 42, 'source' => 'hotel'], $this->resolve(41, 42, [42 => 'A']), 'deleted: no status');
        self::assertSame(['product_id' => 42, 'source' => 'hotel'], $this->resolve(0, 42, [42 => 'A']), 'destination search: no page product');
    }

    public function testAHotelWithoutAProductUsesTheCarrier(): void
    {
        self::assertSame(['product_id' => 900, 'source' => 'carrier'], $this->resolve(0, 0, []));
        self::assertSame(['product_id' => 900, 'source' => 'carrier'], $this->resolve(41, 41, [41 => 'D']));
        self::assertSame(['ensure', 'ensure'], $this->carrierCalls);
    }

    public function testNothingToBookOnWhenTheCarrierCannotBeCreated(): void
    {
        self::assertSame(['product_id' => 0, 'source' => 'none'], $this->resolve(0, 0, [], 0));
    }

    public function testOnlyActiveAndHiddenProductsCanBeBought(): void
    {
        self::assertTrue(BookingCartProduct::canBeBought('A'));
        self::assertTrue(BookingCartProduct::canBeBought('H'));
        self::assertFalse(BookingCartProduct::canBeBought('D'));
        self::assertFalse(BookingCartProduct::canBeBought(''));
    }

    public function testTheSnapshotCarriesThePageProductOnlyForThatHotel(): void
    {
        if (!class_exists(\Tygh\Tygh::class)) {
            eval('namespace Tygh; final class Tygh { /** @var array<string, mixed> */ public static $app = []; }');
        }
        \Tygh\Tygh::$app['session'] = new \ArrayObject();

        $keys = OfferContextStore::remember(
            [self::offer('RO0009', 'a'), self::offer('RO0363', 'b')],
            ['adults' => 2, 'children_ages' => []],
            ['RO0009' => 41],
        );

        self::assertSame(41, OfferContextStore::get($keys[0])['cart_product_id'] ?? null);
        self::assertSame(0, OfferContextStore::get($keys[1])['cart_product_id'] ?? null);
    }

    public function testTheCheckoutPathIsWiredByProductId(): void
    {
        $cart = (string) file_get_contents(self::ROOT . '/controllers/frontend/eurosite_booking/add_to_cart.php');
        self::assertStringContainsString('$cartProduct = BookingCartProduct::resolve(', $cart);
        self::assertStringContainsString("TypeCoerce::toInt(\$snapshot['cart_product_id'] ?? 0)", $cart);
        self::assertStringContainsString('static fn (): int => fn_eurosite_ensure_carrier_product(),', $cart);
        self::assertStringContainsString("'product_id'   => \$cartProductId,", $cart);
        self::assertStringNotContainsString("WHERE product_code = 'EUROSITE-BOOKING'", $cart, 'no bare lookup by code');

        // search.php keeps the page's product_id only when it is that hotel's product.
        $search = (string) file_get_contents(self::ROOT . '/controllers/frontend/eurosite_booking/search.php');
        self::assertStringContainsString("\$pageProductId = RequestCoerce::int(\$_REQUEST, 'product_id');", $search);
        self::assertStringContainsString('Container::hotels()->findByProductId($pageProductId)', $search);
        self::assertStringContainsString('], $cartProductIds);', $search);

        // The carrier: found by its remembered id, created with a company, healed from the dashboard.
        $install = (string) file_get_contents(self::ROOT . '/functions/install.php');
        self::assertStringContainsString("const EUROSITE_CARRIER_STORAGE_KEY = 'eurosite_carrier_product_id';", $install);
        self::assertStringContainsString("'company_id'       => \\Tygh\\Addons\\Eurosite\\Services\\ConfigProvider::getCompanyId(),", $install);
        self::assertStringContainsString("SET status = 'H' WHERE product_id = ?i AND status = 'D'", $install);
        $backend = (string) file_get_contents(self::ROOT . '/controllers/backend/eurosite.php');
        self::assertStringContainsString("\$view->assign('eurosite_carrier_ok', function_exists('fn_eurosite_ensure_carrier_product') && fn_eurosite_ensure_carrier_product() > 0);", $backend);
        $manage = (string) file_get_contents(self::ROOT . '/../../../design/backend/templates/addons/eurosite/views/eurosite/manage.tpl');
        self::assertStringContainsString('{if !$eurosite_carrier_ok}', $manage);
    }

    private static function offer(string $code, string $variant): HotelOffer
    {
        return new HotelOffer(
            productCode: $code, productName: 'IAKI', countryCode: 'RO', cityCode: 'ROMM', cityName: 'Mamaia',
            category: 4, class: 'Hotel', firstImage: '', latitude: '0', longitude: '0', currency: 'EUR',
            offerType: 'Normal', availability: 'Immediate', checkIn: '2026-10-05', checkOut: '2026-10-11',
            price: 548.0, gross: 548.0, net: 500.0, commission: 48.0, variantId: $variant, grila: 'Standard',
            availabilityCode: 'IM',
        );
    }
}
