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

    /** @var array<int, array<string, mixed>> novoton_bookings rows by booking id */
    private array $bookingRows = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        Registry::set('addons.novoton_holidays', []);
        Registry::set('addons.travel_core', null);
        ConfigProvider::reset();
        $this->apiCalls = [];
        $this->formatted = [];
        $this->toasts = [];
        $this->bookingRows = [];
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

    /**
     * C2/H4: every room of the reservation is re-priced with its own room,
     * board, package, adults and child ages — not only the line's top-level
     * room — and the line is compared with their sum.
     */
    public function testEveryRoomOfAMultiRoomLineIsRepriced(): void
    {
        $result = $this->verifier()->verify($this->multiRoomCart(795.0 + 675.0));

        self::assertSame([], $result['corrections']);
        self::assertSame([], $result['notifications']);
        self::assertSame(
            [[self::ROOM, self::UAI, 2, []], [self::ROOM, 'BB', 2, [7]]],
            array_map(static fn (array $call): array => [$call['room_id'], $call['board_id'], $call['adults'], $call['children']], $this->apiCalls),
        );
    }

    /** A two-room line charged one room's price is corrected to both rooms. */
    public function testAMultiRoomLineChargedForOneRoomIsCorrectedToTheSum(): void
    {
        $result = $this->verifier()->verify($this->multiRoomCart(795.0));

        self::assertSame(['42' => [
            'api_price' => 1470.0,
            'api_price_raw' => 1470.0,
            'room_prices' => [['price' => 795.0, 'price_raw' => 795.0], ['price' => 675.0, 'price_raw' => 675.0]],
        ]], $result['corrections']);
        self::assertTrue($result['reconfirm']);
    }

    /**
     * An admin editing a placed order re-places it (pre_place_order runs with
     * cart.order_id): its bookings were priced and submitted with the order,
     * so nothing is re-priced, corrected or blocked.
     */
    public function testAPlacedOrderBeingEditedIsNotRepriced(): void
    {
        $cart = $this->multiRoomCart(795.0);
        $cart['order_id'] = 812;

        $result = $this->verifier()->verify($cart);

        self::assertSame([], $result['corrections']);
        self::assertFalse($result['reconfirm']);
        self::assertSame([], $this->apiCalls);
    }

    /**
     * add_to_cart keys its Silent Sync entries on the priced rooms (the line
     * without guests_data): when a guest's age is not the priced one, the
     * checkout's key differs and the room is re-priced at the guest's age.
     */
    public function testAGuestAgeThatIsNotThePricedOneMissesAddToCartsEntry(): void
    {
        $extra = $this->childCart(1)['products'][42]['extra'];
        $priced = $extra;
        unset($priced['guests_data']);
        $pricedKey = PreOrderPriceVerifier::priceCacheKey(PreOrderPriceVerifier::roomLines($priced)[0]);

        self::assertSame($pricedKey, PreOrderPriceVerifier::priceCacheKey(PreOrderPriceVerifier::roomLines($extra)[0]), 'same ages: same entry');

        $extra['guests_data'] = (string) json_encode(['room1_child_1' => ['type' => 'child', 'room' => 1, 'age' => 14, 'name' => 'Pop, Ion']]);
        self::assertNotSame($pricedKey, PreOrderPriceVerifier::priceCacheKey(PreOrderPriceVerifier::roomLines($extra)[0]));
    }

    /** A room that is no longer offered leaves the line at its shown price. */
    public function testAMultiRoomLineWithAnUnofferedRoomIsNotCorrected(): void
    {
        $cart = $this->multiRoomCart(795.0);
        $cart['products'][42]['extra']['rooms_data'][1]['package_name'] = 'GONE';

        $result = $this->verifier()->verify($cart);

        self::assertSame([], $result['corrections']);
        self::assertSame('offer_missing', $result['notifications'][0]['type'] ?? null);
        self::assertSame('GONE', $result['notifications'][0]['package_name'] ?? null);
    }

    /** Silent Sync per room: add_to_cart's entries answer, a missing one asks the API. */
    public function testEachRoomHasItsOwnSilentSyncEntry(): void
    {
        $cart = $this->multiRoomCart(1470.0);
        $lines = PreOrderPriceVerifier::roomLines($cart['products'][42]['extra']);
        self::assertCount(2, $lines);
        $_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY] = [
            PreOrderPriceVerifier::priceCacheKey($lines[0]) => ['api_price' => 795.0, 'api_price_raw' => 795.0, 'timestamp' => time()],
            PreOrderPriceVerifier::priceCacheKey($lines[1]) => ['api_price' => 675.0, 'api_price_raw' => 675.0, 'timestamp' => time()],
        ];

        self::assertSame([], $this->verifier()->verify($cart)['corrections']);
        self::assertSame([], $this->apiCalls);

        unset($_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY][PreOrderPriceVerifier::priceCacheKey($lines[1])]);
        self::assertSame([], $this->verifier()->verify($cart)['corrections']);
        self::assertCount(1, $this->apiCalls);
        self::assertSame('BB', $this->apiCalls[0]['board_id']);
    }

    /**
     * The line's own entry (the hook writes it after a correction) is made
     * of every room: never one room's key, never the top-level tuple's.
     */
    public function testAMultiRoomLineKeyIsNeitherARoomKeyNorTheTopLevelTuple(): void
    {
        $extra = $this->multiRoomCart(1470.0)['products'][42]['extra'];
        $lines = PreOrderPriceVerifier::roomLines($extra);
        $topLevel = $extra;
        unset($topLevel['rooms_data']);

        $key = PreOrderPriceVerifier::priceCacheKey($extra);
        self::assertNotSame(PreOrderPriceVerifier::priceCacheKey($lines[0]), $key);
        self::assertNotSame(PreOrderPriceVerifier::priceCacheKey($topLevel), $key);

        $_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY] = [
            $key => ['api_price' => 1470.0, 'api_price_raw' => 1470.0, 'timestamp' => time()],
        ];
        self::assertSame([], $this->verifier()->verify($this->multiRoomCart(1470.0))['corrections']);
        self::assertSame([], $this->apiCalls);
    }

    /**
     * A one-room line as add_to_cart writes it (top-level tuple = rooms_data[0])
     * keeps the Silent Sync key it always had.
     */
    public function testAOneRoomLineKeepsItsKey(): void
    {
        $extra = $this->cart(795.0, self::BEACH)['products'][42]['extra'];
        $withRooms = $extra + ['rooms_data' => [
            ['room_id' => self::ROOM, 'board_id' => self::UAI, 'package_name' => self::BEACH, 'adults' => 2, 'children' => 0, 'childrenAges' => []],
        ]];

        self::assertCount(1, PreOrderPriceVerifier::roomLines($withRooms));
        self::assertSame(PreOrderPriceVerifier::priceCacheKey($extra), PreOrderPriceVerifier::priceCacheKey($withRooms));
    }

    /**
     * A line written before the C2 fix: top-level the 600 early-booking
     * package, rooms_data[0] (what the reservation books) the 795 one. The
     * check prices the booked room, not the top-level tuple.
     */
    public function testALegacyOneRoomLineIsRepricedOnTheRoomItBooks(): void
    {
        $cart = $this->cart(600.0, self::BEACH_EB);
        $cart['products'][42]['extra']['rooms_data'] = json_encode([
            ['room_id' => self::ROOM, 'board_id' => self::UAI, 'package_name' => self::BEACH, 'adults' => 2],
        ]);
        // The top-level tuple's Silent Sync entry is not the booked room's.
        $topLevel = $cart['products'][42]['extra'];
        unset($topLevel['rooms_data']);
        $_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY] = [
            PreOrderPriceVerifier::priceCacheKey($topLevel) => ['api_price' => 600.0, 'api_price_raw' => 600.0, 'timestamp' => time()],
        ];

        $result = $this->verifier()->verify($cart);

        self::assertCount(1, $this->apiCalls);
        self::assertSame(795.0, $result['corrections']['42']['api_price'] ?? null);
        self::assertTrue($result['reconfirm']);
    }

    /**
     * Edit Booking moved a child from 1 to 14 after add_to_cart priced the
     * infant (update_booking writes the guests to the booking row). The
     * reservation sends the guest's age, so the check prices the 14-year-old
     * — never the add_to_cart Silent Sync entry of the infant.
     */
    public function testAChildAgeChangedOnTheBookingIsRepricedAtTheBookedAge(): void
    {
        $cart = $this->childCart(1);
        $_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY] = [
            PreOrderPriceVerifier::priceCacheKey($cart['products'][42]['extra']) => ['api_price' => 795.0, 'api_price_raw' => 795.0, 'timestamp' => time()],
        ];
        $this->bookingRows[9] = ['booking_id' => 9, 'guests_data' => (string) json_encode(self::childGuests(14))];

        $result = $this->verifier()->verify($cart);

        self::assertSame([[14]], array_column($this->apiCalls, 'children'));
        self::assertSame(995.0, $result['corrections']['42']['api_price'] ?? null);
        self::assertTrue($result['reconfirm']);
    }

    /** The same when the new age only reached the cart line (no row). */
    public function testAChildAgeChangedOnTheCartLineIsRepricedAtTheBookedAge(): void
    {
        $cart = $this->childCart(1);
        $cart['products'][42]['extra']['guests_data'] = (string) json_encode(self::childGuests(14));

        $result = $this->verifier()->verify($cart);

        self::assertSame([[14]], array_column($this->apiCalls, 'children'));
        self::assertSame(995.0, $result['corrections']['42']['api_price'] ?? null);
    }

    /** Unchanged guests: add_to_cart's Silent Sync entry answers. */
    public function testAnUnchangedChildLineUsesItsSilentSyncEntry(): void
    {
        $cart = $this->childCart(1);
        $this->bookingRows[9] = ['booking_id' => 9, 'guests_data' => (string) json_encode(self::childGuests(1))];
        $_SESSION[PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY] = [
            PreOrderPriceVerifier::priceCacheKey($cart['products'][42]['extra']) => ['api_price' => 795.0, 'api_price_raw' => 795.0, 'timestamp' => time()],
        ];

        self::assertSame([], $this->verifier()->verify($cart)['corrections']);
        self::assertSame([], $this->apiCalls);
    }

    /**
     * One room, two adults and a child of $age, as add_to_cart writes it
     * (rooms_data, guests_data and the top-level tuple agree), at 795.
     *
     * @return array{products: array<int, array<string, mixed>>}
     */
    private function childCart(int $age): array
    {
        $cart = $this->cart(795.0, self::BEACH);
        $cart['products'][42]['extra'] = [
            'children' => 1,
            'children_ages' => (string) $age,
            'rooms_data' => [
                ['room_id' => self::ROOM, 'board_id' => self::UAI, 'package_name' => self::BEACH, 'adults' => 2, 'children' => 1, 'childrenAges' => [$age], 'price' => 795.0],
            ],
            'guests_data' => (string) json_encode(self::childGuests($age)),
        ] + $cart['products'][42]['extra'];

        return $cart;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function childGuests(int $age): array
    {
        return [
            'room1_adult_1' => ['name' => 'Ana Pop', 'type' => 'adult', 'age' => 40, 'room' => 1],
            'room1_adult_2' => ['name' => 'Ion Pop', 'type' => 'adult', 'age' => 41, 'room' => 1],
            'room1_child_1' => ['name' => 'Mara Pop', 'type' => 'child', 'age' => $age, 'room' => 1],
        ];
    }

    /**
     * Room 1: the "+BEACH" UAI double at 795; room 2: the BB/HB package's BB
     * double at 675, with a child of 7.
     *
     * @return array{products: array<int, array<string, mixed>>}
     */
    private function multiRoomCart(float $total): array
    {
        $cart = $this->cart($total, self::BEACH);
        $cart['products'][42]['extra'] = [
            'adults' => 4,
            'children' => 1,
            'children_ages' => '7',
            'num_rooms' => 2,
            'rooms_data' => [
                ['room_id' => self::ROOM, 'board_id' => self::UAI, 'package_name' => self::BEACH, 'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 795.0],
                ['room_id' => self::ROOM, 'board_id' => 'BB', 'package_name' => 'ADMIRAL ***** BB/HB [STAY 22.09 - 19.12]', 'adults' => 2, 'children' => 1, 'childrenAges' => [7], 'price' => 675.0],
            ],
        ] + $cart['products'][42]['extra'];

        return $cart;
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
                $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
                // A child of 12 or more costs 200 more in the "+BEACH" offer.
                $ages = is_array($params['children'] ?? null) ? $params['children'] : [];
                if ($ages !== [] && max($ages) >= 12) {
                    $xml = str_replace('<Price>795.00</Price>', '<Price>995.00</Price>', $xml);
                }

                return simplexml_load_string($xml);
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
            fn (int $bookingId): ?array => $this->bookingRows[$bookingId] ?? null,
        );
    }
}
