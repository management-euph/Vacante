<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\CorrectedRoomPrices;

/**
 * A checkout correction written into the booking's rooms (C2/H4,
 * novoton-money-9): each room its own live price, a one-room line the line's.
 */
#[CoversClass(CorrectedRoomPrices::class)]
final class CorrectedRoomPricesTest extends TestCase
{
    public function testEachRoomTakesItsOwnLivePrice(): void
    {
        $rooms = CorrectedRoomPrices::apply(self::rooms(2), [
            'api_price' => 1470.0,
            'room_prices' => [['price' => 795.0, 'price_raw' => 722.73], ['price' => 675.0, 'price_raw' => 613.63]],
        ]);

        self::assertNotNull($rooms);
        self::assertSame([795.0, 675.0], array_column($rooms, 'price'));
        self::assertSame([722.73, 613.63], array_column($rooms, 'price_raw'));
        self::assertSame(['DBL', 'FAM'], array_column($rooms, 'room_id'));
    }

    public function testOneRoomTakesTheLinePriceAndJsonIsRead(): void
    {
        $rooms = CorrectedRoomPrices::apply((string) json_encode(self::rooms(1)), ['api_price' => 795.0, 'api_price_raw' => 722.73]);

        self::assertNotNull($rooms);
        self::assertSame(795.0, $rooms[0]['price']);
        self::assertSame(722.73, $rooms[0]['price_raw']);
    }

    public function testAMissingRawPriceKeepsTheRoomsOwn(): void
    {
        $rooms = CorrectedRoomPrices::apply(self::rooms(1), ['api_price' => 795.0]);

        self::assertNotNull($rooms);
        self::assertSame(795.0, $rooms[0]['price']);
        self::assertSame(636.36, $rooms[0]['price_raw']);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>}>
     */
    public static function unusable(): iterable
    {
        yield 'no rooms' => [null, ['api_price' => 795.0]];
        yield 'an empty list' => [[], ['api_price' => 795.0]];
        yield 'not JSON' => ['{oops', ['api_price' => 795.0]];
        yield 'a room that is not an object' => [[1], ['api_price' => 795.0]];
        yield 'multi-room without room prices' => [self::rooms(2), ['api_price' => 1470.0]];
        yield 'room prices for other rooms' => [self::rooms(2), ['api_price' => 795.0, 'room_prices' => [['price' => 795.0]]]];
        yield 'a room without a price' => [self::rooms(2), ['room_prices' => [['price' => 795.0], ['price' => 0]]]];
        yield 'one room without a price' => [self::rooms(1), ['api_price' => 0]];
    }

    /**
     * @param array<string, mixed> $correction
     */
    #[DataProvider('unusable')]
    public function testPricesThatDoNotFitTheRoomsWriteNothing(mixed $roomsData, array $correction): void
    {
        self::assertNull(CorrectedRoomPrices::apply($roomsData, $correction));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rooms(int $count): array
    {
        return array_slice([
            ['room_id' => 'DBL', 'board_id' => 'BB', 'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 700.0, 'price_raw' => 636.36],
            ['room_id' => 'FAM', 'board_id' => 'BB', 'adults' => 2, 'children' => 1, 'childrenAges' => [7], 'price' => 95.0, 'price_raw' => 86.36],
        ], 0, $count);
    }
}
