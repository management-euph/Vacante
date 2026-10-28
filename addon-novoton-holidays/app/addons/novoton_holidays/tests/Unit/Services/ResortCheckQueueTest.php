<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ResortCheckQueue;
use Tygh\Addons\NovotonHolidays\Services\ResortCheckSettings;

/**
 * What the weekly availability check asks, and in which order
 * (docs/novoton-availability-gaps-plan.md §4.3): every sold resort once a
 * week, least recently checked first, within the call budget; a hotel no
 * resort answer covers on its own; and the settings that set it.
 */
final class ResortCheckQueueTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function hotel(string $id, string $city, ?string $checked = null, string $country = 'BULGARIA'): array
    {
        return ['hotel_id' => $id, 'country' => $country, 'city' => $city, 'last_price_check' => $checked];
    }

    public function testHotelsAreGroupedByTheResortNovotonListsTrimmedAndInAnyCase(): void
    {
        $units = ResortCheckQueue::units([
            self::hotel('1', 'Sunny Beach', '2026-10-20 04:00:00'),
            self::hotel('2', ' SUNNY BEACH ', '2026-10-19 04:00:00'),
            self::hotel('3', 'Albena', '2026-10-21 04:00:00'),
        ], ['BULGARIA' => ['SUNNY BEACH', 'ALBENA']]);

        self::assertCount(2, $units);
        self::assertSame(['kind' => 'resort', 'country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'hotel_ids' => ['1', '2'], 'last_check' => '2026-10-19 04:00:00'], $units[0]);
        self::assertSame('ALBENA', $units[1]['resort'], 'the resort is asked as Novoton spells it');
    }

    public function testAHotelNoResortAnswerCoversIsAskedAlone(): void
    {
        $units = ResortCheckQueue::units([
            self::hotel('1', 'Sunny Beach', '2026-10-20 04:00:00'),
            self::hotel('7', 'Kosharitsa', '2026-10-20 04:00:00'),
            self::hotel('8', '', '2026-10-20 04:00:00'),
        ], ['BULGARIA' => ['Sunny Beach']]);

        self::assertSame(['hotel', 'hotel', 'resort'], array_column($units, 'kind'));
        self::assertSame([['7'], ['8'], ['1']], array_column($units, 'hotel_ids'));
    }

    public function testACountryWithoutItsResortListIsLeftOut(): void
    {
        $units = ResortCheckQueue::units([
            self::hotel('1', 'Sunny Beach'),
            self::hotel('9', 'Kassandra', null, 'GREECE'),
        ], ['BULGARIA' => ['Sunny Beach']]);

        self::assertSame([['1']], array_column($units, 'hotel_ids'), 'its hotels are neither resorts nor alone: they wait');
    }

    public function testNeverCheckedComesFirstThenTheOldestCheck(): void
    {
        $units = ResortCheckQueue::units([
            self::hotel('1', 'Albena', '2026-10-25 04:00:00'),
            self::hotel('2', 'Sunny Beach', '2026-10-21 04:00:00'),
            self::hotel('3', 'Sunny Beach', '2026-10-24 04:00:00'),
            self::hotel('4', 'Nessebar', '2026-10-22 04:00:00'),
            self::hotel('5', 'Nessebar', '0000-00-00 00:00:00'),
            self::hotel('6', 'Obzor', '2026-10-23 04:00:00'),
        ], ['BULGARIA' => ['Albena', 'Sunny Beach', 'Nessebar', 'Obzor']]);

        self::assertSame(['Nessebar', 'Sunny Beach', 'Obzor', 'Albena'], array_column($units, 'resort'));
        self::assertSame('', $units[0]['last_check'], 'a resort with a hotel never checked is never checked');
    }

    public function testEachRunTakesItsShareWithinTheCallBudget(): void
    {
        self::assertSame(10, ResortCheckQueue::share(70, 7, 6, 120), 'one seventh of 70 resorts');
        self::assertSame(1, ResortCheckQueue::share(3, 7, 6, 120));
        self::assertSame(20, ResortCheckQueue::share(300, 7, 6, 120), '43 would be due, 120 calls allow 20');
        self::assertSame(70, ResortCheckQueue::share(70, 1, 6, 1000), 'every resort every night');
        self::assertSame(0, ResortCheckQueue::share(0, 7, 6, 120));
        self::assertSame(0, ResortCheckQueue::share(10, 7, 6, 5), 'not even one resort fits');
    }

    public function testAHotelWhosePricesStartLaterAddsItsFirstWeekAtMostTwice(): void
    {
        $windows = [
            ['kind' => 'near', 'check_in' => '2026-11-09', 'check_out' => '2026-11-16'],
            ['kind' => 'season', 'check_in' => '2027-07-15', 'check_out' => '2027-07-22'],
        ];

        $dates = ResortCheckQueue::dates($windows, ['2027-05-01', '2026-12-20', '2027-07-15', '2026-12-20', '2027-01-10', 'junk'], 7);

        self::assertSame(['2026-11-09', '2027-07-15', '2026-12-20', '2027-01-10'], array_column($dates, 'check_in'));
        self::assertSame(['kind' => 'first_week', 'check_in' => '2026-12-20', 'check_out' => '2026-12-27'], $dates[2]);
        self::assertSame($windows, ResortCheckQueue::dates($windows, [], 7));
    }

    public function testSettingsHaveSafeDefaultsAndARunCanSetItsBudgets(): void
    {
        $defaults = ResortCheckSettings::from([]);
        self::assertSame([14, 30], $defaults->nearDays);
        self::assertSame('06-20, 07-15, 08-15, 09-05', $defaults->seasonDates);
        self::assertSame([7, 7, 120, 1200, 0], [$defaults->nights, $defaults->cycleDays, $defaults->maxCalls, $defaults->maxSeconds, $defaults->units]);
        self::assertSame(
            ['2026-11-09', '2026-11-25', '2027-06-20', '2027-07-15', '2027-08-15', '2027-09-05'],
            array_column($defaults->windows(new \DateTimeImmutable('2026-10-26 04:00:00')), 'check_in'),
        );

        $stored = ResortCheckSettings::from([
            'availability_check_near_days' => '30',
            'availability_check_season_dates' => '',
            'availability_check_cycle_days' => '14',
            'availability_check_max_calls' => '',
            'availability_check_max_minutes' => '10',
        ]);
        self::assertSame([[30], '', 14, 120, 600], [$stored->nearDays, $stored->seasonDates, $stored->cycleDays, $stored->maxCalls, $stored->maxSeconds], 'an empty number takes its default; empty dates mean none');

        $run = ResortCheckSettings::from(['availability_check_max_calls' => '50'], ['max_calls' => '400', 'max_time' => '5', 'resorts' => '3', 'nights' => '99']);
        self::assertSame([400, 300, 3, 21], [$run->maxCalls, $run->maxSeconds, $run->units, $run->nights]);
        self::assertSame(PHP_INT_MAX, ResortCheckSettings::from([], ['resorts' => 'all'])->units);
        self::assertSame([1, 60], [ResortCheckSettings::from(['availability_check_cycle_days' => '0'], ['max_time' => '-3'])->cycleDays, ResortCheckSettings::from([], ['max_time' => '-3'])->maxSeconds]);
    }

    /** Off only when the store unticks it; a run's URL decides for that run. */
    public function testDatesWithoutPublishedPricesAreLeftOutUnlessTurnedOff(): void
    {
        self::assertTrue(ResortCheckSettings::from([])->skipUnpriced, 'a store that has not saved the setting yet');
        self::assertTrue(ResortCheckSettings::from(['availability_check_skip_unpriced' => 'Y'])->skipUnpriced);
        self::assertFalse(ResortCheckSettings::from(['availability_check_skip_unpriced' => 'N'])->skipUnpriced);
        self::assertFalse(ResortCheckSettings::from(['availability_check_skip_unpriced' => 'Y'], ['skip_unpriced' => '0'])->skipUnpriced);
        self::assertTrue(ResortCheckSettings::from(['availability_check_skip_unpriced' => 'N'], ['skip_unpriced' => '1'])->skipUnpriced);

        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);
        $items = $xml->xpath('//settings//item[@id="availability_check_skip_unpriced"]') ?: [];
        self::assertCount(1, $items);
        self::assertSame(['checkbox', 'Y'], [trim((string) $items[0]->type), trim((string) $items[0]->default_value)]);
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(dirname(__DIR__, 6) . "/var/langs/{$lang}/addons/novoton_holidays.po");
            foreach (['SettingsOptions', 'SettingsTooltips'] as $kind) {
                self::assertStringContainsString("msgctxt \"{$kind}::novoton_holidays::availability_check_skip_unpriced\"", $po, "{$lang} {$kind}");
            }
        }
    }

    /** @return list<array{kind: string, check_in: string, check_out: string}> 14 days ahead, next summer, a first published week */
    private static function checkDates(): array
    {
        return [
            ['kind' => 'near', 'check_in' => '2026-11-09', 'check_out' => '2026-11-16'],
            ['kind' => 'season', 'check_in' => '2027-07-15', 'check_out' => '2027-07-22'],
            ['kind' => 'first_week', 'check_in' => '2027-05-01', 'check_out' => '2027-05-08'],
        ];
    }

    /** @return array{from: ?string, to: ?string, next: ?string} */
    private static function window(?string $from, ?string $to, ?string $next = null): array
    {
        return ['from' => $from, 'to' => $to, 'next' => $next];
    }

    /** @param array{ask: list<array{kind: string, check_in: string, check_out: string}>, skip: list<array{kind: string, check_in: string, check_out: string}>} $split */
    private static function checkIns(array $split): array
    {
        return [array_column($split['ask'], 'check_in'), array_column($split['skip'], 'check_in')];
    }

    public function testADateNoHotelHasAPublishedPriceForIsNotAsked(): void
    {
        $split = ResortCheckQueue::split(self::checkDates(), ['476', '477'], [
            476 => self::window('2027-05-01', '2027-10-15'),
            477 => self::window('2027-06-01', '2027-09-30'),
        ], '2026-10-26');

        self::assertSame([['2027-07-15', '2027-05-01'], ['2026-11-09']], self::checkIns($split));
    }

    public function testAHotelWithNoFuturePriceAnswersEveryDate(): void
    {
        $split = ResortCheckQueue::split(self::checkDates(), ['476'], [476 => self::window(null, null)], '2026-10-26');

        self::assertSame([[], ['2026-11-09', '2027-07-15', '2027-05-01']], self::checkIns($split));
    }

    /** A night inside the window is enough; check-out is not a night. */
    public function testAStayIsAskedWhenOneOfItsNightsIsPriced(): void
    {
        $dates = [
            ['kind' => 'near', 'check_in' => '2027-04-28', 'check_out' => '2027-05-05'],
            ['kind' => 'near', 'check_in' => '2027-04-24', 'check_out' => '2027-05-01'],
            ['kind' => 'near', 'check_in' => '2027-10-15', 'check_out' => '2027-10-22'],
            ['kind' => 'near', 'check_in' => '2027-10-16', 'check_out' => '2027-10-23'],
        ];

        $split = ResortCheckQueue::split($dates, ['476'], [476 => self::window('2027-05-01', '2027-10-15')], '2026-10-26');

        self::assertSame([['2027-04-28', '2027-10-15'], ['2027-04-24', '2027-10-16']], self::checkIns($split));
    }

    /** One hotel the prices cannot answer for keeps every date of its resort asked. */
    public function testAWindowThatCannotAnswerKeepsEveryDateAsked(): void
    {
        $priced = self::window('2027-05-01', '2027-10-15');
        $cases = [
            'prices never read' => [],
            'not rebuilt since its prices ended' => [477 => self::window('2026-09-01', '2026-10-15')],
            'the latest check found an offer outside it' => [477 => self::window('2027-05-01', '2027-10-15', '2026-11-09')],
            'an offer, but no future price' => [477 => self::window(null, null, '2026-11-09')],
            'only one end' => [477 => self::window('2027-05-01', null)],
        ];
        foreach ($cases as $case => $windows) {
            $split = ResortCheckQueue::split(self::checkDates(), ['476', '477'], [476 => $priced] + $windows, '2026-10-26');

            self::assertSame([['2026-11-09', '2027-07-15', '2027-05-01'], []], self::checkIns($split), $case);
        }
    }

    /** An offer inside the window agrees with it; one whose stay has begun says nothing about the prices ahead. */
    public function testAnOfferThatAgreesWithTheWindowOrHasPassedKeepsItTrusted(): void
    {
        foreach (['2027-06-10', '2026-10-20'] as $next) {
            $split = ResortCheckQueue::split(self::checkDates(), ['476'], [476 => self::window('2027-05-01', '2027-10-15', $next)], '2026-10-26');

            self::assertSame([['2027-07-15', '2027-05-01'], ['2026-11-09']], self::checkIns($split), $next);
        }
    }
}
