<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitBookRequest;
use Tygh\Addons\SphinxHolidays\Services\OfferBookBody;
use Tygh\Addons\SphinxHolidays\SphinxApi;

/**
 * circuits/book as the doc writes it (Documentation/Sphinx API.txt, "Book
 * offer" under Circuits): offer_id, reference_code, price, currency,
 * occupancy [{room_code, guests [{first_name, last_name, birth_date,
 * gender}]}] — no additional_services: customize puts them on the offer.
 * The circuit line used to send the hotel body: {offer_id, guests map,
 * contact}.
 */
#[CoversClass(CircuitBookRequest::class)]
#[CoversClass(OfferBookBody::class)]
final class CircuitBookRequestTest extends TestCase
{
    /**
     * A row as circuit add-to-cart stores it: one room with a child, the
     * quote's price and the services chosen kept with the pricing.
     *
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return [
            'booking_id' => 51,
            'offer_id' => '11f51685-0551-4246-ab3d-fbc79b3cbbef',
            'room_type' => 'circuit',
            'check_in' => '2027-07-05',
            'currency' => 'EUR',
            'base_price' => 1017.0,
            'pricing_json' => json_encode(['selling_price' => 1017.0, 'currency' => 'EUR', 'chosen_services' => ['456']]),
            'rooms_data' => json_encode([
                ['room_id' => 'standard-double', 'room_name' => 'Standard - Double', 'adults' => 2, 'children' => 1, 'childrenAges' => [8]],
            ]),
            'guests_data' => json_encode([
                'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Doe', 'type' => 'child', 'birthday' => '2019-03-04', 'room' => 1, 'gender' => 'f'],
                'room1_adult_2' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'type' => 'adult', 'birthday' => '1988-01-01', 'room' => 1, 'gender' => 'f'],
                'room1_adult_1' => ['first_name' => 'John', 'last_name' => 'Doe', 'type' => 'adult', 'birthday' => '', 'room' => 1, 'gender' => 'm', 'is_holder' => 1],
            ]),
        ];
    }

    public function testTheBodyIsTheDocumentedOne(): void
    {
        $customized = [
            'offer_id' => '11f51685-0551-4246-ab3d-fbc79b3cbbef',
            'rooms' => [['code' => 'standard-double', 'name' => 'Standard - Double', 'adults' => 2, 'children_ages' => [8]]],
            'pricing' => ['selling_price' => 1120.0, 'currency' => 'EUR'],
        ];

        self::assertSame([
            'offer_id' => '11f51685-0551-4246-ab3d-fbc79b3cbbef',
            'reference_code' => '601',
            // The offer with its services, as Sphinx prices it now.
            'price' => 1120.0,
            'currency' => 'EUR',
            'occupancy' => [
                ['room_code' => 'standard-double', 'guests' => [
                    // An adult without a birth date: the doc's 30 years old at departure.
                    ['first_name' => 'John', 'last_name' => 'Doe', 'birth_date' => '1997-07-05', 'gender' => 'm'],
                    ['first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1988-01-01', 'gender' => 'f'],
                    ['first_name' => 'Mia', 'last_name' => 'Doe', 'birth_date' => '2019-03-04', 'gender' => 'f'],
                ]],
            ],
        ], CircuitBookRequest::build(self::row(), $customized, 601));
    }

    public function testWithoutAQuoteTheStoredPriceAndRoomsAreSent(): void
    {
        $body = CircuitBookRequest::build(self::row(), null, 601);

        self::assertSame(1017.0, $body['price']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame(['standard-double'], array_column($body['occupancy'], 'room_code'));
        self::assertArrayNotHasKey('additional_services', $body, 'circuits/book has none: the services are on the offer');
    }

    /** A row stored before: no pricing_json (no services were chosen), so its base price; a non-traveller entry is skipped. */
    public function testARowStoredBeforeFallsBackToItsBasePriceAndCurrency(): void
    {
        $row = ['offer_id' => 'C-1', 'base_price' => 927.0, 'currency' => 'EUR', 'check_in' => '2027-07-05',
            'rooms_data' => [['room_id' => 'standard-double', 'room_name' => 'Standard - Double', 'adults' => 1]],
            'guests_data' => ['holder' => 'Ana Pop', 'room1_adult_1' => ['name' => 'Pop, Ana', 'type' => 'adult', 'room' => 1]]];

        $body = CircuitBookRequest::build($row, null, 0);

        self::assertArrayNotHasKey('reference_code', $body, 'no order, no reference');
        self::assertSame(927.0, $body['price']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame([['room_code' => 'standard-double', 'guests' => [
            ['first_name' => 'Ana', 'last_name' => 'Pop', 'birth_date' => '1997-07-05'],
        ]]], $body['occupancy']);
    }

    public function testTheChosenServicesAreTheStoredOnes(): void
    {
        self::assertSame(['456'], CircuitBookRequest::chosenServices(self::row()));
        self::assertSame([], CircuitBookRequest::chosenServices(['pricing_json' => '{"selling_price":1}']));
    }

    // ── quote(): the offer with its services, as Sphinx prices it now ──────

    /**
     * @param array<string, mixed>|null|\Throwable $answer what customizeCircuit() answers
     * @param list<string> $calls filled with the calls made
     *
     * @return SphinxApi answering customizeCircuit() with $answer
     */
    private static function api(array|\Throwable|null $answer, array &$calls): SphinxApi
    {
        return new class ($answer, $calls) extends SphinxApi {
            /**
             * @param array<string, mixed>|null|\Throwable $answer what customizeCircuit() answers
             * @param list<string> $calls filled with the calls made
             */
            public function __construct(private array|\Throwable|null $answer, private array &$calls)
            {
            }

            public function customizeCircuit(array $data): ?array
            {
                $this->calls[] = 'customizeCircuit:' . ($data['offer_id'] ?? '') . ':' . implode(',', (array) ($data['service_codes'] ?? []));
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }

            public function getCircuitQuote(array $params): array
            {
                $this->calls[] = 'getCircuitQuote';

                return [];
            }
        };
    }

    /** Without services there is nothing to re-price: a new quote would be a new offer. */
    public function testWithoutServicesNothingIsAsked(): void
    {
        $calls = [];

        self::assertNull(CircuitBookRequest::quote(self::api(['pricing' => ['selling_price' => 1.0]], $calls), 'C-1', []));
        self::assertSame([], $calls);
    }

    public function testWithServicesTheOfferIsCustomizedAgain(): void
    {
        $calls = [];
        $customized = ['pricing' => ['selling_price' => 1120.0]];

        self::assertSame($customized, CircuitBookRequest::quote(self::api($customized, $calls), 'C-1', ['456']));
        self::assertSame(['customizeCircuit:C-1:456'], $calls);
    }

    public function testNoQuoteWhenCustomizeCannotBeAsked(): void
    {
        $calls = [];

        self::assertNull(CircuitBookRequest::quote(self::api(null, $calls), 'C-1', ['456']));
        self::assertNull(CircuitBookRequest::quote(self::api([], $calls), 'C-1', ['456']));
        self::assertNull(CircuitBookRequest::quote(self::api(new \RuntimeException('down'), $calls), 'C-1', ['456']));
    }
}
