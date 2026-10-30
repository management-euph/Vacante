<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Novoton add-to-cart fell back to the product_id the browser sent when the
 * hotel's NVT product was not found — and then saved that product onto a new
 * hotel row. Now the product is the hotel's own Novoton product, active or
 * hidden by Travel Core's availability gate (HotelCartProduct → travel_core
 * ProviderCartProduct); the form's product_id only counts when it is one of
 * the hotel's own.
 */
final class AddToCartProductTrustTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testTheControllerNoLongerFallsBackToTheFormsProduct(): void
    {
        $cart = self::read('controllers/frontend/novoton_booking/add_to_cart.php');

        self::assertStringNotContainsString('Try the product_id from form', $cart);
        self::assertStringContainsString(
            "\$product_id = \\Tygh\\Addons\\NovotonHolidays\\Services\\HotelCartProduct::resolve(\$bdHotelId, TypeCoerce::toInt(\$bookingData['product_id'] ?? 0));",
            $cart,
        );
        self::assertStringContainsString('return HotelCartProduct::resolve($hotelId, $fallbackProductId);', self::read('src/Services/BookingService.php'));
    }

    public function testTheProductIsTheHotelsOwnNovotonProductAndActive(): void
    {
        $resolver = self::read('src/Services/HotelCartProduct.php');

        self::assertStringContainsString('SELECT product_id FROM ?:novoton_hotels WHERE hotel_id = ?i', $resolver);
        self::assertStringContainsString('ConfigProvider::getProductCodePrefixes()', $resolver);
        self::assertStringContainsString('in_array($requestedProductId, $owned, true)', $resolver);
        self::assertStringContainsString('return ProviderCartProduct::resolve(', $resolver);
        // Hidden by the availability gate (Automatic mode): still bookable from a
        // direct link; hidden by an admin: not.
        self::assertStringContainsString('(new HotelAvailabilityRepository())->isGateHidden(NovotonAvailabilitySource::PROVIDER, $productId),', $resolver);
    }
}
