<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;

/**
 * A package's parts in the shared booking summary: its journeys, in order,
 * with day, times, airports and flight number; what its price includes; and
 * the bare header when the store has no row for its hotel.
 */
final class SphinxTripSummaryTest extends TestCase
{
    private const WORDS = ['out' => 'Dus', 'back' => 'Retur', 'bus' => 'Transport cu autocarul'];

    public function testFlightsGoOutThenBackWithTheirDayTimesAndNumber(): void
    {
        $flight = [
            'inbound' => [['departure' => ['name' => 'Heraklion (HER)', 'datetime' => '2027-07-24 18:30'], 'arrival' => ['name' => 'București (OTP)', 'datetime' => '2027-07-24 20:25'], 'airline' => ['name' => 'TAROM'], 'flight_number' => 'RO 5332']],
            'outbound' => [['departure' => ['name' => 'București (OTP)', 'datetime' => '2027-07-17 15:40'], 'arrival' => ['name' => 'Heraklion (HER)', 'datetime' => '2027-07-17 17:35'], 'airline' => ['name' => 'TAROM'], 'flight_number' => 'RO 5331']],
        ];

        $legs = SphinxBookingSidebarBuilder::tripLegs($flight, [], self::WORDS, static fn (int $ts): string => date('d.m.Y', $ts));

        self::assertSame([
            ['title' => '✈ Dus · 17.07.2027', 'line' => '15:40 București (OTP) → 17:35 Heraklion (HER)', 'note' => 'TAROM RO 5331'],
            ['title' => '✈ Retur · 24.07.2027', 'line' => '18:30 Heraklion (HER) → 20:25 București (OTP)', 'note' => 'TAROM RO 5332'],
        ], $legs);
    }

    public function testASegmentWithoutTimesOrNumberStillReads(): void
    {
        $flight = ['outbound' => [['departure' => ['name' => 'OTP'], 'arrival' => ['name' => 'HER']]]];
        $bus = [['departure' => ['name' => 'București'], 'arrival' => ['name' => 'Sofia']], ['departure' => [], 'arrival' => []]];

        $legs = SphinxBookingSidebarBuilder::tripLegs($flight, $bus, self::WORDS, static fn (int $ts): string => 'never');

        self::assertSame([
            ['title' => '✈ Dus', 'line' => 'OTP → HER', 'note' => ''],
            ['title' => 'Transport cu autocarul', 'line' => 'București → Sofia', 'note' => ''],
        ], $legs, 'a bus segment with no stops is left out');
        self::assertSame([], SphinxBookingSidebarBuilder::tripLegs(null, [], self::WORDS, static fn (int $ts): string => ''));
    }

    public function testIncludesTheOffersListThenEachTransferItDoesNotNameYet(): void
    {
        $includes = SphinxBookingSidebarBuilder::tripIncludes(
            ['Zbor dus-întors', ' Taxe de aeroport ', 'Zbor dus-întors', ''],
            [['title' => 'Taxe de aeroport'], ['title' => 'Transfer aeroport – hotel – aeroport'], ['title' => '']],
        );

        self::assertSame(['Zbor dus-întors', 'Taxe de aeroport', 'Transfer aeroport – hotel – aeroport'], $includes);
    }

    public function testNoHotelRowGivesTheBareHeader(): void
    {
        $header = SphinxBookingSidebarBuilder::headerFromRow('GR0095', 'Hotel Creta', null, 9);

        self::assertSame('Hotel Creta', $header->name);
        self::assertSame(0, $header->stars);
    }
}
