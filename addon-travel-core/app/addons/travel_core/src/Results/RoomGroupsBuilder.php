<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Results;

/**
 * Design A for several rooms (the owner's boards 13c, phase 5 of
 * docs/hotel-page-design-a-plan.md): the rooms searched are grouped by their
 * guests, one tab per group, and each group shows the room cards of
 * RoomResultsBuilder priced for its guests.
 *
 *  - Rooms with the same guests (the same adults, the same children's ages)
 *    are one group, in the order their first room was searched: "8 camere ·
 *    2 adulți" (Camerele 1–8). A group of one room is that room: "Camera 2 ·
 *    2 adulți + 1 copil". Two or three rooms with different guests are
 *    therefore one tab per room, as on board 13c.1.
 *  - One choice covers the group: a card's till counts the rooms (`qty`,
 *    at most the group's and at most the rooms left of that type; the page
 *    lowers it as other groups take rooms of the same type: `quota`), its
 *    total is for that count and its button names how many it covers.
 *  - Room cards are keyed per group (`g2-room-…`): the same room type is a
 *    card in several groups, each with its own radios and till.
 *
 * @phpstan-import-type ResultsView from RoomResultsBuilder
 * @phpstan-type GroupView array{
 *     key: string, index: int, rooms: list<int>, rooms_csv: string, numbers: string, size: int, size_form: string,
 *     adults: int, adults_form: string, ages: list<int>, ages_text: string, ages_form: string,
 *     children: int, children_form: string, results: ResultsView
 * }
 * @phpstan-type GroupsView array{
 *     room_count: int, room_form: string, group_count: int, by_room: bool,
 *     nights: int, nights_form: string, groups: list<GroupView>,
 *     money: array{symbol: string, after: bool, decimals: int, decimal: string, thousands: string}
 * }
 */
final class RoomGroupsBuilder
{
    public function __construct(private readonly RoomResultsBuilder $rooms)
    {
    }

    /**
     * @param list<array{adults: int, children: list<int>}> $searched the rooms searched, in order (Camera 1, 2, …)
     * @param \Closure(int, list<int>): list<RateOffer> $ratesFor the rates priced for these guests: adults, the children's ages
     * @return GroupsView
     */
    public function build(array $searched, \Closure $ratesFor, int $nights): array
    {
        $groups = [];
        foreach ($searched as $i => $room) {
            $ages = $room['children'];
            sort($ages);
            $adults = max(1, $room['adults']);
            $signature = $adults . '|' . implode(',', $ages);
            $groups[$signature] ??= ['adults' => $adults, 'ages' => $ages, 'rooms' => []];
            $groups[$signature]['rooms'][] = $i + 1;
        }

        $views = [];
        foreach (array_values($groups) as $index => $group) {
            $size = count($group['rooms']);
            $children = count($group['ages']);
            $views[] = [
                'key' => 'g' . ($index + 1),
                'index' => $index,
                'rooms' => $group['rooms'],
                'rooms_csv' => implode(',', $group['rooms']),
                'numbers' => self::numbers($group['rooms']),
                'size' => $size,
                'size_form' => RoomResultsBuilder::form($size),
                'adults' => $group['adults'],
                'adults_form' => RoomResultsBuilder::form($group['adults']),
                'ages' => $group['ages'],
                'ages_text' => implode(', ', $group['ages']),
                'ages_form' => RoomResultsBuilder::form($group['ages'] === [] ? 0 : (int) max($group['ages'])),
                'children' => $children,
                'children_form' => RoomResultsBuilder::form($children),
                'results' => $this->counted(
                    'g' . ($index + 1),
                    $size,
                    $this->rooms->build($ratesFor($group['adults'], $group['ages']), $nights, $group['adults'], $children),
                ),
            ];
        }
        $roomCount = count($searched);

        return [
            'room_count' => $roomCount,
            'room_form' => RoomResultsBuilder::form($roomCount),
            'group_count' => count($views),
            'by_room' => count($views) === $roomCount,
            'nights' => $nights,
            'nights_form' => RoomResultsBuilder::form($nights),
            'groups' => $views,
            'money' => $this->rooms->money()->scriptFormat(),
        ];
    }

    /**
     * A group's cards: keyed for the group, each till counting the group's
     * rooms (at most the rooms left of the type) with its totals for them.
     *
     * @param ResultsView $results
     * @return ResultsView
     */
    private function counted(string $group, int $size, array $results): array
    {
        $money = $this->rooms->money();
        foreach ($results['rooms'] as $r => $room) {
            $qty = $room['left'] > 0 ? min($size, $room['left']) : $size;
            $results['rooms'][$r]['key'] = $group . '-' . $room['key'];
            $results['rooms'][$r]['qty'] = $qty;
            $results['rooms'][$r]['qty_form'] = RoomResultsBuilder::form($qty);
            foreach ($room['options'] as $o => $option) {
                $results['rooms'][$r]['options'][$o]['qty_price'] = $money->formatDisplay($option['unit'] * $qty);
                $results['rooms'][$r]['options'][$o]['qty_was'] = $option['unit_was'] > 0 ? $money->formatDisplay($option['unit_was'] * $qty) : '';
            }
        }

        return $results;
    }

    /**
     * The rooms of a group as the guest reads them: runs as ranges
     * ("1–8"), the rest listed ("1, 3").
     *
     * @param list<int> $rooms ascending
     */
    public static function numbers(array $rooms): string
    {
        $parts = [];
        $start = null;
        $prev = null;
        foreach ($rooms as $room) {
            if ($prev !== null && $room === $prev + 1) {
                $prev = $room;
                continue;
            }
            if ($start !== null) {
                $parts[] = self::run($start, (int) $prev);
            }
            $start = $prev = $room;
        }
        if ($start !== null) {
            $parts[] = self::run($start, (int) $prev);
        }

        return implode(', ', $parts);
    }

    private static function run(int $from, int $to): string
    {
        return $from === $to ? (string) $from : $from . '–' . $to;
    }
}
