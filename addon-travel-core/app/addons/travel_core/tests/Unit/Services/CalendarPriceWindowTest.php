<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Services\CalendarPriceWindow;
use Tygh\Registry;

/**
 * The hotel page calendar's window (docs/novoton-availability-gaps-plan.md
 * §7.1): it opens on the first stay date with a published price and marks
 * the dates outside them. From the records only, behind the panels' switch.
 */
final class CalendarPriceWindowTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::set('addons.travel_core.availability_panels', 'Y');
    }

    protected function tearDown(): void
    {
        Registry::set('addons.travel_core.availability_panels', null);
    }

    /**
     * @param array<array-key, array{from: ?string, to: ?string, next: ?string}> $windows the records' windows by hotel id
     */
    private static function window(array $windows, AvailabilityMode $mode = AvailabilityMode::Report): CalendarPriceWindow
    {
        return new CalendarPriceWindow(
            static fn (string $provider, string $hotelId): ?array => $provider === 'novoton' ? $windows[$hotelId] ?? null : null,
            static fn (): AvailabilityMode => $mode,
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-30 09:00:00');
    }

    public function testPricesPublishedForNextSummerOpenTheCalendarThere(): void
    {
        $window = self::window(['476' => ['from' => '2027-05-01', 'to' => '2027-09-30', 'next' => null]]);

        self::assertSame(['from' => '2027-05-01', 'to' => '2027-09-30'], $window->forHotel('novoton', '476', self::today()));
    }

    public function testAWindowThatStartedBeforeTodayStartsToday(): void
    {
        $window = self::window(['476' => ['from' => '2026-10-01', 'to' => '2027-03-31', 'next' => null]]);

        self::assertSame(['from' => '2026-10-30', 'to' => '2027-03-31'], $window->forHotel('novoton', '476', self::today()));
    }

    public function testNoWindowWithoutPublishedPricesAhead(): void
    {
        $window = self::window([
            'ended' => ['from' => '2026-06-01', 'to' => '2026-09-30', 'next' => null],
            'none' => ['from' => null, 'to' => null, 'next' => null],
            'today' => ['from' => '2026-10-30', 'to' => '2026-10-30', 'next' => null],
        ]);

        self::assertNull($window->forHotel('novoton', 'ended', self::today()), 'last season ended: the panel says so');
        self::assertNull($window->forHotel('novoton', 'none', self::today()));
        self::assertNull($window->forHotel('novoton', 'unknown', self::today()), 'prices never read');
        self::assertNull($window->forHotel('eurosite', 'today', self::today()), 'a supplier without recorded windows');
        self::assertSame(['from' => '2026-10-30', 'to' => '2026-10-30'], $window->forHotel('novoton', 'today', self::today()));
    }

    public function testOnlyWhileThePanelsAreOnAndTheSupplierIsNotOff(): void
    {
        $windows = ['476' => ['from' => '2027-05-01', 'to' => '2027-09-30', 'next' => null]];

        self::assertNull(self::window($windows, AvailabilityMode::Off)->forHotel('novoton', '476', self::today()));
        Registry::set('addons.travel_core.availability_panels', 'N');
        self::assertNull(self::window($windows)->forHotel('novoton', '476', self::today()));
    }

    public function testAFailingReadLeavesTheCalendarAsItWas(): void
    {
        $window = new CalendarPriceWindow(static function (): never {
            throw new \RuntimeException('table missing');
        }, static fn (): AvailabilityMode => AvailabilityMode::Report);

        self::assertNull($window->forHotel('novoton', '476', self::today()));
    }
}
