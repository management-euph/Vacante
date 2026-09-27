<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\BookingSidebarBuilder;
use Tygh\Addons\Eurosite\Services\BookingSubmissionService;

/**
 * The eurosite booking page renders the SAME shared travel_core sidebar as
 * sphinx and novoton — this pins what eurosite's own data turns into.
 */
final class BookingSidebarBuilderTest extends TestCase
{
    private const LINE = 'Between [from] - [to]: [value] penalty';

    /** @return array<string, mixed> */
    private static function snapshot(array $over = []): array
    {
        return $over + [
            'product_code' => 'RO0452', 'product_name' => 'Villa Ecletico CM', 'country_code' => 'RO',
            'city_code' => 'ROBCH1', 'city_name' => 'Bucharest', 'check_in' => '2026-10-05',
            'check_out' => '2026-10-11', 'price' => 1798.0, 'currency' => 'EUR',
            'availability' => 'Immediate', 'availability_code' => 'IM',
            'rooms' => [['code' => '5819', 'name' => 'Luxury Suite Apartament']],
            'meals' => [['code' => '2157', 'name' => 'BB']],
            'adults' => 2, 'children_ages' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function hotel(array $over = []): array
    {
        return $over + ['category' => 4, 'first_image' => 'https://img/1.jpg', 'product_id' => 0];
    }

    /**
     * @return array{type: string, from_date: string, to_date: string, value: float, is_percent: bool}
     */
    private static function fee(string $from, string $to, float $value, bool $percent = true): array
    {
        return ['type' => 'cancel', 'from_date' => $from, 'to_date' => $to, 'value' => $value, 'is_percent' => $percent];
    }

    public function testImmediateOfferFillsEverySidebarCard(): void
    {
        $vm = BookingSidebarBuilder::build(
            self::snapshot(),
            self::hotel(),
            [self::fee('2026-09-25', '2026-10-05', 100.0)],
            ['Avans 30% la confirmarea rezervării.'],
            'Bucharest, Romania',
            [],
            self::LINE,
            '2026-09-25',
        )->toViewArray();

        self::assertSame('Villa Ecletico CM', $vm['name']);
        self::assertSame(4, $vm['stars']);
        self::assertTrue($vm['available']);
        self::assertSame('https://img/1.jpg', $vm['image_url']);
        self::assertSame('Bucharest, Romania', $vm['location_line']);
        self::assertSame(6, $vm['nights']);
        self::assertSame(2, $vm['adults']);
        self::assertSame(0, $vm['children']);
        self::assertSame([['qty' => 1, 'name' => 'Luxury Suite Apartament']], $vm['room_lines']);
        self::assertSame('BB', $vm['board_name']);
        self::assertSame('1.798,00 €', $vm['total']);
        self::assertCount(1, $vm['cancel_lines']);
        self::assertStringContainsString('100% penalty', $vm['cancel_lines'][0]);
        // A 100% penalty is headlined with the full total.
        self::assertSame('1.798,00 €', $vm['cancel_full_amount']);
        // The penalty is already in force today: no "free until" promise.
        self::assertSame('', $vm['cancel_free_until']);
        self::assertSame(['Avans 30% la confirmarea rezervării.'], $vm['payment_lines']);
        // No linked product → no "Change your selection" link to a dead page.
        self::assertSame('', $vm['change_url']);
    }

    /** "Location - show map" like sphinx / novoton: no coordinates, so a Maps search. */
    public function testLocationLineCarriesAMapLink(): void
    {
        $vm = BookingSidebarBuilder::build(self::snapshot(), self::hotel(), [], [], 'Bucharest, Romania')->toViewArray();

        self::assertSame('Bucharest, Romania', $vm['location_line']);
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Villa Ecletico CM, Bucharest, Romania'),
            $vm['map_url'],
        );
    }

    public function testOnRequestOfferShowsTheOnRequestBadge(): void
    {
        $vm = BookingSidebarBuilder::build(self::snapshot(['availability_code' => 'OR']), self::hotel(), [], [])->toViewArray();

        self::assertFalse($vm['available']);
    }

    public function testLinkedProductGetsTheChangeSelectionLink(): void
    {
        $vm = BookingSidebarBuilder::build(
            self::snapshot(['children_ages' => [7]]),
            self::hotel(['product_id' => 55]),
            [],
            [],
        )->toViewArray();

        self::assertSame(55, $vm['product_id']);
        self::assertSame(1, $vm['children']);
        self::assertStringStartsWith('products.view?product_id=55', $vm['change_url']);
        self::assertStringContainsString('children_ages=7', $vm['change_url']);
    }

    public function testMissingHotelRowStillRendersFromTheSnapshot(): void
    {
        $vm = BookingSidebarBuilder::build(self::snapshot(), null, [], [])->toViewArray();

        self::assertSame('Bucharest', $vm['location_line']);
        self::assertSame(0, $vm['stars']);
        self::assertSame('', $vm['image_url']);
    }

    public function testCancelLinesFormatPercentAndAmount(): void
    {
        $lines = BookingSidebarBuilder::cancelLines([
            self::fee('2026-09-26', '2026-09-30', 30.0),
            self::fee('2026-10-01', '2026-10-05', 250.5, false),
        ], 'EUR', '[value]');

        self::assertSame(['30%', '250,50 €'], $lines);
    }

    public function testPartialPenaltyIsNotHeadlined(): void
    {
        $vm = BookingSidebarBuilder::build(
            self::snapshot(),
            self::hotel(),
            [self::fee('2026-09-26', '2026-10-05', 30.0)],
            [],
            '',
            [],
            self::LINE,
            '2026-09-25',
        )->toViewArray();

        self::assertSame('', $vm['cancel_full_amount']);
    }

    public function testFreeUntilIsTheDayBeforeTheFirstPenalty(): void
    {
        $fees = [self::fee('2026-10-01', '2026-10-05', 100.0), self::fee('2026-09-28', '2026-09-30', 30.0)];

        self::assertNotSame('', BookingSidebarBuilder::freeUntil($fees, '2026-09-25'));
        self::assertSame(
            BookingSidebarBuilder::freeUntil([self::fee('2026-09-28', '2026-09-30', 30.0)], '2026-09-25'),
            BookingSidebarBuilder::freeUntil($fees, '2026-09-25'),
        );
        // Penalty already running, or no schedule at all: nothing to promise.
        self::assertSame('', BookingSidebarBuilder::freeUntil($fees, '2026-09-28'));
        self::assertSame('', BookingSidebarBuilder::freeUntil([], '2026-09-25'));
    }

    public function testContactIsBackfilledFromTheOrderOnlyWhenMissing(): void
    {
        $order = ['email' => 'guest@example.com', 'phone' => '+40700000000'];

        self::assertSame(
            ['guest_email' => 'guest@example.com', 'guest_phone' => '+40700000000'],
            BookingSubmissionService::contactBackfill(['guest_email' => '', 'guest_phone' => ''], $order),
        );
        self::assertSame(
            [],
            BookingSubmissionService::contactBackfill(['guest_email' => 'kept@example.com', 'guest_phone' => '123'], $order),
        );
    }

    public function testStatusFollowsTheApiAvailabilityCode(): void
    {
        self::assertSame('instant', BookingSidebarBuilder::status('IM'));
        self::assertSame('on_request', BookingSidebarBuilder::status('OR'));
        self::assertSame('stop_sale', BookingSidebarBuilder::status('ST'));
        self::assertSame('available', BookingSidebarBuilder::status(''));

        $vm = BookingSidebarBuilder::build(self::snapshot(), self::hotel(), [], [])->toViewArray();
        self::assertSame('instant', $vm['status']);
    }

    /**
     * Live SCANDINAVIA CM, 26.09.2026: ProductPrice 299, Gross 324,
     * PriceNoRedd 381 (Gross scale) → old price 351,60 on the charged scale,
     * the offer's own text as the discount label, and the fee windows as a
     * timeline whose current step is the 80% one.
     */
    public function testOldPriceDiscountAndTimelineFromTheOffer(): void
    {
        $offer = new \Tygh\Addons\Eurosite\Dto\HotelOffer(
            'RO0451', 'SCANDINAVIA CM', 'RO', 'ROMM', 'Mamaia', 4, '', '', '0', '0', 'EUR', 'Normal', 'Immediate',
            '2026-10-05', '2026-10-11', 299.0, 324.0, 0.0, 0.0, 'v', 'Nerambursabil MD', [], [], '', 'IM', 381.0,
            'Reducere Oferta Speciala 15% pana la 31.12.2026',
        );
        self::assertSame(351.6, $offer->oldPrice());

        $vm = BookingSidebarBuilder::build(
            self::snapshot(['price' => 299.0, 'old_price' => $offer->oldPrice(), 'offer_description' => $offer->offerDescription]),
            self::hotel(),
            [self::fee('2026-09-26', '2026-09-28', 80.0), self::fee('2026-09-29', '2026-10-05', 100.0)],
            ['Avans 30% la confirmarea rezervării.'],
            '',
            [],
            self::LINE,
            '2026-09-26',
        )->toViewArray();

        self::assertSame('299,00 €', $vm['total']);
        self::assertSame('351,60 €', $vm['old_total']);
        self::assertSame('Reducere Oferta Speciala 15% pana la 31.12.2026', $vm['discount_label']);
        self::assertSame('49,83 €', $vm['per_night']);
        self::assertCount(2, $vm['cancel_steps']);
        self::assertTrue($vm['cancel_steps'][0]['is_current']);
        self::assertSame('239,20 €', $vm['cancel_steps'][0]['amount_label']);
        self::assertSame('', $vm['cancel_free_until']);
        self::assertSame(['Avans <strong>30%</strong> la confirmarea rezervării.'], $vm['payment_lines_html']);
    }

    public function testNoReductionMeansNoOldPrice(): void
    {
        $vm = BookingSidebarBuilder::build(self::snapshot(['old_price' => 0.0, 'offer_description' => 'x']), self::hotel(), [], [])->toViewArray();

        self::assertSame('', $vm['old_total']);
        self::assertSame('', $vm['discount_label']);
    }

    public function testAmountsFollowTheCartScaleAndTheShoppersCurrency(): void
    {
        $usd = new \Tygh\Addons\TravelCore\Services\MoneyFormatter(
            ['symbol' => '$', 'after' => 'N', 'decimals' => 2, 'decimals_separator' => '.', 'thousands_separator' => ','],
            static fn (float $primary): float => $primary * 2,
        );
        $vm = BookingSidebarBuilder::build(self::snapshot(), self::hotel(), [], [], '', [], self::LINE, '2026-09-25', '%d.%m.%Y', $usd, 1.0)->toViewArray();

        self::assertSame('$3,596.00', $vm['total']);
    }
}
