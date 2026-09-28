<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;

#[CoversClass(SearchOfferNormalizer::class)]
class SearchOfferNormalizerTest extends TestCase
{
    /**
     * The nested shape returned by the live /hotels/search results endpoint.
     *
     * @return array<string, mixed>
     */
    private function nestedOffer(): array
    {
        return [
            'offer_id' => 'abc-123',
            'hotel_id' => 59833,
            'hotel_name' => 'Hotel Royal Ariston',
            'destination_name' => 'Dubrovnik',
            'pricing' => [
                'selling_price' => 842.5,
                'currency' => 'EUR',
            ],
            'rooms' => [
                ['room_name' => 'Double Room Sea View'],
            ],
            'meal_type_name' => 'Half Board',
        ];
    }

    public function testFlattenMapsNestedApiShapeToFlatTemplateKeys(): void
    {
        $flat = SearchOfferNormalizer::flatten($this->nestedOffer());

        $this->assertSame('abc-123', $flat['offer_id']);
        $this->assertSame('59833', $flat['hotel_id']);
        $this->assertSame('Hotel Royal Ariston', $flat['hotel_name']);
        $this->assertSame('Dubrovnik', $flat['destination']);
        $this->assertSame(842.5, $flat['price']);
        $this->assertSame('EUR', $flat['currency']);
        $this->assertSame('Double Room Sea View', $flat['room_name']);
        $this->assertSame('Half Board', $flat['board_name']);
    }

    public function testFlattenReadsRoomTypeAndNameFallbacks(): void
    {
        $offer = $this->nestedOffer();
        $offer['rooms'] = [['room_type' => 'Triple Standard']];
        $this->assertSame('Triple Standard', SearchOfferNormalizer::flatten($offer)['room_name']);

        $offer['rooms'] = [['name' => 'Studio Apartment']];
        $this->assertSame('Studio Apartment', SearchOfferNormalizer::flatten($offer)['room_name']);
    }

    public function testFlattenIsIdempotentOnAlreadyFlatOffers(): void
    {
        $flat = [
            'offer_id' => 'x',
            'hotel_id' => '42',
            'hotel_name' => 'Flat Hotel',
            'destination' => 'Paris',
            'price' => 100.0,
            'currency' => 'EUR',
            'room_name' => 'Suite',
            'board_name' => 'All Inclusive',
        ];

        $this->assertSame($flat, SearchOfferNormalizer::flatten($flat));
    }

    public function testFlattenHandlesMissingPricingGracefully(): void
    {
        $flat = SearchOfferNormalizer::flatten([
            'offer_id' => 'no-price',
            'hotel_id' => 7,
            'hotel_name' => 'Budget Inn',
        ]);

        $this->assertSame(0.0, $flat['price']);
        $this->assertSame('', $flat['currency']);
        $this->assertSame('', $flat['room_name']);
        $this->assertSame('', $flat['board_name']);
    }

    public function testFlattenFallsBackToIdAndNameKeys(): void
    {
        $flat = SearchOfferNormalizer::flatten([
            'offer_id' => 'o1',
            'id' => 999,
            'name' => 'Aliased Hotel',
            'pricing' => ['selling_price' => 50, 'currency' => 'USD'],
        ]);

        $this->assertSame('999', $flat['hotel_id']);
        $this->assertSame('Aliased Hotel', $flat['hotel_name']);
    }

    public function testFlattenPreservesConfirmationField(): void
    {
        // The availability filter relies on confirmation surviving flatten().
        $offer = $this->nestedOffer();
        $offer['confirmation'] = 'immediate';

        $this->assertSame('immediate', SearchOfferNormalizer::flatten($offer)['confirmation']);
    }

    public function testFlattenAllProcessesEveryOffer(): void
    {
        $result = SearchOfferNormalizer::flattenAll([
            $this->nestedOffer(),
            $this->nestedOffer(),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(842.5, $result[0]['price']);
        $this->assertSame(842.5, $result[1]['price']);
    }

    /**
     * Real production payload shape from the live /hotels/results endpoint:
     * TOP-LEVEL selling_price and meal_type (no nested pricing, no
     * meal_type_name). Regression: these rendered as 0,00 price and a blank
     * board because only the nested/legacy keys were read.
     */
    public function testFlattenReadsLiveFlatPayloadShape(): void
    {
        $flat = SearchOfferNormalizer::flatten([
            'hotel_id' => 61992,
            'hotel_name' => 'Ozkaymak Falez Otel',
            'offer_id' => 'e302a15f-ad04-4d57-b7fd-90255cf2393f',
            'confirmation' => 'immediate',
            'selling_price' => 987,
            'currency' => 'EUR',
            'meal_type' => 'ULTRA ALL INCLUSIVE',
            'check_in' => '2026-07-14',
            'check_out' => '2026-07-21',
        ]);

        $this->assertSame(987.0, $flat['price']);
        $this->assertSame('EUR', $flat['currency']);
        $this->assertSame('ULTRA ALL INCLUSIVE', $flat['board_name']);
        $this->assertSame('61992', $flat['hotel_id']);
        $this->assertSame('immediate', $flat['confirmation']);
    }

    public function testEveryRoomOfAMultiRoomOfferBecomesARoomLine(): void
    {
        $offer = $this->nestedOffer();
        $offer['rooms'] = [
            ['name' => 'Double Sea View', 'adults' => 2, 'children_ages' => [7]],
            ['room_name' => 'Triple Room', 'adults' => 3, 'children_ages' => []],
        ];

        $flat = SearchOfferNormalizer::flatten($offer);

        self::assertSame([
            ['name' => 'Double Sea View', 'adults' => 2, 'children' => 1],
            ['name' => 'Triple Room', 'adults' => 3, 'children' => 0],
        ], $flat['room_lines']);
        self::assertSame('Double Sea View', $flat['room_name']);
    }

    public function testAnOfferWithoutRoomsHasNoRoomLines(): void
    {
        $offer = $this->nestedOffer();
        unset($offer['rooms']);

        self::assertArrayNotHasKey('room_lines', SearchOfferNormalizer::flatten($offer));
    }
}
