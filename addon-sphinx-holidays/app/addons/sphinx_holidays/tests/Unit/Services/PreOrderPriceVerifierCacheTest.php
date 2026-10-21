<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CheckoutBookingBinder;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\PreOrderPriceVerifier;
use Tygh\Registry;

/**
 * Pins the "Silent Sync" checkout cache: add_to_cart verifies every offer
 * live and stores the raw price in $_SESSION['sphinx_price_cache']; while
 * that entry is younger than the TTL, the pre-order verifier must use it
 * WITHOUT any API access, and beyond the TTL (or with the TTL set to 0)
 * it must fall back to the live path.
 *
 * The tests run with no API configured, which makes the discriminator
 * sharp: on the live path the verifier bails out with an empty result
 * ("API unavailable"), so corrections can ONLY appear when the cached
 * price was used.
 *
 * The cart fixtures use NUMERIC cart ids on purpose — real CS-Cart carts
 * key products by int hashes, and routing them through toStringMap()
 * (which drops int keys) once made this verifier silently iterate nothing.
 * Any regression to that empties every result here.
 *
 * Every line is bound to its own pending booking row priced at the form
 * price (CheckoutBookingBinder), so only the cache decides the outcome.
 */
#[CoversClass(PreOrderPriceVerifier::class)]
final class PreOrderPriceVerifierCacheTest extends TestCase
{
    private const OFFER_ID = 'offer-abc-123';

    protected function setUp(): void
    {
        $_SESSION = [];
        Registry::set('addons.sphinx_holidays', null);
        ConfigProvider::resetSettingsCache();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Registry::set('addons.sphinx_holidays', null);
        ConfigProvider::resetSettingsCache();
    }

    /**
     * @param float $formPrice
     * @return array<string, mixed>
     */
    private function cartWithOffer(float $formPrice): array
    {
        return [
            'products' => [
                '7' => [
                    'product_id' => 100,
                    'amount' => 1,
                    'price' => $formPrice,
                    'stored_price' => 'Y',
                    'extra' => [
                        'sphinx_booking' => 1,
                        'travel_booking_id' => 1,
                        'offer_id' => self::OFFER_ID,
                        'total_price' => $formPrice,
                        'hotel_id' => 'H1',
                        'hotel_name' => 'Hotel Test',
                    ],
                ],
            ],
        ];
    }

    /** The verifier with line 7 bound to its pending booking row at $formPrice. */
    private function verifier(float $formPrice): PreOrderPriceVerifier
    {
        $row = [
            'booking_id' => 1, 'order_id' => 0, 'user_id' => 5, 'session_id' => '',
            'product_id' => 100, 'offer_id' => self::OFFER_ID, 'status' => 'pending',
            'total_price' => $formPrice, 'currency' => 'EUR',
        ];

        return new PreOrderPriceVerifier(null, new CheckoutBookingBinder(
            static fn (int $id): ?array => $id === 1 ? $row : null,
            static fn (float $amount, string $currency): float => $amount,
            5,
            '',
        ));
    }

    private function seedCache(float $rawPrice, int $timestamp): void
    {
        $_SESSION['sphinx_price_cache'] = [
            md5(self::OFFER_ID) => [
                'api_price_raw' => $rawPrice,
                'timestamp' => $timestamp,
            ],
        ];
    }

    public function testFreshCacheEntryIsUsedWithoutAnyApiAccess(): void
    {
        // Verified 10 s ago at 100 — the customer's form still shows 90.
        $this->seedCache(100.0, time() - 10);

        $result = $this->verifier(90.0)->verify($this->cartWithOffer(90.0));

        // Commission defaults to 0 in tests, so api_price === the cached raw.
        self::assertSame(100.0, $result['corrections']['7']['api_price'] ?? null);
        self::assertSame(100.0, $result['corrections']['7']['api_price_raw'] ?? null);
        self::assertTrue((bool) $result['reconfirm'], 'an upward correction beyond the absorb allowance requires re-confirmation');
        self::assertSame([], $result['unavailable']);
    }

    public function testMatchingCachedPriceLetsTheOrderThroughSilently(): void
    {
        $this->seedCache(90.0, time());

        $result = $this->verifier(90.0)->verify($this->cartWithOffer(90.0));

        self::assertSame([], $result['corrections']);
        self::assertSame([], $result['notifications']);
        self::assertFalse((bool) $result['reconfirm']);
        self::assertTrue((bool) $result['allow']);
    }

    public function testStaleEntryFallsBackToTheLiveVerify(): void
    {
        // Older than the 180 s default TTL → live path → with no API
        // configured the verifier bails out without corrections.
        $this->seedCache(100.0, time() - 1000);

        $result = $this->verifier(90.0)->verify($this->cartWithOffer(90.0));

        self::assertSame([], $result['corrections'], 'a stale cached price must never be trusted');
        self::assertFalse((bool) $result['reconfirm']);
    }

    public function testTtlZeroDisablesTheCacheEntirely(): void
    {
        Registry::set('addons.sphinx_holidays', ['preorder_cache_ttl' => '0']);
        ConfigProvider::resetSettingsCache();
        self::assertSame(0, ConfigProvider::getPreorderCacheTtl());

        $this->seedCache(100.0, time()); // perfectly fresh — must still be ignored

        $result = $this->verifier(90.0)->verify($this->cartWithOffer(90.0));

        self::assertSame([], $result['corrections'], 'TTL 0 must force the live verify on every click');
    }

    public function testTtlDefaultsTo180Seconds(): void
    {
        self::assertSame(180, ConfigProvider::getPreorderCacheTtl());
    }

    public function testAddToCartWritesTheCacheTheVerifierReads(): void
    {
        // Source pin: the writer and the reader agree on the session bag
        // shape — same key derivation, same fields.
        $controller = dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/add_to_cart.php';
        $src = (string) file_get_contents($controller);
        self::assertStringContainsString("\$_SESSION['sphinx_price_cache']", $src);
        self::assertStringContainsString('$price_cache[md5($offer_id)] = [', $src);
        self::assertStringContainsString("'api_price_raw' => \$basePrice", $src);
        self::assertStringContainsString("'timestamp'     => time()", $src);
    }
}
