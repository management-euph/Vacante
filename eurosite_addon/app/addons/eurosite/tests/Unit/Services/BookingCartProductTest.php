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
 * That stand-in product is gone. As for Sphinx and Novoton, the cart line goes
 * on the hotel's own product, chosen by product_id: the product page the guest
 * booked from, else the hotel's linked product. A hotel that is not a store
 * product cannot be booked.
 */
final class BookingCartProductTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @param array<int, string> $statuses */
    private static function resolve(int $page, int $hotel, array $statuses): array
    {
        return BookingCartProduct::resolve($page, $hotel, static fn (int $id): string => $statuses[$id] ?? '');
    }

    public function testTheProductPageTheGuestBookedFromWins(): void
    {
        self::assertSame(['product_id' => 41, 'source' => 'page'], self::resolve(41, 42, [41 => 'A', 42 => 'A']));
    }

    public function testAGateHiddenProductCanStillBeBooked(): void
    {
        self::assertSame(['product_id' => 41, 'source' => 'page'], self::resolve(41, 0, [41 => 'H']));
    }

    public function testADisabledOrDeletedPageProductFallsBackToTheHotelsProduct(): void
    {
        self::assertSame(['product_id' => 42, 'source' => 'hotel'], self::resolve(41, 42, [41 => 'D', 42 => 'A']));
        self::assertSame(['product_id' => 42, 'source' => 'hotel'], self::resolve(41, 42, [42 => 'A']), 'deleted: no status');
        self::assertSame(['product_id' => 42, 'source' => 'hotel'], self::resolve(0, 42, [42 => 'A']), 'destination search: no page product');
    }

    public function testAHotelWithoutAProductCannotBeBooked(): void
    {
        self::assertSame(['product_id' => 0, 'source' => 'none'], self::resolve(0, 0, []));
        self::assertSame(['product_id' => 0, 'source' => 'none'], self::resolve(41, 41, [41 => 'D']));
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

    public function testTheCheckoutPathIsWiredByProductIdWithNoStandInProduct(): void
    {
        $cart = (string) file_get_contents(self::ROOT . '/controllers/frontend/eurosite_booking/add_to_cart.php');
        self::assertStringContainsString('$cartProductId = BookingCartProduct::forSnapshot(', $cart);
        self::assertStringContainsString("'product_id'   => \$cartProductId,", $cart);
        self::assertStringContainsString("__('eurosite.hotel_not_bookable'", $cart);

        // The guest learns it before filling in the form, not at checkout.
        $form = (string) file_get_contents(self::ROOT . '/controllers/frontend/eurosite_booking/booking_form.php');
        self::assertStringContainsString("if (BookingCartProduct::forSnapshot(\$snapshot, \$hotelRow)['product_id'] <= 0) {", $form);

        // search.php keeps the page's product_id only when it is that hotel's product.
        $search = (string) file_get_contents(self::ROOT . '/controllers/frontend/eurosite_booking/search.php');
        self::assertStringContainsString("\$pageProductId = RequestCoerce::int(\$_REQUEST, 'product_id');", $search);
        self::assertStringContainsString('Container::hotels()->findByProductId($pageProductId)', $search);
        self::assertStringContainsString('], $cartProductIds);', $search);

        // No hidden EUROSITE-BOOKING product anywhere: not created, not looked up.
        foreach ([
            '/functions/install.php',
            '/controllers/frontend/eurosite_booking/add_to_cart.php',
            '/controllers/backend/eurosite.php',
            '/../../../design/backend/templates/addons/eurosite/views/eurosite/manage.tpl',
        ] as $rel) {
            $src = (string) file_get_contents(self::ROOT . $rel);
            self::assertStringNotContainsString('EUROSITE-BOOKING', $src, $rel);
            self::assertStringNotContainsString('carrier', $src, $rel);
        }
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
