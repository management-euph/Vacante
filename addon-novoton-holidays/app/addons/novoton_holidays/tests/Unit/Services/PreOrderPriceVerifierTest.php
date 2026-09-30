<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PreOrderPriceVerifier;
use Tygh\Addons\NovotonHolidays\Services\RoomOfferRows;
use Tygh\Registry;

/**
 * The Place-order check compares a cart line with the live price of the
 * offer it was SOLD from: same room, board and package.
 *
 * The regression (hotel 476): the answer lists "+BEACH" 795 first, then the
 * early-booking "+BEACH [STAY …]" 600. The verifier read the FIRST <Price>
 * and "corrected" a 600 line to 795 — another package's price — and asked
 * the customer to re-confirm.
 *
 * Runs verify() end to end with an in-memory pricing client (identity
 * commission), the storefront formatter/toast replaced by recorders, and
 * the travel_core checkout policy at its defaults (20 % alert, 0 absorb).
 */
#[CoversClass(PreOrderPriceVerifier::class)]
final class PreOrderPriceVerifierTest extends TestCase
{
    private const ROOM = 'DBL 2+0 DELUXE NO BALCONY';
    private const UAI = 'ULTRA ALL INCL';
    private const BEACH = 'ADMIRAL ***** +BEACH';
    private const BEACH_EB = 'ADMIRAL ***** +BEACH [STAY 18.09 - 31.10]';

    /** @var list<array<string, mixed>> */
    private array $apiCalls = [];

    /** @var list<float> */
    private array $formatted = [];

    /** @var list<array{string, string, string}> */
    private array $toasts = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        Registry::set('addons.novoton_holidays', []);
        Registry::set('addons.travel_core', null);
        ConfigProvider::reset();
        $this->apiCalls = [];
        $this->formatted = [];
        $this->toasts = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Registry::set('addons.novoton_holidays', null);
        ConfigProvider::reset();
    }

    /** The early-booking line at 600 is its own package's price: untouched. */
    public function testTheEarlyBookingLineIsNotCorrectedToAnotherPackage(): void
    {
        $result = $this->verifier()->verify($this->cart(600.0, self::BEACH_EB));

        self::assertSame([], $result['corrections']);
        self::assertSame([], $result['notifications']);
        self::assertFalse($result['reconfirm']);
        self::assertCount(1, $this->apiCalls);
    }

    public function testTheBeachLineAt795IsNotCorrected(): void
    {
        $result = $this->verifier()->verify($this->cart(795.0, self::BEACH));

        self::assertSame([], $result['corrections']);
        self::assertSame([], $result['notifications']);
        self::assertFalse($result['reconfirm']);
    }

    /** A real rise within the SAME package is corrected and re-confirmed. */
    public function testARiseOfTheSamePackageIsCorrected(): void
    {
        $result = $this->verifier()->verify($this->cart(700.0, self::BEACH));

        self::assertSame(['42' => ['api_price' => 795.0, 'api_price_raw' => 795.0]], $result['corrections']);
        self::assertTrue($result['reconfirm']);
        self::assertCount(1, $result['notifications']);
        self::assertSame('price_lower', $result['notifications'][0]['type']);
        self::assertSame(self::BEACH, $result['notifications'][0]['package_name']);

        // One customer toast, its amounts written by the cart formatter;
        // the admin-worded "Price Discrepancy" toast is gone.
        self::assertSame([700.0, 795.0], $this->formatted);
        self::assertCount(1, $this->toasts);
        self::assertSame('W', $this->toasts[0][0]);
        self::assertSame('novoton_holidays.price_change', $this->toasts[0][1]);
    }

    /**
     * The booked package is gone: never re-priced to another package's
     * price; the admin is told what is offered instead.
     */
    public function testAMissingPackageIsNeverCorrectedToAnotherPackage(): void
    {
        $result = $this->verifier()->verify($this->cart(500.0, 'ADMIRAL ***** HB ONLY'));

        self::assertSame([], $result['corrections']);
        self::assertFalse($result['reconfirm']);
        self::assertSame([], $this->toasts);
        self::assertCount(1, $result['notifications']);
        $notification = $result['notifications'][0];
        self::assertSame('offer_missing', $notification['type']);
        self::assertSame('ADMIRAL ***** HB ONLY', $notification['package_name']);
        self::assertSame(500.0, $notification['form_price']);
        self::assertSame([self::BEACH . ': 795.00', self::BEACH_EB . ': 600.00'], $notification['offered']);
    }

    /** No package on the line: the cheapest offer of the room + board. */
    public function testALineWithoutAPackageIsComparedWithTheCheapestRoomBoardOffer(): void
    {
        $result = $this->verifier()->verify($this->cart(600.0, ''));

        self::assertSame([], $result['corrections']);
        self::assertSame([], $result['notifications']);
    }

    /** A room that is not in the answer is no price — never its first <Price>. */
    public function testARoomMissingFromTheAnswerIsNotComparedWithTheFirstPrice(): void
    {
        $cart = $this->cart(500.0, '');
        $cart['products'][42]['extra']['room_id'] = 'SGL';

        $result = $this->verifier()->verify($cart);

        self::assertSame([], $result['corrections']);
        self::assertSame('offer_missing', $result['notifications'][0]['type'] ?? null);
    }

    public function testTheHotelNameFallsBackToTheProductThenTheCatalogueThenTheId(): void
    {
        $cart = $this->cart(500.0, 'GONE');
        $cart['products'][42]['extra']['hotel_name'] = '';
        $cart['products'][42]['product'] = 'Admiral Hotel';
        self::assertSame('Admiral Hotel', $this->verifier()->verify($cart)['notifications'][0]['hotel_name'] ?? null);

        unset($cart['products'][42]['product']);
        self::assertSame('Admiral (catalogue)', $this->verifier('Admiral (catalogue)')->verify($cart)['notifications'][0]['hotel_name'] ?? null);
        self::assertSame('#476', $this->verifier('')->verify($cart)['notifications'][0]['hotel_name'] ?? null);
    }

    /** Silent Sync: a fresh entry of the SAME package answers without the API. */
    public function testAFreshSilentSyncEntryOfTheSamePackageSkipsTheApi(): void
    {
        $cart = $this->cart(795.0, self::BEACH);
        $extra = $cart['products'][42]['extra'];
        $_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY] = [
            PreOrderPriceVerifier::priceCacheKey($extra) => ['api_price' => 795.0, 'api_price_raw' => 795.0, 'timestamp' => time()],
        ];

        $result = $this->verifier()->verify($cart);

        self::assertSame([], $this->apiCalls);
        self::assertSame([], $result['corrections']);
    }

    public function testTheSilentSyncKeyDependsOnThePackage(): void
    {
        $extra = $this->cart(795.0, self::BEACH)['products'][42]['extra'];
        $other = ['package_name' => self::BEACH_EB] + $extra;

        self::assertNotSame(PreOrderPriceVerifier::priceCacheKey($extra), PreOrderPriceVerifier::priceCacheKey($other));
        // URL-encoded or padded, it is the same package.
        self::assertSame(
            PreOrderPriceVerifier::priceCacheKey($extra),
            PreOrderPriceVerifier::priceCacheKey(['package_name' => ' ' . rawurlencode(self::BEACH)] + $extra),
        );
    }

    public function testLineOfferPinsThePackageAndFlagsAMissingOne(): void
    {
        $rows = RoomOfferRows::fromXml(self::fixture());
        $extra = ['room_id' => rawurlencode(self::ROOM), 'board_id' => self::UAI];

        self::assertSame(795.0, PreOrderPriceVerifier::lineOffer($rows, $extra + ['package_name' => self::BEACH])['row']['price'] ?? null);
        self::assertSame(600.0, PreOrderPriceVerifier::lineOffer($rows, $extra)['row']['price'] ?? null);
        self::assertSame(['row' => null, 'offer_missing' => true], PreOrderPriceVerifier::lineOffer($rows, $extra + ['package_name' => 'GONE']));
        self::assertSame(['row' => null, 'offer_missing' => false], PreOrderPriceVerifier::lineOffer([], $extra));
    }

    private static function fixture(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        return $xml;
    }

    /**
     * @return array{products: array<int, array<string, mixed>>}
     */
    private function cart(float $total, string $package): array
    {
        return [
            // Real carts key products by numeric hashes.
            'products' => [
                42 => [
                    'product_id' => 7,
                    'price' => $total,
                    'extra' => [
                        'novoton_booking' => true,
                        'novoton_booking_id' => 9,
                        'hotel_id' => '476',
                        'hotel_name' => 'Admiral',
                        'package_name' => $package,
                        'room_id' => self::ROOM,
                        'board_id' => self::UAI,
                        'check_in' => '2026-10-05',
                        'check_out' => '2026-10-11',
                        'adults' => 2,
                        'children' => 0,
                        'children_ages' => '',
                        'total_price' => $total,
                    ],
                ],
            ],
        ];
    }

    private function verifier(string $catalogueHotelName = ''): PreOrderPriceVerifier
    {
        $pricing = new class ($this->apiCalls) implements PricingApiClientInterface {
            /** @param list<array<string, mixed>> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function applyCommission(float $price): float
            {
                return $price;
            }

            public function buildRoomPriceXml(array $params): string
            {
                return '';
            }

            public function getRoomPriceBatch(array $requestParams, int $concurrency = 5): array
            {
                return [];
            }

            public function getRoomPrice(array $params): \SimpleXMLElement|false
            {
                $this->calls[] = $params;

                return simplexml_load_file(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
            }

            public function getRoomPriceByResort(array $params): \SimpleXMLElement|false
            {
                return false;
            }

            public function getRoomPriceByResortRaw(array $params): string
            {
                return '';
            }

            public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): \SimpleXMLElement
            {
                return new \SimpleXMLElement('<r/>');
            }

            public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK'): \SimpleXMLElement
            {
                return new \SimpleXMLElement('<r/>');
            }
        };

        return new PreOrderPriceVerifier(
            null,
            static fn (): PricingApiClientInterface => $pricing,
            function (float $amount): string {
                $this->formatted[] = $amount;

                return '$' . number_format($amount, 2);
            },
            function (string $type, string $title, string $message): void {
                $this->toasts[] = [$type, $title, $message];
            },
            static fn (string $hotelId): string => $catalogueHotelName,
        );
    }
}
