<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;

/**
 * The booking page's struck-through "was" price for novoton follows the
 * search card (search.tpl) exactly: extras promotion vs the standard row,
 * otherwise early booking — never a figure the search card did not show.
 */
final class NovotonBookingSidebarDiscountTest extends TestCase
{
    private const BOOK_PAY = 'Book [book] nights, pay for [pay]';
    private const EARLY = 'Early Booking';

    /** @return array{old: float, label: string} */
    private static function discount(float $charged, float $standard, string $extras, float $early): array
    {
        return NovotonBookingSidebarBuilder::discount($charged, $standard, $extras, $early, self::BOOK_PAY, self::EARLY);
    }

    public function testExtrasPromotionStrikesTheStandardPrice(): void
    {
        self::assertSame(
            ['old' => 1260.0, 'label' => 'Book 7 nights, pay for 6'],
            self::discount(1080.0, 1260.0, '7 = 6', 10.0),
        );
    }

    /** A label without "N = M" is shown as the operator wrote it. */
    public function testExtrasLabelWithoutTheEqualsSignIsKeptAsIs(): void
    {
        self::assertSame(['old' => 1260.0, 'label' => 'Free night'], self::discount(1080.0, 1260.0, 'Free night', 0.0));
    }

    public function testEarlyBookingGrossesTheChargedPriceUp(): void
    {
        // 900 / (1 - 10 %) = 1000, as search.tpl's {math} does
        self::assertSame(['old' => 1000.0, 'label' => '-10% Early Booking'], self::discount(900.0, 900.0, '', 10.0));
    }

    /** An extras row with no standard sibling: the search card falls back to early booking too. */
    public function testExtrasWithoutAStandardRowFallsBackToEarlyBooking(): void
    {
        self::assertSame(['old' => 1000.0, 'label' => '-10% Early Booking'], self::discount(900.0, 0.0, '7 = 6', 10.0));
    }

    public function testNoOfferMeansNoWasPrice(): void
    {
        $none = ['old' => 0.0, 'label' => ''];
        self::assertSame($none, self::discount(900.0, 900.0, '', 0.0));
        self::assertSame($none, self::discount(900.0, 900.0, '', 100.0), 'a 100 % reduction would divide by zero');
        self::assertSame($none, self::discount(0.0, 900.0, '7 = 6', 10.0));
        // A "promotion" that is not cheaper is no saving.
        self::assertSame($none, self::discount(900.0, 900.0, '7 = 6', 0.0));
    }

    public function testSeveralRoomsSumEachRoomsOwnOffer(): void
    {
        self::assertSame(
            ['old' => 1700.0, 'label' => 'Book 7 nights, pay for 6 · -10% Early Booking'],
            NovotonBookingSidebarBuilder::combinedDiscount([
                ['price' => 600.0, 'old' => 700.0, 'label' => 'Book 7 nights, pay for 6'],
                ['price' => 900.0, 'old' => 1000.0, 'label' => '-10% Early Booking'],
            ]),
        );
        // A room without an offer counts at its own price.
        self::assertSame(
            ['old' => 1600.0, 'label' => '-10% Early Booking'],
            NovotonBookingSidebarBuilder::combinedDiscount([
                ['price' => 600.0, 'old' => 0.0, 'label' => ''],
                ['price' => 900.0, 'old' => 1000.0, 'label' => '-10% Early Booking'],
            ]),
        );
        self::assertSame(
            ['old' => 0.0, 'label' => ''],
            NovotonBookingSidebarBuilder::combinedDiscount([['price' => 600.0, 'old' => 0.0, 'label' => '']]),
        );
    }
}
