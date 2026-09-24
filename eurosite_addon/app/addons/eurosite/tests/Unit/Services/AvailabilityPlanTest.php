<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\Eurosite\Services\AvailabilityPlan;

/**
 * Which stays the availability check asks about, and how it reads the answers.
 */
final class AvailabilityPlanTest extends TestCase
{
    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-24');
    }

    private static function offer(string $code, float $price, int $stars = 0, string $image = '', string $product = 'RO0363'): HotelOffer
    {
        return new HotelOffer(
            productCode: $product,
            productName: 'Parc CM',
            countryCode: 'RO',
            cityCode: 'RO0101',
            cityName: 'Mamaia',
            category: $stars,
            class: 'Hotel',
            firstImage: $image,
            latitude: '',
            longitude: '',
            currency: 'EUR',
            offerType: 'CAZARE',
            availability: '',
            checkIn: '',
            checkOut: '',
            price: $price,
            gross: $price * 1.1,
            net: $price,
            commission: 0.0,
            variantId: 'v',
            grila: '',
            availabilityCode: $code,
        );
    }

    public function testNearDaysAreParsedSortedAndBounded(): void
    {
        self::assertSame([14, 30, 60], AvailabilityPlan::parseDays('60, 14,30'));
        self::assertSame([7, 30], AvailabilityPlan::parseDays('30;7 7 x -3 0 400'));
        self::assertSame([], AvailabilityPlan::parseDays(''));
    }

    /** "07-15" means the next 15 July; a full date only while it is ahead. */
    public function testSeasonDatesResolveToTheirNextOccurrence(): void
    {
        self::assertSame(
            ['2027-07-15', '2027-08-15'],
            AvailabilityPlan::parseSeasonDates('07-15, 08-15', self::today()),
            'both have passed this year, so next year',
        );
        self::assertSame(['2026-12-20', '2027-07-15'], AvailabilityPlan::parseSeasonDates('12-20 2027-07-15', self::today()));
        self::assertSame([], AvailabilityPlan::parseSeasonDates('2026-07-15, 2026-09-24', self::today()), 'past and today are dropped');
        self::assertSame([], AvailabilityPlan::parseSeasonDates('13-01, 02-30, July, 2027-02-30', self::today()), 'not dates');
        self::assertSame(['2028-02-29'], AvailabilityPlan::parseSeasonDates('2028-02-29', self::today()));
    }

    public function testWindowsAreNearFirstThenSeasonEachWithItsStayLength(): void
    {
        $w = AvailabilityPlan::windows(self::today(), [14, 30], '07-15', 7);

        self::assertSame([
            ['kind' => 'near', 'check_in' => '2026-10-08', 'check_out' => '2026-10-15'],
            ['kind' => 'near', 'check_in' => '2026-10-24', 'check_out' => '2026-10-31'],
            ['kind' => 'season', 'check_in' => '2027-07-15', 'check_out' => '2027-07-22'],
        ], $w);
    }

    public function testASeasonDateOnANearDateIsCheckedOnce(): void
    {
        $w = AvailabilityPlan::windows(self::today(), [14], '10-08', 3);

        self::assertCount(1, $w);
        self::assertSame('near', $w[0]['kind']);
        self::assertSame('2026-10-11', $w[0]['check_out']);
    }

    public function testTheBetterAvailabilityWinsWhateverThePrice(): void
    {
        $near = ['kind' => 'near', 'check_in' => '2026-10-08', 'check_out' => '2026-10-15'];
        $season = ['kind' => 'season', 'check_in' => '2027-07-15', 'check_out' => '2027-07-22'];

        $b = AvailabilityPlan::merge(null, self::offer('OR', 300), $near);
        $b = AvailabilityPlan::merge($b, self::offer('IM', 900), $season);
        $b = AvailabilityPlan::merge($b, self::offer('ST', 100), $near);

        self::assertSame('IM', $b['availability']);
        self::assertSame(900.0, $b['min_price']);
        self::assertSame('season', $b['window'], 'the list says which date proved it');
        self::assertSame('2027-07-15', $b['check_in']);
    }

    public function testAtTheSameAvailabilityTheCheapestStayWins(): void
    {
        $w = ['kind' => 'near', 'check_in' => '2026-10-08', 'check_out' => '2026-10-15'];
        $b = AvailabilityPlan::merge(null, self::offer('IM', 500), $w);
        $b = AvailabilityPlan::merge($b, self::offer('IM', 461), $w);
        $b = AvailabilityPlan::merge($b, self::offer('IM', 0), $w);
        $b = AvailabilityPlan::merge($b, self::offer('IM', 480), $w);

        self::assertSame(461.0, $b['min_price'], 'a zero price never counts as cheapest');
    }

    public function testStarsAndCoverImageAreKeptFromWhicheverAnswerHadThem(): void
    {
        $w = ['kind' => 'near', 'check_in' => '2026-10-08', 'check_out' => '2026-10-15'];
        $b = AvailabilityPlan::merge(null, self::offer('OR', 300, 4, 'http://img/a.jpg'), $w);
        $b = AvailabilityPlan::merge($b, self::offer('IM', 400), $w);

        self::assertSame('IM', $b['availability']);
        self::assertSame(4, $b['category']);
        self::assertSame('http://img/a.jpg', $b['first_image']);
    }

    public function testAnOfferWithoutACodeIsNotCountedAsImmediate(): void
    {
        $b = AvailabilityPlan::merge(null, self::offer('', 300), ['kind' => 'near', 'check_in' => 'x', 'check_out' => 'y']);

        self::assertSame('OR', $b['availability']);
        self::assertFalse(AvailabilityPlan::isAvailable($b['availability']));
        self::assertTrue(AvailabilityPlan::isAvailable('IM'));
    }

    public function testTheCheckedRoomIsADoubleForTwoAdults(): void
    {
        self::assertSame(['code' => 'DB', 'adults' => 2, 'children' => []], AvailabilityPlan::ROOM);
    }
}
