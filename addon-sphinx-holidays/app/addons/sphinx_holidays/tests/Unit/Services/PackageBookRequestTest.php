<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\OfferBookBody;
use Tygh\Addons\SphinxHolidays\Services\PackageBookRequest;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * packages/book as the doc writes it (Documentation/Sphinx API.txt, "Book
 * offer" under Packages): offer_id, reference_code, price, currency,
 * occupancy [{room_code, guests [{first_name, last_name, birth_date,
 * gender}]}], additional_services. The package line used to send the hotel
 * body: {offer_id, guests map, contact}.
 */
#[CoversClass(PackageBookRequest::class)]
#[CoversClass(OfferBookBody::class)]
final class PackageBookRequestTest extends TestCase
{
    /**
     * A row as package add-to-cart stores it: two rooms, the first with a
     * child, the services chosen kept with the pricing.
     *
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return [
            'booking_id' => 41,
            'offer_id' => '008f4f48-dda7-4b23-9687-6cdfdd81b520',
            'check_in' => '2027-07-22',
            'currency' => 'EUR',
            'base_price' => 4000.0,
            'pricing_json' => json_encode(['selling_price' => 4000.0, 'currency' => 'EUR', 'chosen_services' => ['456']]),
            'rooms_data' => json_encode([
                ['code' => '75', 'name' => 'Twin Room with Sea View', 'adults' => 2, 'children_ages' => [5], 'room_id' => '75', 'room_code' => '75'],
                ['code' => 'UA-4514', 'name' => 'Deluxe Apartment', 'adults' => 1, 'children_ages' => [], 'room_id' => 'UA-4514', 'room_code' => 'UA-4514'],
            ]),
            // The form's order is kept per room: adults, then children.
            'guests_data' => json_encode([
                'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Doe', 'type' => 'child', 'birthday' => '2022-03-04', 'dob' => '04/03/2022', 'room' => 1, 'gender' => 'f'],
                'room1_adult_2' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'type' => 'adult', 'birthday' => '1988-01-01', 'room' => 1, 'gender' => 'f'],
                'room1_adult_1' => ['first_name' => 'John', 'last_name' => 'Doe', 'type' => 'adult', 'birthday' => '1987-05-06', 'room' => 1, 'gender' => 'm', 'is_holder' => 1],
                'room2_adult_1' => ['first_name' => 'Ion', 'last_name' => 'Pop', 'type' => 'adult', 'birthday' => '', 'room' => 2, 'gender' => 'm'],
            ]),
        ];
    }

    public function testTheBodyIsTheDocumentedOne(): void
    {
        $quote = [
            'hotel' => ['rooms' => [['code' => '75', 'adults' => 2, 'children_ages' => [5]], ['code' => 'UA-4514', 'adults' => 1, 'children_ages' => []]]],
            'pricing' => ['selling_price' => 4012.5, 'currency' => 'EUR', 'commission' => 0],
        ];

        self::assertSame([
            'offer_id' => '008f4f48-dda7-4b23-9687-6cdfdd81b520',
            'reference_code' => '501',
            // Sphinx's own selling price now, before the store's commission.
            'price' => 4012.5,
            'currency' => 'EUR',
            'occupancy' => [
                ['room_code' => '75', 'guests' => [
                    ['first_name' => 'John', 'last_name' => 'Doe', 'birth_date' => '1987-05-06', 'gender' => 'm'],
                    ['first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1988-01-01', 'gender' => 'f'],
                    ['first_name' => 'Mia', 'last_name' => 'Doe', 'birth_date' => '2022-03-04', 'gender' => 'f'],
                ]],
                // An adult without a birth date: the doc's 30 years old at check-in.
                ['room_code' => 'UA-4514', 'guests' => [
                    ['first_name' => 'Ion', 'last_name' => 'Pop', 'birth_date' => '1997-07-22', 'gender' => 'm'],
                ]],
            ],
            'additional_services' => ['456'],
        ], PackageBookRequest::build(self::row(), $quote, 501));
    }

    public function testWithoutAQuoteTheStoredPriceAndRoomsAreSent(): void
    {
        $body = PackageBookRequest::build(self::row(), null, 501);

        self::assertSame(4000.0, $body['price']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame(['75', 'UA-4514'], array_column($body['occupancy'], 'room_code'));
    }

    public function testARowStoredBeforeFallsBackToItsBasePriceAndCurrency(): void
    {
        $row = ['offer_id' => 'O-1', 'base_price' => 879.0, 'currency' => 'EUR', 'check_in' => '2027-07-22', 'guests_data' => [
            'room1_adult_1' => ['name' => 'Pop, Ana', 'api_name' => '', 'type' => 'adult', 'room' => 1],
            'room1_adult_2' => ['name' => 'Ion Maria Pop', 'type' => 'adult', 'room' => 1],
        ]];

        $body = PackageBookRequest::build($row, ['pricing' => ['selling_price' => 0]], 0);

        self::assertArrayNotHasKey('reference_code', $body, 'no order, no reference');
        self::assertSame(879.0, $body['price']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame([], $body['additional_services']);
        // No rooms stored: the guests' own room, without a code.
        self::assertSame([['room_code' => '', 'guests' => [
            // No gender stored: none is sent (Sphinx says what is missing).
            ['first_name' => 'Ana', 'last_name' => 'Pop', 'birth_date' => '1997-07-22'],
            ['first_name' => 'Ion Maria', 'last_name' => 'Pop', 'birth_date' => '1997-07-22'],
        ]]], $body['occupancy']);
    }

    /** A child's birth date is checked against the priced age: it is never made up. */
    public function testAChildWithoutABirthDateGetsNone(): void
    {
        $row = ['offer_id' => 'O-2', 'check_in' => '2027-07-22', 'guests_data' => json_encode([
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'room' => 1, 'gender' => 'f'],
            'room1_adult_1' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'dob' => '04/03/1990', 'room' => 1, 'gender' => 'f'],
        ])];

        $guests = PackageBookRequest::build($row, null, 7)['occupancy'][0]['guests'];

        self::assertSame(['1990-03-04', ''], array_column($guests, 'birth_date'), 'an adult\'s DD/MM/YYYY read; the child\'s left empty');
    }

    public function testTheChosenServicesAreTheStoredOnes(): void
    {
        self::assertSame(['456'], PackageBookRequest::chosenServices(self::row()));
        self::assertSame([], PackageBookRequest::chosenServices(['pricing_json' => '{"selling_price":1}']));
    }

    // ── quote(): the offer as Sphinx prices it now ─────────────────────────

    /**
     * @param array<string, array<string, mixed>|null|\Throwable> $answers method => answer
     * @param list<string> $calls filled with the calls made
     *
     * @return SphinxApi answering each method with its answer
     */
    private static function api(array $answers, array &$calls): SphinxApi
    {
        return new class ($answers, $calls) extends SphinxApi {
            /**
             * @param array<string, array<string, mixed>|null|\Throwable> $answers method => answer
             * @param list<string> $calls filled with the calls made
             */
            public function __construct(private array $answers, private array &$calls)
            {
            }

            public function verifyPackageOffer(string $offerId): ?array
            {
                return $this->answer('verifyPackageOffer');
            }

            public function customizePackage(array $data): ?array
            {
                $this->calls[] = 'customizePackage:' . implode(',', (array) ($data['service_codes'] ?? []));

                return $this->answer('customizePackage');
            }

            /**
             * @param string $method the method answering
             *
             * @return array<string, mixed>|null its answer
             */
            private function answer(string $method): ?array
            {
                if ($method === 'verifyPackageOffer') {
                    $this->calls[] = $method;
                }
                $answer = $this->answers[$method] ?? null;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
    }

    public function testTheQuoteIsTheVerifiedOfferWithoutServices(): void
    {
        $calls = [];
        $verified = ['pricing' => ['selling_price' => 879.0]];

        self::assertSame($verified, PackageBookRequest::quote(self::api(['verifyPackageOffer' => $verified], $calls), 'O-1', []));
        self::assertSame(['verifyPackageOffer'], $calls);
    }

    public function testTheQuoteIsTheCustomizedOfferWithServices(): void
    {
        $calls = [];
        $customized = ['pricing' => ['selling_price' => 969.0]];

        self::assertSame($customized, PackageBookRequest::quote(self::api(['verifyPackageOffer' => ['pricing' => []], 'customizePackage' => $customized], $calls), 'O-1', ['456']));
        self::assertSame(['verifyPackageOffer', 'customizePackage:456'], $calls, 'verified first, as the doc orders it');
    }

    /** The verified price would leave the chosen services out: no quote then, the stored price stands. */
    public function testNoQuoteWhenTheServicesCannotBePriced(): void
    {
        $calls = [];

        self::assertNull(PackageBookRequest::quote(self::api(['verifyPackageOffer' => ['pricing' => []], 'customizePackage' => null], $calls), 'O-1', ['456']));
        self::assertNull(PackageBookRequest::quote(self::api(['verifyPackageOffer' => null], $calls), 'O-1', []));
        self::assertNull(PackageBookRequest::quote(self::api(['verifyPackageOffer' => new \RuntimeException('down')], $calls), 'O-1', []));
    }
}
