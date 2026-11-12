<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\StoredHotelBoards;

/**
 * The boards of a stored hotelinfo: the sync keeps Novoton's element name
 * (`board`), which the product feature assignment never found under `boards`.
 */
final class StoredHotelBoardsTest extends TestCase
{
    public function testReadsTheBoardKeyTheSyncStores(): void
    {
        // Hotel 4516, as stored.
        $stored = json_decode(
            '{"rooms":[{"IdRoom":"SGL PARTIAL SEA"}],"board":[{"IdBoard":"BB"},{"IdBoard":"HB"},{"IdBoard":"ALL INCL"}]}',
            true,
        );
        self::assertIsArray($stored);

        self::assertSame(
            [['IdBoard' => 'BB'], ['IdBoard' => 'HB'], ['IdBoard' => 'ALL INCL']],
            StoredHotelBoards::fromHotelInfo($stored),
        );
    }

    public function testASingleBoardIsAListOfOne(): void
    {
        // json_encode of one <board> element is an object, not a list.
        $stored = json_decode('{"board":{"IdBoard":"BB"}}', true);
        self::assertIsArray($stored);

        self::assertSame([['IdBoard' => 'BB']], StoredHotelBoards::fromHotelInfo($stored));
    }

    public function testAcceptsThePluralKeyAndPlainCodes(): void
    {
        self::assertSame([['IdBoard' => 'HB']], StoredHotelBoards::fromHotelInfo(['boards' => ['IdBoard' => 'HB']]));
        self::assertSame(['BB', 'HB'], StoredHotelBoards::fromHotelInfo(['board' => ['BB', 'HB']]));
    }

    public function testNoBoardsIsEmpty(): void
    {
        self::assertSame([], StoredHotelBoards::fromHotelInfo([]));
        self::assertSame([], StoredHotelBoards::fromHotelInfo(['board' => []]));
        self::assertSame([], StoredHotelBoards::fromHotelInfo(['board' => '']));
    }
}
