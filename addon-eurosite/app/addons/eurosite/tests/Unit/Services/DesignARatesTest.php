<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\Eurosite\Services\DesignARates;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * Eurosite's offers for one hotel → the Design A room cards' rates
 * (board 13b), for a one-room search: one room at one meal plan per offer,
 * the cart line's price, the reduction with the offer's own text, IM / OR,
 * stop sale left out, the terms loaded when opened.
 */
final class DesignARatesTest extends TestCase
{
    private static function mapper(float $coefficient = 1.0): DesignARates
    {
        return new DesignARates(
            static fn (string $currency): float => $currency === 'EUR' ? $coefficient : 0.0,
            static fn (string $url): string => '/index.php?dispatch=' . $url,
            'Avans 30% la rezervare',
            292,
            'Fără masă',
        );
    }

    /**
     * @param list<array<string, string>> $meals
     * @param array<string, mixed> $more
     */
    private static function offer(string $code, string $room, array $meals, float $price, array $more = []): HotelOffer
    {
        return new HotelOffer(...$more + [
            'productCode' => 'RO0099',
            'productName' => 'Condor',
            'countryCode' => 'RO',
            'cityCode' => 'ROMM',
            'cityName' => 'Mamaia',
            'category' => 4,
            'class' => 'Hotel',
            'firstImage' => '',
            'latitude' => '0',
            'longitude' => '0',
            'currency' => 'EUR',
            'offerType' => 'Normal',
            'availability' => 'Immediate',
            'checkIn' => '2027-07-10',
            'checkOut' => '2027-07-17',
            'price' => $price,
            'gross' => $price,
            'net' => round($price * 0.9, 2),
            'commission' => round($price * 0.1, 2),
            'variantId' => '0|' . $code . '_' . (int) $price,
            'grila' => 'Standard',
            'rooms' => [['code' => $code, 'gcode' => 'DB', 'name' => $room, 'quantity' => '1', 'price' => '0']],
            'meals' => $meals,
            'availabilityCode' => 'IM',
        ]);
    }

    /** @return list<array{offer: HotelOffer, key: string}> */
    private static function condor(): array
    {
        $bb = [['type' => '2', 'code' => '11', 'name' => 'Mic dejun', 'price' => '0']];
        $hb = [['type' => '2', 'code' => '12', 'name' => 'Demipensiune', 'price' => '0']];

        return [
            ['offer' => self::offer('40', 'Double Room', $hb, 640.0), 'key' => 'aa01'],
            ['offer' => self::offer('40', 'Double Room', $bb, 520.0, [
                'gross' => 560.0, 'priceNoRedd' => 640.0, 'offerDescription' => 'Reducere Oferta Speciala 12% pana la 31.12.2026',
            ]), 'key' => 'aa02'],
            ['offer' => self::offer('41', 'Family Room Sea View', $bb, 790.0, ['availabilityCode' => 'OR', 'availability' => 'OnRequest']), 'key' => 'aa03'],
            // Stop sale: shown for information only, never bookable.
            ['offer' => self::offer('42', 'Suite', $bb, 990.0, ['availabilityCode' => 'ST']), 'key' => 'aa04'],
            // Past the snapshot's 200 offers: no key, no booking.
            ['offer' => self::offer('43', 'Studio', $bb, 450.0), 'key' => ''],
        ];
    }

    public function testEachBookableOfferIsOneRoomAtOneMealPlan(): void
    {
        $rates = self::mapper()->rates(self::condor());

        self::assertCount(3, $rates, 'stop sale and keyless offers are left out');
        [$hb, $bb, $family] = $rates;
        self::assertSame($hb->roomKey, $bb->roomKey, 'one card for the room, its meal plans as options');
        self::assertNotSame($bb->roomKey, $family->roomKey);
        self::assertSame(['Double Room', 'Double Room', '', ''], [$bb->roomName, $bb->roomTitle ?: $bb->roomName, $bb->roomCode, $bb->packageName]);
        self::assertSame(['Mic dejun', RateOffer::MEAL_BREAKFAST], [$bb->mealPlan, $bb->mealRank]);
        self::assertSame(['Demipensiune', RateOffer::MEAL_HALF_BOARD], [$hb->mealPlan, $hb->mealRank]);

        self::assertSame(520.0, $bb->price);
        self::assertSame(594.29, $bb->standardPrice, 'PriceNoRedd on the price scale: 520 × 640 / 560');
        self::assertSame('Reducere Oferta Speciala 12% pana la 31.12.2026', $bb->discountLabel);
        self::assertSame([0.0, ''], [$hb->standardPrice, $hb->discountLabel]);

        self::assertFalse($bb->onRequest);
        self::assertTrue($family->onRequest, 'OR = on request');
        self::assertSame('Avans 30% la rezervare', $bb->paymentText);
        self::assertSame('/index.php?dispatch=eurosite_booking.booking_form?offer_key=aa02&return_product_id=292', $bb->bookUrl);
        self::assertSame('/index.php?dispatch=eurosite_booking.offer_terms?offer_key=aa02&design=a', $bb->termsUrl);
        self::assertSame(['', false, false], [$bb->freeCancellationUntil, $bb->freeCancellation, $bb->nonRefundable], 'no terms in the search');
    }

    public function testPricesAreOnTheCartLinesScale(): void
    {
        $rates = self::mapper(4.97)->rates(self::condor());

        self::assertSame(2584.4, $rates[1]->price, '520 EUR × 4.97, as add_to_cart charges it');
        self::assertSame(2953.62, $rates[1]->standardPrice, '594.29 × 4.97');
    }

    public function testAnOfferWithoutAMealIsRoomOnly(): void
    {
        $rates = self::mapper()->rates([['offer' => self::offer('40', 'Double Room', [], 400.0), 'key' => 'bb01']]);

        self::assertSame(['Fără masă', RateOffer::MEAL_ROOM_ONLY], [$rates[0]->mealPlan, $rates[0]->mealRank]);
    }

    public function testTheBuilderMakesOneCardPerRoom(): void
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);
        $view = (new RoomResultsBuilder($money, static fn (string $iso): string => $iso))->build(self::mapper()->rates(self::condor()), 7);

        self::assertSame(2, $view['room_count']);
        self::assertSame(['Mic dejun', 'Demipensiune'], array_column($view['meal_plans'], 'name'));
        $double = $view['rooms'][0];
        self::assertSame(['Double Room', 'Double Room'], [$double['name'], $double['title']]);
        self::assertSame('', $double['code'], 'the hotel\'s internal room number is not shown');
        self::assertSame(['Mic dejun', 'Demipensiune'], array_column($double['options'], 'meal'));
        self::assertSame([true, false], array_column($double['options'], 'chosen'), 'the cheapest chosen');
        self::assertSame('−13%', $double['options'][0]['off'], '520 against 594.29');
        self::assertSame(['on_request' => false, 'deposit_percent' => 0, 'balance_due' => '', 'text' => 'Avans 30% la rezervare'], $double['payment']);
        self::assertSame([0, 1], array_column($double['terms'], 'for'), 'each option loads its own terms');
        self::assertTrue($view['rooms'][1]['on_request']);
    }

    public function testTheTermsTheCardLoadsAreWordedAsTheSidebar(): void
    {
        $texts = [
            'cancel' => 'Condiții de anulare',
            'payment' => 'Termeni de plată',
            'fee_line' => 'Între [from] - [to]: penalizare [value]',
            'unavailable' => 'Condițiile de anulare vor fi confirmate la rezervare.',
        ];
        $fees = [
            ['type' => 'cancel', 'from_date' => '2027-06-01', 'to_date' => '2027-06-30', 'value' => 30.0, 'is_percent' => true],
            ['type' => 'cancel', 'from_date' => '2027-07-01', 'to_date' => '2027-07-10', 'value' => 100.0, 'is_percent' => true],
        ];

        self::assertSame([
            ['title' => 'Condiții de anulare', 'lines' => ['Între 01.06.2027 - 30.06.2027: penalizare 30%', 'Între 01.07.2027 - 10.07.2027: penalizare 100%']],
            ['title' => 'Termeni de plată', 'lines' => ['Avans 30% la rezervare', 'Diferența de 70% cu 30 de zile înainte']],
        ], DesignARates::termsSections($fees, ['Avans 30% la rezervare', 'Diferența de 70% cu 30 de zile înainte'], 'EUR', $texts, '%d.%m.%Y'));

        self::assertSame(
            [['title' => 'Condiții de anulare', 'lines' => ['Condițiile de anulare vor fi confirmate la rezervare.']]],
            DesignARates::termsSections([], [], 'EUR', $texts, '%d.%m.%Y'),
            'no fees from Eurosite: confirmed at booking',
        );
    }

    public function testThePaymentLineIsTheAdminTextsFirstLine(): void
    {
        self::assertSame('Avans 30% la rezervare', DesignARates::firstLine("\n  Avans 30% la rezervare\nDiferența de 70% …"));
        self::assertSame('', DesignARates::firstLine("  \n"));
    }
}
