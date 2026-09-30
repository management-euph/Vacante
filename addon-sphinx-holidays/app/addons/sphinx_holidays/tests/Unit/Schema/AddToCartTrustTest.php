<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Sphinx add-to-cart used to trust the browser twice:
 *  - the product: any product_id in the request won (CartService), so a
 *    booking could go on any store product, active or not;
 *  - the price: circuit and package add-to-cart fell back to the form's
 *    total_price — changeable, and already carrying commission, which was
 *    then added a second time.
 * Now the product is the hotel's (or circuit's) own buyable Sphinx product,
 * the offer is tied to its hotel, and the price is the provider's.
 */
final class AddToCartTrustTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testTheProductIsTheHotelsOwnBuyableSphinxProduct(): void
    {
        $cart = self::read('src/Services/CartService.php');

        self::assertStringNotContainsString("if (\$providedId > 0) {\n            return \$providedId;", $cart);
        self::assertStringContainsString('SELECT product_id, product_skip_reason FROM ?:sphinx_hotels WHERE hotel_id = ?s', $cart);
        self::assertStringContainsString("FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active'", $cart);
        self::assertSame(2, substr_count($cart, 'return ProviderCartProduct::resolve('));
        self::assertStringContainsString('=== HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY', $cart);
    }

    public function testTheOfferIsTiedToItsOwnHotel(): void
    {
        $hotel = self::read('controllers/frontend/sphinx_booking/add_to_cart.php');
        self::assertStringContainsString("\$offerHotelId = TypeCoerce::toString(\$verifyResult['hotel_id'] ?? '');", $hotel);
        self::assertStringContainsString('OfferSnapshotStore::get($offer_id)', $hotel);
        self::assertLessThan(
            (int) strpos($hotel, '$cartService->resolveProductId($hotel_id, $product_id)'),
            (int) strpos($hotel, '$hotel_id = $offerHotelId;'),
        );

        $package = self::read('controllers/frontend/sphinx_booking/package_add_to_cart.php');
        self::assertStringContainsString('Container::getApi()->verifyPackageOffer($offer_id)', $package);
        self::assertStringContainsString('$hotel_id = $verifiedHotelId;', $package);
    }

    public function testCircuitAndPackagePricesNeverComeFromTheFormAndCommissionIsAddedOnce(): void
    {
        foreach (['circuit_add_to_cart.php', 'package_add_to_cart.php'] as $file) {
            $src = self::read('controllers/frontend/sphinx_booking/' . $file);
            foreach (["\$bookingData['total_price']", "\$bookingData['base_price']", "\$bookingData['currency']"] as $formField) {
                self::assertStringNotContainsString($formField, $src, $file);
            }
            self::assertSame(1, substr_count($src, '->applyCommission('), $file);
        }

        self::assertStringContainsString(
            '(new \Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore())->get($offer_id, $circuit_id, time())',
            self::read('controllers/frontend/sphinx_booking/circuit_add_to_cart.php'),
        );
        self::assertStringContainsString(
            '(new \Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore())->remember(',
            self::read('controllers/frontend/sphinx_booking/circuit_booking_form.php'),
        );
    }
}
