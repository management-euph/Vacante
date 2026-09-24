<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\Eurosite\Services\OfferContextStore;

/**
 * Stop Sale (Availability Code="ST") is shown for information only. The
 * spec: "Nu trebuie sa permiteti incercarea de rezervare pe produsele Stop
 * Sale." The search used to offer a Book button on every offer.
 */
final class StopSaleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\Tygh\Tygh::class)) {
            eval('namespace Tygh; final class Tygh { /** @var array<string, mixed> */ public static $app = []; }');
        }
    }

    protected function setUp(): void
    {
        \Tygh\Tygh::$app['session'] = new \ArrayObject();
    }

    private static function offer(string $code, string $text, string $variant): HotelOffer
    {
        return new HotelOffer(
            productCode: 'RO0099', productName: 'Condor', countryCode: 'RO', cityCode: 'ROMM', cityName: 'Mamaia',
            category: 4, class: 'Hotel', firstImage: '', latitude: '0', longitude: '0', currency: 'EUR',
            offerType: 'Normal', availability: $text, checkIn: '2026-10-24', checkOut: '2026-10-31',
            price: 100.0, gross: 110.0, net: 90.0, commission: 10.0, variantId: $variant, grila: 'Standard',
            availabilityCode: HotelOffer::normalizeAvailability($code, $text),
        );
    }

    public function testTheCodeAttributeWinsAndTheTextIsTheFallback(): void
    {
        self::assertSame('IM', HotelOffer::normalizeAvailability('IM', 'Immediate'));
        self::assertSame('ST', HotelOffer::normalizeAvailability('st', 'whatever'));
        self::assertSame('OR', HotelOffer::normalizeAvailability('', 'OnRequest'));
        self::assertSame('ST', HotelOffer::normalizeAvailability('', 'Stop Sale'));
        self::assertSame('IM', HotelOffer::normalizeAvailability('', 'Immediate'));
        self::assertSame('', HotelOffer::normalizeAvailability('', ''));
    }

    public function testOnlyStopSaleIsUnbookable(): void
    {
        self::assertTrue(self::offer('IM', 'Immediate', 'a')->isBookable());
        self::assertTrue(self::offer('OR', 'OnRequest', 'b')->isBookable());
        self::assertTrue(self::offer('', '', 'c')->isBookable(), 'an offer that says nothing is not blocked');
        self::assertFalse(self::offer('ST', 'StopSale', 'd')->isBookable());
    }

    /** No key means booking_form and add_to_cart have nothing to act on. */
    public function testAStopSaleOfferGetsNoBookingKey(): void
    {
        $keys = OfferContextStore::remember(
            [self::offer('IM', 'Immediate', 'a'), self::offer('ST', 'StopSale', 'b'), self::offer('OR', 'OnRequest', 'c')],
            ['adults' => 2, 'children_ages' => []],
        );

        self::assertArrayHasKey(0, $keys);
        self::assertArrayNotHasKey(1, $keys);
        self::assertArrayHasKey(2, $keys);
        self::assertNull(OfferContextStore::get(OfferContextStore::keyFor(self::offer('ST', 'StopSale', 'b'))));
        self::assertSame('IM', OfferContextStore::get($keys[0])['availability_code'] ?? null);
    }

    public function testTheSearchPageOffersNoBookButtonOnStopSale(): void
    {
        $root = dirname(__DIR__, 5);
        $tpl = (string) file_get_contents($root . '/design/themes/responsive/templates/addons/eurosite/views/eurosite_booking/search.tpl');
        $ctl = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/frontend/eurosite_booking/search.php');
        $cart = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/frontend/eurosite_booking/add_to_cart.php');

        self::assertMatchesRegularExpression('/\{if \$offer\.bookable\}\s*<a class="ty-btn ty-btn__primary travel-offer-book-btn"/', $tpl);
        self::assertStringContainsString("'bookable'     => \$offer->isBookable()", $ctl);
        self::assertStringContainsString("TypeCoerce::toString(\$snapshot['availability_code'] ?? '') === 'ST'", $cart);
    }
}
