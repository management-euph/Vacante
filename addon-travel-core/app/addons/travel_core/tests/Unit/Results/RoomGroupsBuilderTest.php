<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Results;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomGroupsBuilder;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * Several rooms on the Design A hotel page (boards 13c): the rooms searched
 * grouped by their guests, one tab per group, each group's room cards priced
 * for its guests; what a booking of several rooms posts for a room travels
 * with each option, and every room type keeps one key across the groups.
 */
#[CoversClass(RoomGroupsBuilder::class)]
final class RoomGroupsBuilderTest extends TestCase
{
    private static function builder(): RoomGroupsBuilder
    {
        return new RoomGroupsBuilder(new RoomResultsBuilder(
            new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']),
            static fn (string $iso): string => date('d.m.Y', (int) strtotime($iso)),
        ));
    }

    /**
     * Admiral's rates for some guests: a Deluxe double at breakfast and at
     * ultra all inclusive, priced for the guests asked.
     *
     * @param list<int> $ages
     * @return list<RateOffer>
     */
    private static function rates(int $adults, array $ages, int $left = 10): array
    {
        $per = 300 * $adults + 90 * count($ages);
        $rates = [];
        foreach ([['Mic dejun', RateOffer::MEAL_BREAKFAST, 1.0, 'BB'], ['Ultra All Inclusive', RateOffer::MEAL_ULTRA_ALL_INCLUSIVE, 1.4, 'UAI']] as [$meal, $rank, $factor, $board]) {
            $rates[] = new RateOffer(
                roomKey: 'DBL 2+1 DELUXE',
                roomName: 'Cameră Dublă',
                mealPlan: $meal,
                price: round($per * $factor),
                bookUrl: '',
                roomTitle: 'Cameră Dublă Deluxe',
                mealRank: $rank,
                roomsLeft: $left,
                roomCode: 'DBL 2+1 DELUXE',
                capacity: 3,
                roomData: ['room_id' => 'DBL 2+1 DELUXE', 'board_id' => $board, 'price' => round($per * $factor)],
            );
        }

        return $rates;
    }

    /** @return \Closure(int, list<int>): list<RateOffer> */
    private static function asked(array &$calls): \Closure
    {
        return static function (int $adults, array $ages) use (&$calls): array {
            $calls[] = [$adults, $ages];

            return self::rates($adults, $ages);
        };
    }

    public function testTwelveRoomsAreThreeGroupsByTheirGuests(): void
    {
        $searched = array_merge(
            array_fill(0, 8, ['adults' => 2, 'children' => []]),
            array_fill(0, 2, ['adults' => 2, 'children' => [7]]),
            array_fill(0, 2, ['adults' => 1, 'children' => []]),
        );
        $calls = [];

        $view = self::builder()->build($searched, self::asked($calls), 6);

        self::assertSame(12, $view['room_count']);
        self::assertSame(3, $view['group_count']);
        self::assertFalse($view['by_room']);
        self::assertSame([[2, []], [2, [7]], [1, []]], $calls, 'one search answer per group, not per room');
        self::assertSame(['g1', 'g2', 'g3'], array_column($view['groups'], 'key'));
        self::assertSame([8, 2, 2], array_column($view['groups'], 'size'));
        self::assertSame(['1–8', '9–10', '11–12'], array_column($view['groups'], 'numbers'));
        self::assertSame([[], [7], []], array_column($view['groups'], 'ages'));
        self::assertSame('7', $view['groups'][1]['ages_text']);
    }

    public function testEachGroupIsPricedForItsOwnGuests(): void
    {
        $calls = [];
        $view = self::builder()->build([['adults' => 2, 'children' => []], ['adults' => 2, 'children' => [7]]], self::asked($calls), 5);

        $breakfast = static fn (array $group): string => $group['results']['rooms'][0]['options'][0]['price'];
        self::assertSame('600 €', $breakfast($view['groups'][0]));
        self::assertSame('690 €', $breakfast($view['groups'][1]));
        self::assertSame(1, $view['groups'][1]['results']['children'], 'the footnote and the till name the group\'s guests');
    }

    public function testRoomsWithDifferentGuestsAreOneTabEach(): void
    {
        $calls = [];
        $view = self::builder()->build([['adults' => 2, 'children' => []], ['adults' => 2, 'children' => [7]]], self::asked($calls), 6);

        self::assertTrue($view['by_room']);
        self::assertSame([[1], [2]], array_column($view['groups'], 'rooms'));
        self::assertSame(['one', 'one'], array_column($view['groups'], 'size_form'));
    }

    public function testTheSameGuestsMakeOneGroupWhereverTheyWereSearched(): void
    {
        $calls = [];
        $view = self::builder()->build([
            ['adults' => 2, 'children' => [9, 4]],
            ['adults' => 1, 'children' => []],
            ['adults' => 2, 'children' => [4, 9]],
        ], self::asked($calls), 6);

        self::assertSame([[1, 3], [2]], array_column($view['groups'], 'rooms'));
        self::assertSame('1, 3', $view['groups'][0]['numbers']);
        self::assertSame('4, 9', $view['groups'][0]['ages_text'], 'the ages sorted, so 4 and 9 match 9 and 4');
        self::assertSame([[2, [4, 9]], [1, []]], $calls);
    }

    public function testWhatABookingPostsAndTheRoomTypesKeyTravelWithTheOptions(): void
    {
        $calls = [];
        $view = self::builder()->build([['adults' => 2, 'children' => []], ['adults' => 2, 'children' => [7]]], self::asked($calls), 6);

        $first = $view['groups'][0]['results']['rooms'][0];
        $second = $view['groups'][1]['results']['rooms'][0];
        self::assertSame($first['quota'], $second['quota'], 'one room type, one key in every group');
        self::assertSame('{"room_id":"DBL 2+1 DELUXE","board_id":"BB","price":600}', $first['options'][0]['room_json']);
        self::assertSame('UAI', json_decode($second['options'][1]['room_json'], true)['board_id']);
    }

    public function testRoomNumbersReadAsRuns(): void
    {
        self::assertSame('1–8', RoomGroupsBuilder::numbers([1, 2, 3, 4, 5, 6, 7, 8]));
        self::assertSame('9–10', RoomGroupsBuilder::numbers([9, 10]));
        self::assertSame('1–3, 5, 7–8', RoomGroupsBuilder::numbers([1, 2, 3, 5, 7, 8]));
        self::assertSame('4', RoomGroupsBuilder::numbers([4]));
        self::assertSame('', RoomGroupsBuilder::numbers([]));
    }

    public function testATillCountsTheGroupsRoomsUpToTheRoomsLeft(): void
    {
        $eight = array_fill(0, 8, ['adults' => 2, 'children' => []]);
        $plenty = self::builder()->build($eight, static fn (int $a, array $c): array => self::rates($a, $c), 6);
        $short = self::builder()->build($eight, static fn (int $a, array $c): array => self::rates($a, $c, 6), 6);

        $room = $plenty['groups'][0]['results']['rooms'][0];
        self::assertSame(8, $room['qty']);
        self::assertSame('600 €', $room['options'][0]['price'], 'the options keep one room\'s price, for comparing');
        self::assertSame('4.800 €', $room['options'][0]['qty_price'], 'the till: 8 × 600 €');
        self::assertSame(600.0, $room['options'][0]['unit']);
        self::assertSame(6, $short['groups'][0]['results']['rooms'][0]['qty'], 'only 6 left: the till counts 6 of the 8');
        self::assertSame('3.600 €', $short['groups'][0]['results']['rooms'][0]['options'][0]['qty_price']);
    }

    public function testCardsAreKeyedPerGroupAndTheMoneyFormatTravels(): void
    {
        $calls = [];
        $view = self::builder()->build([['adults' => 2, 'children' => []], ['adults' => 2, 'children' => [7]]], self::asked($calls), 6);

        $first = $view['groups'][0]['results']['rooms'][0];
        $second = $view['groups'][1]['results']['rooms'][0];
        self::assertStringStartsWith('g1-room-', $first['key']);
        self::assertStringStartsWith('g2-room-', $second['key']);
        self::assertSame(1, $first['qty'], 'a group of one room books one');
        self::assertSame(['symbol' => '€', 'after' => true, 'decimals' => 0, 'decimal' => ',', 'thousands' => '.'], $view['money']);
    }

    public function testAOneRoomSearchNeedsNoBookingData(): void
    {
        $rate = new RateOffer(roomKey: 'X', roomName: 'Cameră', mealPlan: 'Mic dejun', price: 100.0, bookUrl: 'book');
        $view = (new RoomResultsBuilder(
            new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0]),
            static fn (string $iso): string => $iso,
        ))->build([$rate], 2);

        self::assertSame('', $view['rooms'][0]['options'][0]['room_json']);
    }
}
