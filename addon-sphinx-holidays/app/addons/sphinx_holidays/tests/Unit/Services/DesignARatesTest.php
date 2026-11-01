<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\DesignARates;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * Sphinx's offers for one hotel → the Design A room cards' rates (board
 * 13b), for a one-room search: from the flat rows search.php and the poll
 * keep (commission applied), the marketing price struck, the labels naming
 * the discount, free cancellation without its day, on request, the booking
 * link as today's card; the terms the card loads when opened.
 */
final class DesignARatesTest extends TestCase
{
    private const SEARCH = [
        'hotel_id' => '1234', 'product_id' => 77, 'check_in' => '2027-07-10', 'check_out' => '2027-07-17',
        'adults' => 2, 'children' => 1, 'children_ages' => '7', 'rooms' => 1,
    ];

    private static function mapper(float $rate = 1.0): DesignARates
    {
        return new DesignARates(
            static fn (float $amount, string $currency): float => round($amount * ($currency === 'EUR' ? $rate : 0.2), 2),
            static fn (string $url): string => '/index.php?dispatch=' . $url,
            'Fără masă',
            self::SEARCH,
        );
    }

    /**
     * A raw search offer as the API sends it, flattened and commissioned as
     * search.php / search_poll.php do (+10% here).
     *
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private static function row(string $offerId, string $code, string $room, string $meal, float $selling, array $more = []): array
    {
        $offer = SearchOfferNormalizer::flatten($more + [
            'offer_id' => $offerId,
            'hotel_id' => '1234',
            'confirmation' => 'immediate',
            'pricing' => ['marketing_price' => $selling, 'discount' => 0, 'selling_price' => $selling, 'currency' => 'EUR'],
            'rooms' => [['code' => $code, 'name' => $room, 'adults' => 2, 'children_ages' => [7]]],
            'meal_type_name' => $meal,
            'labels' => [],
            'cancellation_fees' => ['is_loaded' => false, 'is_free' => false, 'text' => null, 'rules' => []],
        ]);
        $offer['price'] = round((float) $offer['price'] * 1.1, 2);
        if ((float) ($offer['marketing_price'] ?? 0) > 0) {
            $offer['marketing_price'] = round((float) $offer['marketing_price'] * 1.1, 2);
        }

        return $offer;
    }

    /** @return list<array<string, mixed>> */
    private static function offers(): array
    {
        return [
            self::row('o1', '75', 'Twin Room with Sea View', 'Half Board', 800.0, [
                'pricing' => ['marketing_price' => 1000.0, 'discount' => 200.0, 'selling_price' => 800.0, 'currency' => 'EUR'],
                'labels' => [['name' => 'Early Booking', 'description' => 'Book now and save 20%']],
                'cancellation_fees' => ['is_loaded' => false, 'is_free' => true, 'text' => null, 'rules' => []],
            ]),
            self::row('o2', '75', 'Twin Room with Sea View', 'ALL INCLUSIVE', 1000.0, ['confirmation' => 'on_request']),
            self::row('o3', '76', 'Double Room Superior', '', 700.0),
            // No price or no room: no card.
            self::row('o4', '77', 'Studio', 'Half Board', 0.0),
            self::row('', '78', 'Suite', 'Half Board', 900.0),
        ];
    }

    public function testTheSearchRowsCarryWhatTheCardsNeed(): void
    {
        $row = self::offers()[0];

        self::assertSame(['75', 1100.0, 'Early Booking', true], [$row['room_code'], $row['marketing_price'], $row['deal_label'], $row['free_cancellation']]);
        // A flat (cached, slimmed) row passes through as it is: its marketing price already carries the commission.
        self::assertSame($row['marketing_price'], SearchOfferNormalizer::flatten(array_intersect_key($row, array_flip([
            'offer_id', 'room_name', 'price', 'currency', 'room_code', 'marketing_price', 'deal_label', 'free_cancellation',
        ])))['marketing_price']);
    }

    public function testEachOfferIsOneRoomAtOneMealPlan(): void
    {
        $rates = self::mapper()->rates(self::offers());

        self::assertCount(3, $rates);
        [$hb, $ai, $double] = $rates;
        self::assertSame($hb->roomKey, $ai->roomKey, 'one card for the room, its meal plans as options');
        self::assertNotSame($hb->roomKey, $double->roomKey);
        self::assertSame(['Twin Room with Sea View', '', 0], [$hb->roomName, $hb->roomCode, $hb->capacity], 'the room code is a number: not shown');
        self::assertSame(['Half Board', RateOffer::MEAL_HALF_BOARD], [$hb->mealPlan, $hb->mealRank]);
        self::assertSame(['All Inclusive', RateOffer::MEAL_ALL_INCLUSIVE], [$ai->mealPlan, $ai->mealRank], 'all capitals read in title case');
        self::assertSame(['Fără masă', RateOffer::MEAL_ROOM_ONLY], [$double->mealPlan, $double->mealRank]);

        self::assertSame([880.0, 1100.0, 'Early Booking'], [$hb->price, $hb->standardPrice, $hb->discountLabel]);
        self::assertSame([0.0, ''], [$ai->standardPrice, $ai->discountLabel]);
        self::assertSame([true, '', false], [$hb->freeCancellation, $hb->freeCancellationUntil, $hb->nonRefundable], 'free: its last day comes with the terms');
        self::assertSame([false, true], [$hb->onRequest, $ai->onRequest]);

        self::assertStringStartsWith('/index.php?dispatch=sphinx_booking.booking_form?', $hb->bookUrl);
        parse_str(substr($hb->bookUrl, strlen('/index.php?dispatch=sphinx_booking.booking_form?')), $query);
        self::assertSame([
            'offer_id' => 'o1', 'hotel_id' => '1234', 'product_id' => '77', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17',
            'adults' => '2', 'children' => '1', 'children_ages' => '7', 'rooms' => '1',
            'room_name' => 'Twin Room with Sea View', 'board_name' => 'Half Board',
        ], $query, 'the booking form gets what today\'s card sends');
        self::assertSame('/index.php?dispatch=sphinx_booking.offer_terms?offer_id=o1&design=a', $hb->termsUrl);
    }

    public function testPricesAreInTheStoresCurrency(): void
    {
        $rates = self::mapper(4.97)->rates(self::offers());

        self::assertSame([4373.6, 5467.0], [$rates[0]->price, $rates[0]->standardPrice], 'EUR × 4.97, as the cart converts it');
    }

    public function testTheBuilderMakesOneCardPerRoom(): void
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);
        $view = (new RoomResultsBuilder($money, static fn (string $iso): string => $iso))->build(self::mapper()->rates(self::offers()), 7, 2, 1);

        self::assertSame(['Double Room Superior', 'Twin Room with Sea View'], array_column($view['rooms'], 'title'), 'cheapest room first');
        $twin = $view['rooms'][1];
        self::assertSame([false, false], [$twin['on_request'], $twin['immediate']], 'one option on request: each says its own');
        self::assertSame(
            ['kind' => 'free_open', 'until' => '', 'time' => '', 'rel' => '', 'amount' => '', 'nights' => 0, 'approx' => false, 'percent' => ''],
            $twin['options'][0]['cancel'],
            '"Anulare gratuită", no day',
        );
        self::assertNull($twin['options'][1]['cancel']);
        self::assertSame(['880 €', '1.100 €', '−20%'], [$twin['options'][0]['price'], $twin['options'][0]['was'], $twin['options'][0]['off']]);
        self::assertSame([0, 1], array_column($twin['terms'], 'for'), 'each option loads its own terms');
        self::assertSame([1, 'one'], [$view['children'], $view['children_form']]);
    }

    public function testTheTermsTheCardLoadsAreSectionsOfLines(): void
    {
        $texts = [
            'payment' => 'Termeni de plată', 'cancellation' => 'Politica de anulare', 'free' => 'Anulare gratuită',
            'free_until' => 'Anulare gratuită înainte de', 'none' => 'Nu există condiții specifice.',
            'outage' => 'Momentan indisponibile.', 'unavailable' => 'Căutați din nou.',
        ];

        self::assertSame([
            ['title' => 'Termeni de plată', 'lines' => ['30% până la 01.06.2027', '70% până la 01.07.2027']],
            ['title' => 'Politica de anulare', 'lines' => ['Anulare gratuită înainte de 01.06.2027', '50% de la 01.06.2027']],
        ], DesignARates::termsSections([
            'status' => 'ok', 'is_free' => true, 'free_until' => '01.06.2027',
            'payment_terms' => ['30% până la 01.06.2027', '70% până la 01.07.2027'],
            'cancellation_fees' => ['50% de la 01.06.2027'],
        ], $texts));

        self::assertSame(
            [['title' => 'Politica de anulare', 'lines' => ['Anulare gratuită', 'Programul detaliat nu poate fi încărcat.']]],
            DesignARates::termsSections(['status' => 'partial', 'is_free' => true, 'payment_terms' => [], 'cancellation_fees' => [], 'free_until' => null, 'notice' => 'Programul detaliat nu poate fi încărcat.'], $texts),
            'verify down: the search\'s free-cancellation fact and why the rest is missing',
        );
        self::assertSame([['title' => '', 'lines' => ['Nu există condiții specifice.']]], DesignARates::termsSections(['status' => 'ok', 'payment_terms' => [], 'cancellation_fees' => []], $texts));
        self::assertSame([['title' => '', 'lines' => ['Momentan indisponibile.']]], DesignARates::termsSections(['status' => 'outage'], $texts));
        self::assertSame([['title' => '', 'lines' => ['Căutați din nou.']]], DesignARates::termsSections(['status' => 'unavailable'], $texts));
        self::assertSame([], DesignARates::termsSections(['status' => 'error', 'message' => 'x'], $texts), 'the card says it could not load them');
    }
}
