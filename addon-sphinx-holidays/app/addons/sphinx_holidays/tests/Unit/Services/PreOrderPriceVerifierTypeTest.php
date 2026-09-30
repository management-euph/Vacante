<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\PreOrderPriceVerifier;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Registry;

/**
 * The checkout re-check sent every Sphinx cart line to the HOTEL verify
 * endpoint. Circuit and package offers are unknown there, so their lines
 * were dropped as "unavailable" (or never checked). Each type now goes to
 * its own endpoint: packages to verifyPackageOffer, circuits (no verify
 * endpoint) to a fresh getCircuitQuote, services through customize.
 */
#[CoversClass(PreOrderPriceVerifier::class)]
final class PreOrderPriceVerifierTypeTest extends TestCase
{
    /** @var list<string> */
    private static array $calls = [];

    /** @var array<string, mixed> */
    private static array $answers = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        self::$calls = [];
        self::$answers = [];
        Registry::set('addons.sphinx_holidays', null);
        ConfigProvider::resetSettingsCache();
        Container::reset();
        Container::override('api', static fn (): SphinxApi => new class extends SphinxApi {
            public function __construct()
            {
            }

            public function verifyHotelOffer(string $offerId): ?array
            {
                return PreOrderPriceVerifierTypeTest::answer('verifyHotelOffer');
            }

            public function verifyPackageOffer(string $offerId): ?array
            {
                return PreOrderPriceVerifierTypeTest::answer('verifyPackageOffer');
            }

            public function customizePackage(array $data): ?array
            {
                return PreOrderPriceVerifierTypeTest::answer('customizePackage');
            }

            public function getCircuitQuote(array $params): array
            {
                return PreOrderPriceVerifierTypeTest::answer('getCircuitQuote') ?? [];
            }

            public function customizeCircuit(array $data): ?array
            {
                return PreOrderPriceVerifierTypeTest::answer('customizeCircuit');
            }
        });
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Container::reset();
        Registry::set('addons.sphinx_holidays', null);
        ConfigProvider::resetSettingsCache();
    }

    /** @return array<array-key, mixed>|null */
    public static function answer(string $method): ?array
    {
        self::$calls[] = $method;
        $answer = self::$answers[$method] ?? null;

        return is_array($answer) ? $answer : null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function cart(string $type, float $price, array $extra = []): array
    {
        return ['products' => [7 => [
            'price' => $price,
            'extra' => $extra + [
                'sphinx_booking' => 1,
                'booking_type' => $type,
                'offer_id' => 'offer-1',
                'total_price' => $price,
                'hotel_id' => '55',
                'hotel_name' => 'Test',
                'check_in' => '2026-11-02',
                'adults' => 2,
                'children_ages' => '',
            ],
        ]]];
    }

    public function testAPackageGoesToThePackageEndpointNotTheHotelOne(): void
    {
        self::$answers['verifyPackageOffer'] = ['offer_id' => 'offer-1', 'pricing' => ['selling_price' => 120.0]];

        $result = (new PreOrderPriceVerifier())->verify(self::cart('package', 100.0));

        self::assertSame(['verifyPackageOffer'], self::$calls);
        self::assertSame([], $result['unavailable']);
        self::assertSame(120.0, $result['corrections'][7]['api_price'] ?? null);
    }

    public function testChosenPackageServicesArePricedThroughCustomize(): void
    {
        self::$answers['verifyPackageOffer'] = ['offer_id' => 'offer-1', 'pricing' => ['selling_price' => 100.0]];
        self::$answers['customizePackage'] = ['pricing' => ['selling_price' => 130.0]];

        $result = (new PreOrderPriceVerifier())->verify(self::cart('package', 130.0, ['additional_services' => ['TRF']]));

        self::assertSame(['verifyPackageOffer', 'customizePackage'], self::$calls);
        self::assertSame([], $result['corrections'], 'priced with its services, it matches');
        self::assertSame([], $result['unavailable']);
    }

    public function testAPackageNoLongerOfferedIsRemoved(): void
    {
        $result = (new PreOrderPriceVerifier())->verify(self::cart('package', 100.0));

        self::assertArrayHasKey(7, $result['unavailable']);
    }

    public function testACircuitIsReQuotedForTheSameDeparture(): void
    {
        self::$answers['getCircuitQuote'] = [
            ['offer_id' => 'other', 'pricing' => ['selling_price' => 999.0]],
            ['offer_id' => 'offer-1', 'pricing' => ['selling_price' => 100.0]],
        ];

        $result = (new PreOrderPriceVerifier())->verify(self::cart('circuit', 100.0));

        self::assertSame(['getCircuitQuote'], self::$calls, 'never the hotel endpoint');
        self::assertSame([], $result['corrections'], "this offer's own quote is used");
        self::assertSame([], $result['unavailable']);
    }

    public function testACircuitDepartureWithNoQuoteIsRemoved(): void
    {
        $result = (new PreOrderPriceVerifier())->verify(self::cart('circuit', 100.0));

        self::assertArrayHasKey(7, $result['unavailable']);
    }

    public function testCircuitServicesThatCannotBeRePricedAreNotComparedOrRemoved(): void
    {
        self::$answers['getCircuitQuote'] = [['offer_id' => 'offer-1', 'pricing' => ['selling_price' => 50.0]]];

        $result = (new PreOrderPriceVerifier())->verify(self::cart('circuit', 100.0, ['service_codes' => ['X']]));

        self::assertSame(['customizeCircuit', 'getCircuitQuote'], self::$calls);
        self::assertSame([], $result['corrections'], 'a base quote without the services would compare wrong');
        self::assertSame([], $result['unavailable']);
    }

    public function testAHotelStillUsesTheHotelEndpoint(): void
    {
        self::$answers['verifyHotelOffer'] = ['offer_id' => 'offer-1', 'price' => 100.0];

        $result = (new PreOrderPriceVerifier())->verify(self::cart('hotel', 100.0));

        self::assertSame(['verifyHotelOffer'], self::$calls);
        self::assertSame([], $result['corrections']);
    }

    public function testAFreshCircuitOrPackagePriceNeedsNoProviderCall(): void
    {
        $_SESSION['sphinx_price_cache'] = [md5('offer-1') => ['api_price_raw' => 100.0, 'timestamp' => time()]];

        (new PreOrderPriceVerifier())->verify(self::cart('circuit', 100.0));
        (new PreOrderPriceVerifier())->verify(self::cart('package', 100.0));

        self::assertSame([], self::$calls);
    }

    public function testCircuitAndPackageAddToCartRememberTheRawPrice(): void
    {
        $root = dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/';
        foreach (['circuit_add_to_cart.php', 'package_add_to_cart.php'] as $file) {
            $src = (string) file_get_contents($root . $file);
            $remember = (int) strpos($src, '$cartService->rememberVerifiedPrice($offer_id, $total_price);');
            self::assertGreaterThan(0, $remember, $file);
            self::assertLessThan((int) strpos($src, '->applyCommission('), $remember, "{$file}: before commission");
        }
        $circuit = (string) file_get_contents($root . 'circuit_add_to_cart.php');
        self::assertStringContainsString("'departure_id' => RequestCoerce::int(\$_REQUEST, 'departure_id'),", $circuit);
        self::assertStringContainsString("'service_codes' => \$selected_services,", $circuit);
    }
}
