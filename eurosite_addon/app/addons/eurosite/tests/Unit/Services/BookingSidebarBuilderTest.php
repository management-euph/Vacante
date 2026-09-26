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
}
